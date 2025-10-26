<?php

class EVO_DocumentSync extends EVO_Base
{
    public function import(): array
    {
        $rows = is_array($this->afs->Dokumente) ? $this->afs->Dokumente : [];
        if ($rows === []) {
            return [];
        }

        $titles = [];

        $this->db->beginTransaction();
        try {
            $upsert = $this->db->prepare(
                'INSERT INTO Dokumente (Titel, Dateiname, Art, "update")
                 VALUES (:titel, :dateiname, :art, 1)
                 ON CONFLICT(Titel) DO UPDATE SET
                    Dateiname = excluded.Dateiname,
                    Art = excluded.Art,
                    "update" = 1'
            );

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
                if ($title === '') {
                    continue;
                }
                $titles[] = $title;
                $filename = isset($row['Dateiname']) ? $this->nullIfEmpty($row['Dateiname']) : null;
                $art = isset($row['Art']) && $row['Art'] !== '' ? (int)$row['Art'] : null;
                $upsert->execute([
                    ':titel' => $title,
                    ':dateiname' => $filename,
                    ':art' => $art,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $map = $this->fetchIdMap('Dokumente', 'ID', 'Titel', $this->normalizeStrings($titles));
        $enrichedMap = $map;
        foreach ($map as $titleKey => $idVal) {
            $lower = strtolower($titleKey);
            if (!isset($enrichedMap[$lower])) {
                $enrichedMap[$lower] = $idVal;
            }
        }

        foreach ($this->afs->Dokumente as &$doc) {
            if (!is_array($doc)) {
                continue;
            }
            $title = isset($doc['Titel']) ? trim((string)$doc['Titel']) : '';
            if ($title === '') {
                continue;
            }
            if (isset($enrichedMap[$title])) {
                $doc['ID'] = $enrichedMap[$title];
            } elseif (isset($enrichedMap[strtolower($title)])) {
                $doc['ID'] = $enrichedMap[strtolower($title)];
            }
        }
        unset($doc);

        return $enrichedMap;
    }

    public function loadDocumentIdMap(): array
    {
        $map = [];
        $sql = 'SELECT ID, Titel FROM Dokumente';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
            if ($title === '') {
                continue;
            }
            $id = (int)$row['ID'];
            $map[$title] = $id;
            $map[strtolower($title)] = $id;
        }
        return $map;
    }

    public function copy(string $sourceDir, ?string $destDir = null): array
    {
        $rows = is_array($this->afs->Dokumente) ? $this->afs->Dokumente : [];
        $root = dirname(__DIR__);
        $dest = $destDir ?: ($root . DIRECTORY_SEPARATOR . 'Files' . DIRECTORY_SEPARATOR . 'Dokumente');

        $this->ensureDirectory($dest);

        $result = [
            'copied'  => [],
            'missing' => [],
            'failed'  => [],
            'total_unique' => 0,
        ];

        if (!is_array($rows) || $rows === []) {
            return $result;
        }

        if ($sourceDir === null || !is_dir($sourceDir)) {
            $this->logWarning('Dokumentkopie übersprungen – Quellverzeichnis existiert nicht', ['source' => $sourceDir], 'dokumente');
            return $result;
        }

        $files = $this->buildFileIndex($sourceDir);

        $uniqueFilenames = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $filename = $this->extractDocumentFilename($row);
            if ($filename === null) {
                continue;
            }
            $key = strtolower($filename);
            if (!isset($uniqueFilenames[$key])) {
                $uniqueFilenames[$key] = $filename;
            }
        }

        $upsert = $this->db->prepare(
            'INSERT INTO Dokumente (Titel, Dateiname, Art, md5, "update", uploaded)
             VALUES (:title, :filename, :art, :md5, 1, 0)
             ON CONFLICT(Titel) DO UPDATE SET
                Dateiname = COALESCE(excluded.Dateiname, Dokumente.Dateiname),
                Art = COALESCE(excluded.Art, Dokumente.Art),
                md5 = excluded.md5,
                "update" = 1,
                uploaded = 0
             WHERE IFNULL(Dokumente.md5, \'\') <> IFNULL(excluded.md5, \'\')'
        );

        $tracker = $this->tracker();
        $total = count($uniqueFilenames);
        $processed = 0;
        $seenProgress = [];
        $addedMissing = [];
        $addedFailed = [];
        $addedCopied = [];

        $updateProgress = function () use (&$processed, $total, $tracker) {
            if (!$tracker || $total === 0) {
                return;
            }
            if ($processed === 0 || $processed % self::PROGRESS_UPDATE_INTERVAL === 0 || $processed === $total) {
                $tracker->advance('dokumente_kopieren', [
                    'message' => sprintf('Kopiere Dokumente (%d / %d)', $processed, $total),
                    'processed' => $processed,
                    'total' => $total,
                ]);
            }
        };

        if ($tracker && $total > 0) {
            $tracker->advance('dokumente_kopieren', [
                'message' => sprintf('Kopiere Dokumente (0 / %d)', $total),
                'processed' => 0,
                'total' => $total,
            ]);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
            $filename = $this->extractDocumentFilename($row);
            if ($filename === null) {
                continue;
            }

            $key = strtolower($filename);
            if (isset($addedCopied[$key]) || isset($addedMissing[$key]) || isset($addedFailed[$key])) {
                continue;
            }
            if (!isset($seenProgress[$key])) {
                $processed++;
                $seenProgress[$key] = true;
                $updateProgress();
            }

            $meta = $files[$key] ?? null;
            if ($meta === null) {
                if (!isset($addedMissing[$key])) {
                    $result['missing'][] = $filename;
                    $addedMissing[$key] = true;
                }
                continue;
            }

            $srcPath = $meta['path'];
            $srcSignature = $meta['signature'] ?? $this->computeFileSignature($srcPath);

            $dstPath = $dest . DIRECTORY_SEPARATOR . $filename;
            $dstSignature = is_file($dstPath) ? $this->computeFileSignature($dstPath) : null;
            $needsCopy = true;
            if ($dstSignature !== null && $srcSignature !== null && $dstSignature === $srcSignature) {
                $needsCopy = false;
            }
            $signatureForDb = $srcSignature;

            try {
                if ($needsCopy) {
                    if (!@copy($srcPath, $dstPath)) {
                        if (!isset($addedFailed[$key])) {
                            $result['failed'][] = $filename;
                            $addedFailed[$key] = true;
                        }
                        continue;
                    }
                    if (isset($meta['mtime']) && is_int($meta['mtime'])) {
                        @touch($dstPath, $meta['mtime']);
                    }
                    $signatureForDb = $this->computeFileSignature($dstPath) ?? $srcSignature;
                    if (!isset($addedCopied[$key])) {
                        $result['copied'][] = $filename;
                    }
                } else {
                    $signatureForDb = $dstSignature ?? $srcSignature;
                }

                if ($title !== '') {
                    $artValue = isset($row['Art']) && $row['Art'] !== '' ? (int)$row['Art'] : null;
                    $upsert->execute([
                        ':title' => $title,
                        ':filename' => $filename,
                        ':art' => $artValue,
                        ':md5' => $signatureForDb,
                    ]);
                }
                $addedCopied[$key] = true;
            } catch (\Throwable $e) {
                if (!isset($addedFailed[$key])) {
                    $result['failed'][] = $filename;
                    $addedFailed[$key] = true;
                }
            }
        }

        $result['total_unique'] = $total;

        return $result;
    }

    private function extractDocumentFilename(array $row): ?string
    {
        $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
        if ($title !== '') {
            $normalized = strtr($title, ['\\' => '/', '//' => '/']);
            $base = basename($normalized);
            if ($base !== '') {
                return $this->ensurePdfExtension($base);
            }
        }
        $filename = $row['Dateiname'] ?? null;
        if ($filename !== null) {
            $filename = trim((string)$filename);
            if ($filename !== '') {
                $base = basename($filename);
                if ($base !== '') {
                    return $this->ensurePdfExtension($base);
                }
            }
        }
        return null;
    }

    private function ensurePdfExtension(string $name): string
    {
        if (str_contains($name, '.')) {
            return $name;
        }
        return $name . '.pdf';
    }

    /**
     * Analysiert fehlende/fehlgeschlagene Dokumente, protokolliert Artikelbezüge und nutzt Fortschrittsbalken.
     *
     * @param array<string,mixed> $copyResult
     * @param array<int,array<string,mixed>> $rows
     */
    public function analyseCopyIssues(array $copyResult, array $rows, string $stage = 'dokumente_pruefung'): void
    {
        $missing = array_values(array_unique($copyResult['missing'] ?? []));
        $failed = array_values(array_unique($copyResult['failed'] ?? []));
        $total = count($missing) + count($failed);

        $tracker = $this->tracker();
        if ($tracker) {
            $tracker->advance($stage, [
                'message' => $total > 0
                    ? sprintf('Analysiere Dokumentverweise (%d offene Elemente)', $total)
                    : 'Analysiere Dokumentverweise (keine offenen Elemente)',
                'processed' => 0,
                'total' => $total,
            ]);
        }

        if ($total === 0) {
            if ($tracker) {
                $tracker->advance($stage, [
                    'message' => 'Alle Dokumentdateien wurden erfolgreich kopiert.',
                    'processed' => 0,
                    'total' => 0,
                ]);
            }
            $this->logInfo(
                'Dokumentenprüfung abgeschlossen',
                [
                    'missing' => 0,
                    'failed' => 0,
                ],
                $stage
            );
            return;
        }

        $referenceMap = $this->summarizeArticlesByDocument(
            $rows,
            array_values(array_unique(array_merge($missing, $failed)))
        );

        $processed = 0;
        $advance = function () use (&$processed, $total, $tracker, $stage) {
            if (!$tracker) {
                return;
            }
            $tracker->advance($stage, [
                'message' => sprintf('Analysiere Dokumentverweise (%d / %d)', $processed, $total),
                'processed' => $processed,
                'total' => $total,
            ]);
        };

        $missingContext = [];
        foreach ($missing as $filename) {
            $processed++;
            $missingContext[$filename] = $referenceMap[$filename] ?? [];
            $advance();
        }

        $failedContext = [];
        foreach ($failed as $filename) {
            $processed++;
            $failedContext[$filename] = $referenceMap[$filename] ?? [];
            $advance();
        }

        if ($tracker) {
            $tracker->advance($stage, [
                'message' => sprintf('Dokumentenprüfung abgeschlossen (%d Elemente analysiert)', $total),
                'processed' => $total,
                'total' => $total,
            ]);
        }

        if ($missing !== []) {
            $this->logWarning(
                'Dokumentdateien nicht gefunden',
                [
                    'count' => count($missing),
                    'samples' => array_slice($missing, 0, self::ERROR_SAMPLE_SIZE),
                    'articles_by_document' => $this->limitContext($missingContext),
                    'operation' => 'analyse',
                ],
                $stage
            );
        }

        if ($failed !== []) {
            $this->logError(
                'Dokumentdateien konnten nicht kopiert werden',
                [
                    'count' => count($failed),
                    'samples' => array_slice($failed, 0, self::ERROR_SAMPLE_SIZE),
                    'articles_by_document' => $this->limitContext($failedContext),
                    'operation' => 'analyse',
                ],
                $stage
            );
        }

        $this->logInfo(
            'Dokumentenprüfung abgeschlossen',
            [
                'missing' => count($missing),
                'failed' => count($failed),
                'operation' => 'analyse',
            ],
            $stage
        );
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    public function resolveDocumentId(array $map, ?string $title): ?int
    {
        if ($title === null) {
            return null;
        }
        $trimmed = trim($title);
        if ($trimmed === '') {
            return null;
        }
        $lower = strtolower($trimmed);
        if (isset($map[$trimmed])) {
            return (int)$map[$trimmed];
        }
        if (isset($map[$lower])) {
            return (int)$map[$lower];
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $filenames
     * @return array<string,array<int,string>>
     */
    private function summarizeArticlesByDocument(array $rows, array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }

        $lookup = [];
        foreach ($filenames as $name) {
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
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $filename = $this->extractDocumentFilename($row);
            if ($filename === null || $filename === '') {
                continue;
            }
            $lower = strtolower($filename);
            if (!isset($lookup[$lower])) {
                continue;
            }
            $articleId = isset($row['Artikel']) && $row['Artikel'] !== '' ? (int)$row['Artikel'] : null;
            $articleRef = $this->articleReferenceById($articleId);
            if ($articleRef === null) {
                continue;
            }
            $key = $lookup[$lower];
            $map[$key] ??= [];
            $map[$key][] = $articleRef;
        }

        $summary = [];
        foreach ($lookup as $lower => $original) {
            $list = $map[$original] ?? [];
            if ($list !== []) {
                $summary[$original] = array_slice(array_values(array_unique($list)), 0, 5);
            } else {
                $summary[$original] = [];
            }
        }

        return $summary;
    }

    /**
     * @param array<string,array<int,string>> $context
     * @return array<string,array<int,string>>
     */
    private function limitContext(array $context, int $limit = 10): array
    {
        if (count($context) <= $limit) {
            return $context;
        }
        return array_slice($context, 0, $limit, true);
    }
}
