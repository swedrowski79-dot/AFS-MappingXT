<?php

class AFS_Evo_ImageSync extends AFS_Evo_Base
{
    public function import(): array
    {
        $names = $this->normalizeStrings(is_array($this->afs->Bilder) ? $this->afs->Bilder : []);
        if ($names === []) {
            return [];
        }

        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare('INSERT OR IGNORE INTO Bilder (Bildname) VALUES (:name)');
            foreach ($names as $name) {
                $ins->execute([':name' => $name]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->fetchIdMap('Bilder', 'ID', 'Bildname', $names);
    }

    public function copy(string $sourceDir, ?string $destDir = null): array
    {
        $baseNames = $this->normalizeFilenames(is_array($this->afs->Bilder) ? $this->afs->Bilder : []);
        $root      = dirname(__DIR__);
        $dest      = $destDir ?: ($root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'Bilder');

        $this->ensureDirectory($dest);

        $result = [
            'copied'  => [],
            'missing' => [],
            'failed'  => [],
            'total_unique' => 0,
        ];

        if ($baseNames === []) {
            return $result;
        }

        if ($sourceDir === null || !is_dir($sourceDir)) {
            $this->logWarning('Bildkopie übersprungen – Quellverzeichnis existiert nicht', ['source' => $sourceDir], 'bilder');
            return $result;
        }

        $fileIndex = $this->buildFileIndex($sourceDir);
        $upsert = $this->db->prepare(
            'INSERT INTO Bilder (Bildname, md5, "update", uploaded)
             VALUES (:name, :md5, 1, 0)
             ON CONFLICT(Bildname) DO UPDATE SET
                md5 = excluded.md5,
                "update" = 1,
                uploaded = 0
             WHERE IFNULL(Bilder.md5, \'\') <> IFNULL(excluded.md5, \'\')'
        );

        $tracker = $this->tracker();
        $total = count($baseNames);
        $processed = 0;
        $updateProgress = function () use (&$processed, $total, $tracker) {
            if (!$tracker || $total === 0) {
                return;
            }
            if ($processed === 0 || $processed % self::PROGRESS_UPDATE_INTERVAL === 0 || $processed === $total) {
                $tracker->advance('bilder_kopieren', [
                    'message' => sprintf('Kopiere Bilder (%d / %d)', $processed, $total),
                    'processed' => $processed,
                    'total' => $total,
                ]);
            }
        };

        if ($tracker && $total > 0) {
            $tracker->advance('bilder_kopieren', [
                'message' => sprintf('Kopiere Bilder (0 / %d)', $total),
                'processed' => 0,
                'total' => $total,
            ]);
        }

        foreach ($baseNames as $base) {
            $processed++;
            $meta = $fileIndex[strtolower($base)] ?? null;
            if ($meta === null) {
                $result['missing'][] = $base;
                $updateProgress();
                continue;
            }

            $src = $meta['path'];
            $srcSignature = $meta['signature'] ?? $this->computeFileSignature($src);

            $dstPath = $dest . DIRECTORY_SEPARATOR . $base;
            $dstSignature = is_file($dstPath) ? $this->computeFileSignature($dstPath) : null;
            $needsCopy = true;
            if ($dstSignature !== null && $srcSignature !== null && $dstSignature === $srcSignature) {
                $needsCopy = false;
            }
            $signatureForDb = $srcSignature;
            try {
                if ($needsCopy) {
                    if (!@copy($src, $dstPath)) {
                        $result['failed'][] = $base;
                        $updateProgress();
                        continue;
                    }
                    if (isset($meta['mtime']) && is_int($meta['mtime'])) {
                        @touch($dstPath, $meta['mtime']);
                    }
                    $signatureForDb = $this->computeFileSignature($dstPath) ?? $srcSignature;
                    $result['copied'][] = $base;
                } else {
                    $signatureForDb = $dstSignature ?? $srcSignature;
                }
                $upsert->execute([
                    ':name' => $base,
                    ':md5'  => $signatureForDb,
                ]);
            } catch (\Throwable $e) {
                $result['failed'][] = $base;
            }
            $updateProgress();
        }

        $result['total_unique'] = $total;

        if ($this->tracker()) {
            if (!empty($result['missing'])) {
                $this->logWarning(
                    'Bilddateien nicht gefunden',
                    [
                        'count' => count($result['missing']),
                        'samples' => array_slice($result['missing'], 0, self::ERROR_SAMPLE_SIZE),
                        'operation' => 'copy',
                        'articles_by_image' => $this->summarizeArticlesByImage($result['missing']),
                    ],
                    'bilder'
                );
            }
            if (!empty($result['failed'])) {
                $this->logError(
                    'Bilddateien konnten nicht kopiert werden',
                    [
                        'count' => count($result['failed']),
                        'samples' => array_slice($result['failed'], 0, self::ERROR_SAMPLE_SIZE),
                        'operation' => 'copy',
                    ],
                    'bilder'
                );
            }
        }

        return $result;
    }

    public function sync(string $sourceDir, ?string $destDir = null): array
    {
        $afsNames = is_array($this->afs->Bilder) ? $this->afs->Bilder : [];
        $root     = dirname(__DIR__);
        $dest     = $destDir ?: ($root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'Bilder');
        $this->ensureDirectory($dest);

        $temp = $this->loadBilderState();
        $afsBaseNames = $this->normalizeFilenames($afsNames);
        foreach ($afsBaseNames as $base) {
            if (!isset($temp[$base])) {
                $temp[$base] = ['id' => null, 'md5' => null, 'update' => 0];
            }
        }

        $result = [
            'copied'  => [],
            'skipped' => [],
            'missing' => [],
            'failed'  => [],
        ];

        if ($sourceDir === null || !is_dir($sourceDir)) {
            $this->logWarning('Bildabgleich übersprungen – Quellverzeichnis existiert nicht', ['source' => $sourceDir], 'bilder_sync');
            return $result;
        }

        $fileIndex = $this->buildFileIndex($sourceDir);
        $afsSet = array_fill_keys($afsBaseNames, true);

        foreach ($temp as $name => &$info) {
            if (!isset($afsSet[$name])) {
                continue;
            }
            $meta = $fileIndex[strtolower($name)] ?? null;
            if ($meta === null) {
                $result['missing'][] = $name;
                continue;
            }

            $src = $meta['path'];
            $newSignature = $meta['signature'] ?? $this->computeFileSignature($src);

            if ($newSignature !== null && $newSignature === ($info['md5'] ?? null)) {
                $result['skipped'][] = $name;
                continue;
            }

            $dstPath = $dest . DIRECTORY_SEPARATOR . $name;
            try {
                $needsCopy = true;
                $existingSignature = is_file($dstPath) ? $this->computeFileSignature($dstPath) : null;
                if ($existingSignature !== null && $newSignature !== null && $existingSignature === $newSignature) {
                    $needsCopy = false;
                }
                if ($needsCopy) {
                    if (!@copy($src, $dstPath)) {
                        $result['failed'][] = $name;
                        continue;
                    }
                    if (isset($meta['mtime']) && is_int($meta['mtime'])) {
                        @touch($dstPath, $meta['mtime']);
                    }
                    $newSignature = $this->computeFileSignature($dstPath) ?? $newSignature;
                }
                $info['md5'] = $newSignature;
                $info['update'] = 1;
                $result['copied'][] = $name;
            } catch (\Throwable $e) {
                $result['failed'][] = $name;
            }
        }
        unset($info);

        $ins = $this->db->prepare('INSERT OR IGNORE INTO Bilder (Bildname, md5, "update", uploaded) VALUES (:name, :md5, :upd, :upl)');
        $upd = $this->db->prepare('UPDATE Bilder SET md5 = :md5, "update" = :upd, uploaded = :upl WHERE Bildname = :name');
        $sel = $this->db->prepare('SELECT md5 FROM Bilder WHERE Bildname = :name');

        $this->db->beginTransaction();
        try {
            foreach ($temp as $name => $info) {
                if (!isset($afsSet[$name])) {
                    continue;
                }
                $sel->execute([':name' => $name]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);

                if ($row === false) {
                    $ins->execute([':name'=>$name, ':md5'=>$info['md5'], ':upd'=>(int)($info['update']??0), ':upl'=>0]);
                } else {
                    if ((int)($info['update'] ?? 0) === 1 && ($info['md5'] ?? null) !== ($row['md5'] ?? null)) {
                        $upd->execute([':name'=>$name, ':md5'=>$info['md5'], ':upd'=>1, ':upl'=>0]);
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($this->tracker()) {
            if (!empty($result['missing'])) {
                $this->logWarning(
                    'Bilddateien für Abgleich nicht gefunden',
                    [
                        'count' => count($result['missing']),
                        'samples' => array_slice($result['missing'], 0, self::ERROR_SAMPLE_SIZE),
                        'operation' => 'sync',
                        'articles_by_image' => $this->summarizeArticlesByImage($result['missing']),
                    ],
                    'bilder_sync'
                );
            }
            if (!empty($result['failed'])) {
                $this->logError(
                    'Bilddateien konnten nicht synchronisiert werden',
                    [
                        'count' => count($result['failed']),
                        'samples' => array_slice($result['failed'], 0, self::ERROR_SAMPLE_SIZE),
                        'operation' => 'sync',
                    ],
                    'bilder_sync'
                );
            }
        }

        return $result;
    }

    public function loadBildIdMap(): array
    {
        $out = [];
        $sql = 'SELECT ID, Bildname FROM Bilder';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['Bildname'];
            if ($name === '') {
                continue;
            }
            $base = basename($name);
            $id   = (int)$row['ID'];
            $out[$name] = $id;
            if ($base !== $name) {
                $out[$base] = $id;
            }
            $out[strtolower($base)] = $id;
        }
        return $out;
    }

    private function loadBilderState(): array
    {
        $out = [];
        $sql = 'SELECT ID, Bildname, md5, "update" FROM Bilder';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['Bildname']] = [
                'id' => isset($row['ID']) ? (int)$row['ID'] : null,
                'md5' => $row['md5'] !== null ? (string)$row['md5'] : null,
                'update' => isset($row['update']) ? (int)$row['update'] : 0,
            ];
        }
        return $out;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public function resolveBildId(array $map, ?string $name): ?int
    {
        if ($name === null) {
            return null;
        }
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }
        $base = basename($trimmed);
        if ($base === '') {
            return null;
        }

        $candidates = [
            $trimmed,
            $base,
            strtolower($base),
        ];

        foreach ($candidates as $candidate) {
            if (isset($map[$candidate])) {
                return (int)$map[$candidate];
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $baseNames
     * @return array<string,array<int,string>>
     */
    private function summarizeArticlesByImage(array $baseNames): array
    {
        if ($baseNames === []) {
            return [];
        }

        $map = $this->findArticlesForImages($baseNames);
        $summary = [];
        foreach ($baseNames as $name) {
            $normalized = trim((string)$name);
            if ($normalized === '') {
                continue;
            }
            $articles = $map[strtolower($normalized)] ?? [];
            if ($articles !== []) {
                $summary[$normalized] = array_slice(array_values(array_unique($articles)), 0, 5);
            } else {
                $summary[$normalized] = [];
            }
        }
        return $summary;
    }

    /**
     * @param array<int,string> $baseNames
     * @return array<string,array<int,string>>
     */
    private function findArticlesForImages(array $baseNames): array
    {
        if ($baseNames === []) {
            return [];
        }

        $lookup = [];
        foreach ($baseNames as $name) {
            $base = trim((string)$name);
            if ($base === '') {
                continue;
            }
            $lookup[strtolower($base)] = $base;
        }
        if ($lookup === []) {
            return [];
        }

        $map = [];
        foreach ($this->afs->Artikel as $article) {
            if (!is_array($article)) {
                continue;
            }
            $articleRef = $this->buildArticleReference($article);

            for ($i = 1; $i <= 10; $i++) {
                $field = 'Bild' . $i;
                if (empty($article[$field])) {
                    continue;
                }
                $base = basename((string)$article[$field]);
                if ($base === '') {
                    continue;
                }
                $lower = strtolower($base);
                if (!isset($lookup[$lower])) {
                    continue;
                }
                $key = $lookup[$lower];
                $map[$lower] ??= [];
                $map[$lower][] = $articleRef;
            }
        }

        return $map;
    }
}
