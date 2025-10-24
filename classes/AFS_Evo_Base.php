<?php

abstract class AFS_Evo_Base
{
    // Constants for performance tuning
    protected const CHUNK_SIZE_FOR_ID_MAP = 500;
    protected const PROGRESS_UPDATE_INTERVAL = 10;
    protected const ERROR_SAMPLE_SIZE = 12;
    
    protected PDO $db;
    protected AFS $afs;
    protected ?AFS_Evo_StatusTracker $status;
    /** @var array<string,string|null> Cache for file signatures to avoid redundant computations */
    private array $signatureCache = [];

    public function __construct(PDO $db, AFS $afs, ?AFS_Evo_StatusTracker $status = null)
    {
        $this->db  = $db;
        $this->afs = $afs;
        $this->status = $status;
    }

    /**
     * Entfernt Leerwerte, trimmt und dedupliziert Zeichenketten.
     *
     * @param array<int, string> $values
     * @return array<int, string>
     */
    protected function normalizeStrings(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '' || isset($unique[$value])) {
                continue;
            }
            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    /**
     * Liefert eindeutige Dateinamen (case-insensitive) zurück.
     *
     * @param array<int, string> $values
     * @return array<int, string>
     */
    protected function normalizeFilenames(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $base = basename($value);
            if ($base === '') {
                continue;
            }
            $lower = strtolower($base);
            if (!isset($unique[$lower])) {
                $unique[$lower] = $base;
            }
        }

        return array_values($unique);
    }

    /**
     * Holt IDs für die angegebenen Werte in einem Rutsch (chunked IN-Statement).
     *
     * @param array<int, string> $values
     * @return array<string, int>
     */
    protected function fetchIdMap(string $table, string $idColumn, string $valueColumn, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $tableQ = $this->quoteIdent($table);
        $idColumnQ = $this->quoteIdent($idColumn);
        $valueColumnQ = $this->quoteIdent($valueColumn);

        $map = [];
        foreach (array_chunk($values, self::CHUNK_SIZE_FOR_ID_MAP) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT {$valueColumnQ} AS val, {$idColumnQ} AS id FROM {$tableQ} WHERE {$valueColumnQ} IN ({$placeholders})";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($chunk);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $map[(string)$row['val']] = (int)$row['id'];
            }
        }

        return $map;
    }

    protected function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    protected function nullIfEmpty($value)
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }

    protected function intOrNull($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    protected function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float)$value;
    }

    protected function boolToInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null || $value === '') {
            return 0;
        }
        $truthy = ['1','true','TRUE','ja','JA','yes','YES','y','Y'];
        if (is_numeric($value)) {
            return ((int)$value) === 1 ? 1 : 0;
        }
        if (is_string($value) && in_array($value, $truthy, true)) {
            return 1;
        }
        return 0;
    }

    protected function toTimestamp($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }
        $ts = strtotime($str);
        return $ts === false ? null : $ts;
    }

    protected function tracker(): ?AFS_Evo_StatusTracker
    {
        return $this->status;
    }

    /**
     * Erzeugt eine gut lesbare Artikelreferenz (Artikelnummer · Bezeichnung).
     *
     * @param array<string,mixed> $article
     */
    protected function buildArticleReference(array $article): string
    {
        $parts = [];
        if (!empty($article['Artikelnummer'])) {
            $parts[] = (string)$article['Artikelnummer'];
        } elseif (!empty($article['Artikel'])) {
            $parts[] = 'ID ' . (string)$article['Artikel'];
        }
        if (!empty($article['Bezeichnung'])) {
            $parts[] = (string)$article['Bezeichnung'];
        }

        $label = trim(implode(' · ', $parts));
        return $label === '' ? 'unbekannter Artikel' : $label;
    }

    protected function findArticleById(int $articleId): ?array
    {
        foreach ($this->afs->Artikel as $article) {
            if (!is_array($article)) {
                continue;
            }
            if (isset($article['Artikel']) && (int)$article['Artikel'] === $articleId) {
                return $article;
            }
        }
        return null;
    }

    protected function articleReferenceById(?int $articleId): ?string
    {
        if ($articleId === null) {
            return null;
        }
        $article = $this->findArticleById($articleId);
        return $article ? $this->buildArticleReference($article) : null;
    }

    /**
     * Baut ein Dateiverzeichnis-Index (case-insensitive) mit Zusatzmetadaten (Pfad, mtime, size, signature).
     *
     * @return array<string,array{path:string,mtime:int|null,size:int|null,signature:?string}>
     */
    protected function buildFileIndex(string $baseDir): array
    {
        $index = [];
        if (!is_dir($baseDir)) {
            return $index;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $filename = strtolower($file->getFilename());
            if ($filename === '') {
                continue;
            }
            $path = $file->getPathname();
            $size = $file->getSize();
            $mtime = $file->getMTime();
            $index[$filename] = [
                'path' => $path,
                'size' => is_int($size) ? $size : null,
                'mtime' => is_int($mtime) ? $mtime : null,
                'signature' => $this->computeFileSignatureFromStats($size, $mtime),
            ];
        }

        return $index;
    }

    protected function computeFileSignature(string $path): ?string
    {
        // Check cache first to avoid redundant filesystem calls
        if (isset($this->signatureCache[$path])) {
            return $this->signatureCache[$path];
        }
        
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if ($size === false || $mtime === false) {
            $this->signatureCache[$path] = null;
            return null;
        }
        $signature = $this->computeFileSignatureFromStats($size, $mtime);
        $this->signatureCache[$path] = $signature;
        return $signature;
    }

    protected function computeFileSignatureFromStats($size, $mtime): ?string
    {
        if (!is_int($size) || !is_int($mtime)) {
            return null;
        }
        return $size . ':' . $mtime;
    }

    protected function logError(string $message, array $context = [], ?string $stage = null): void
    {
        if ($this->status) {
            $this->status->logError($message, $context, $stage);
        }
    }

    protected function logWarning(string $message, array $context = [], ?string $stage = null): void
    {
        if ($this->status) {
            $this->status->logWarning($message, $context, $stage);
        }
    }

    protected function logInfo(string $message, array $context = [], ?string $stage = null): void
    {
        if ($this->status) {
            $this->status->logInfo($message, $context, $stage);
        }
    }
}
