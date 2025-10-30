<?php
declare(strict_types=1);

use PDOStatement;

/**
 * TargetMapper
 *
 * Verwaltet die Ziel-Schema-Beschreibung (z. B. mappings/evo.yml) und
 * generiert dynamisch vorbereitete UPSERT-Statements für SQLite (oder
 * andere Ziele mit identischer Syntax). Für Performance werden Statements
 * pro Tabellenspaltenkombination gecacht.
 */
class TargetMapper
{
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,array<string,mixed>> */
    private array $tables;
    /** @var array<string, PDOStatement> */
    private array $statementCache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $tables = $config['tables'] ?? [];
        $this->tables = is_array($tables) ? $tables : [];
    }

    /**
     * Convenience factory für YAML-Dateien.
     */
    public static function fromFile(string $path): self
    {
        $data = YamlMappingLoader::load($path);
        return new self($data);
    }

    /**
     * Führt ein UPSERT (INSERT ... ON CONFLICT DO UPDATE) für die angegebene Tabelle aus.
     *
     * @param PDO   $connection Ziel-DB-Verbindung
     * @param string $tableName Tabellenname wie im YAML definiert
     * @param array<string,mixed> $row Datenzeile (Spalte => Wert)
     */
    public function upsert(PDO $connection, string $tableName, array $row): void
    {
        if ($row === []) {
            return;
        }

        $tableConfig = $this->getTableConfig($tableName);
        $keys = $this->getUniqueKeys($tableConfig);
        if ($keys === []) {
            throw new RuntimeException(sprintf('Keine eindeutigen Schlüssel für Tabelle "%s" definiert.', $tableName));
        }

        $columns = array_keys($row);
        sort($columns);

        $cacheKey = $this->buildCacheKey($tableName, $columns);
        $statement = $this->statementCache[$cacheKey] ?? null;

        if ($statement === null) {
            $sql = $this->buildUpsertSql($tableName, $columns, $keys);
            $statement = $connection->prepare($sql);
            if ($statement === false) {
                throw new RuntimeException('Vorbereiten des UPSERT-Statements fehlgeschlagen: ' . $sql);
            }
            $this->statementCache[$cacheKey] = $statement;
        }

        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $row[$column] ?? null;
        }

        if ($statement->execute($params) === false) {
            $errorInfo = $statement->errorInfo();
            throw new RuntimeException(sprintf(
                'UPSERT fehlgeschlagen (%s): %s',
                $tableName,
                $errorInfo[2] ?? 'Unbekannter Fehler'
            ));
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getTableConfig(string $tableName): array
    {
        $tableConfig = $this->tables[$tableName] ?? null;
        if (!is_array($tableConfig) || $tableConfig === []) {
            throw new RuntimeException(sprintf('Tabelle "%s" ist in der Zielkonfiguration nicht definiert.', $tableName));
        }
        return $tableConfig;
    }

    /**
     * @param array<string,mixed> $tableConfig
     * @return array<int,string>
     */
    private function getUniqueKeys(array $tableConfig): array
    {
        $keys = $tableConfig['business_key'] ?? $tableConfig['keys'] ?? [];
        if (is_string($keys)) {
            return [$keys];
        }
        if (!is_array($keys)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $keys), static fn($item) => $item !== ''));
    }

    /**
     * Baut das eigentliche UPSERT-Statement.
     *
     * @param array<int,string> $columns
     * @param array<int,string> $keys
     */
    public function generateUpsertSql(string $tableName, array $columns): string
    {
        $tableConfig = $this->getTableConfig($tableName);
        $keys = $this->getUniqueKeys($tableConfig);
        if ($keys === []) {
            throw new RuntimeException(sprintf('Keine eindeutigen Schlüssel für Tabelle "%s".', $tableName));
        }
        $normalized = array_values(array_unique(array_map('strval', $columns)));
        sort($normalized);
        return $this->buildUpsertSql($tableName, $normalized, $keys);
    }

    public function generateDeleteSql(string $tableName, array $whereColumns): string
    {
        if ($whereColumns === []) {
            throw new RuntimeException('Delete statement benötigt mindestens eine WHERE-Spalte.');
        }
        $table = $this->quoteIdentifier($tableName);
        $conditions = [];
        foreach ($whereColumns as $column) {
            $placeholder = ':' . strtolower((string)$column);
            $conditions[] = sprintf('%s = %s', $this->quoteIdentifier((string)$column), $placeholder);
        }
        return sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $conditions));
    }

    private function buildUpsertSql(string $tableName, array $columns, array $keys): string
    {
        $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
        $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);

        $quotedTable = $this->quoteIdentifier($tableName);
        $quotedKeys = array_map([$this, 'quoteIdentifier'], $keys);

        $updateColumns = array_diff($columns, $keys);
        $updateAssignments = [];
        foreach ($updateColumns as $column) {
            $quoted = $this->quoteIdentifier($column);
            $updateAssignments[] = sprintf('%s = excluded.%s', $quoted, $quoted);
        }

        $insert = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $quotedTable,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );

        if ($updateAssignments === []) {
            return sprintf(
                '%s ON CONFLICT(%s) DO NOTHING',
                $insert,
                implode(', ', $quotedKeys)
            );
        }

        return sprintf(
            '%s ON CONFLICT(%s) DO UPDATE SET %s',
            $insert,
            implode(', ', $quotedKeys),
            implode(', ', $updateAssignments)
        );
    }

    /**
     * @param array<int,string> $columns
     */
    private function buildCacheKey(string $tableName, array $columns): string
    {
        return $tableName . '|' . implode(',', $columns);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $parts = array_map('trim', explode('.', $identifier));
        $quoted = [];
        foreach ($parts as $part) {
            if ($part === '*') {
                $quoted[] = '*';
                continue;
            }
            $quoted[] = '"' . str_replace('"', '""', $part) . '"';
        }
        return implode('.', $quoted);
    }
}
