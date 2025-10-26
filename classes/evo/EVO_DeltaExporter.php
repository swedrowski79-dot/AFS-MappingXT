<?php

declare(strict_types=1);

class EVO_DeltaExporter
{
    private PDO $db;
    private string $targetPath;
    private ?STATUS_Tracker $status;
    private ?STATUS_MappingLogger $logger;

    public function __construct(PDO $db, string $targetPath, ?STATUS_Tracker $status = null, ?STATUS_MappingLogger $logger = null)
    {
        $this->db = $db;
        $this->targetPath = $targetPath;
        $this->status = $status;
        $this->logger = $logger;
    }

    /**
     * Exportiert alle Datensätze mit update=1 in eine eigenständige SQLite-Datei
     * und setzt anschließend die Flags in der Hauptdatenbank zurück.
     *
     * @return array<string,int> Anzahl exportierter Zeilen je Tabelle
     */
    public function export(): array
    {
        $startTime = microtime(true);
        
        $this->ensureDirectory(dirname($this->targetPath));
        if (is_file($this->targetPath)) {
            @unlink($this->targetPath);
        }

        $deltaHandle = new PDO('sqlite:' . $this->targetPath);
        $deltaHandle->exec('PRAGMA journal_mode=OFF');
        $deltaHandle->exec('PRAGMA synchronous=OFF');
        $deltaHandle = null;

        $tables = $this->tablesWithUpdateColumn();
        if ($tables === []) {
            $this->status?->logInfo('Delta-Export übersprungen: Keine Tabellen mit update-Spalte gefunden', [], 'delta_export');
            return [];
        }

        $this->attachDelta();
        $exportCounts = [];

        try {
            foreach ($tables as $table) {
                $this->createTableInDelta($table);
                $count = $this->copyRows($table);
                if ($count > 0) {
                    $exportCounts[$table] = $count;
                }
            }
        } finally {
            $this->detachDelta();
        }

        $this->resetUpdateFlags($tables);
        
        // Calculate statistics
        $totalTables = count($exportCounts);
        $totalRows = array_sum($exportCounts);
        $duration = microtime(true) - $startTime;
        
        // Log to StatusTracker (for UI)
        $this->status?->logInfo(
            sprintf('Delta-Export abgeschlossen: %d Tabellen, %d Datensätze in %.2fs', $totalTables, $totalRows, $duration),
            [
                'target' => $this->targetPath,
                'tables' => $exportCounts,
                'total_tables' => $totalTables,
                'total_rows' => $totalRows,
                'duration_seconds' => round($duration, 2),
            ],
            'delta_export'
        );

        // Log to file logger (for permanent records)
        $this->logger?->logDeltaExport($duration, $exportCounts, $totalRows, $this->targetPath);

        return $exportCounts;
    }

    private function tablesWithUpdateColumn(): array
    {
        $stmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $result = [];
        foreach ($tables as $table) {
            if ($this->tableHasUpdateColumn((string)$table)) {
                $result[] = (string)$table;
            }
        }
        return $result;
    }

    private function tableHasUpdateColumn(string $table): bool
    {
        $stmt = $this->db->prepare("PRAGMA table_info(\"{$table}\")");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($columns as $column) {
            if (strcasecmp((string)$column['name'], 'update') === 0) {
                return true;
            }
        }
        return false;
    }

    private function attachDelta(): void
    {
        $escaped = str_replace("'", "''", $this->targetPath);
        $this->db->exec("ATTACH DATABASE '{$escaped}' AS delta");
        $this->db->exec('PRAGMA delta.foreign_keys = OFF');
    }

    private function detachDelta(): void
    {
        $this->db->exec('DETACH DATABASE delta');
    }

    private function createTableInDelta(string $table): void
    {
        $stmt = $this->db->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = :table");
        $stmt->execute([':table' => $table]);
        $createSql = $stmt->fetchColumn();
        if (!is_string($createSql) || trim($createSql) === '') {
            return;
        }

        $patched = preg_replace('/^CREATE TABLE\s+IF NOT EXISTS\s+/i', 'CREATE TABLE IF NOT EXISTS delta.', $createSql, 1);
        if ($patched === null) {
            return;
        }
        if ($patched === $createSql) {
            $patched = preg_replace('/^CREATE TABLE\s+/i', 'CREATE TABLE delta.', $createSql, 1);
            if ($patched === null) {
                return;
            }
        }

        $createSql = $patched;

        $this->db->exec("DROP TABLE IF EXISTS delta.\"{$table}\"");
        $this->db->exec($createSql);
    }

    private function copyRows(string $table): int
    {
        $this->db->exec("DELETE FROM delta.\"{$table}\"");
        $this->db->exec(
            "INSERT INTO delta.\"{$table}\" SELECT * FROM \"{$table}\" WHERE \"update\" = 1"
        );
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM delta.\"{$table}\"");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function resetUpdateFlags(array $tables): void
    {
        foreach ($tables as $table) {
            $this->db->exec("UPDATE \"{$table}\" SET \"update\" = 0 WHERE \"update\" = 1");
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}
