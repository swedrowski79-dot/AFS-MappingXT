<?php
declare(strict_types=1);

/**
 * TargetMapper
 *
 * Nutzt das Zielschema (z. B. schemas/evo.yml), um vorbereitete UPSERT-Statements
 * dynamisch zu erzeugen. Unterstützt sowohl das neue "tables"-Format als auch
 * das Legacy-Format mit "entities"/"relationships".
 */
class TargetMapper
{
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,array<string,mixed>> */
    private array $tables;
    /** @var array<string,mixed> */
    private array $entities;
    /** @var array<string,mixed> */
    private array $relationships;
    /** @var array<string, PDOStatement> */
    private array $statementCache = [];

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->entities = is_array($config['entities'] ?? null) ? $config['entities'] : [];
        $this->relationships = is_array($config['relationships'] ?? null) ? $config['relationships'] : [];

        $tables = $config['tables'] ?? [];
        if (is_array($tables) && $tables !== []) {
            $tables = $this->normalizeTables($tables);
        } else {
            $tables = $this->buildTablesFromDefinitions();
        }
        $this->tables = $tables;
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
     * @param PDO   $connection
     * @param string $tableName
     * @param array<string,mixed> $row
     */
    public function upsert(PDO $connection, string $tableName, array $row): void
    {
        if ($row === []) {
            return;
        }

        $tableConfig = $this->getTableConfig($tableName);
        $keys = $this->extractUniqueKeys($tableConfig);
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
        if (!isset($this->tables[$tableName])) {
            throw new RuntimeException(sprintf('Tabelle "%s" ist nicht im Mapping definiert.', $tableName));
        }
        return $this->tables[$tableName];
    }

    /**
     * @param string $tableName
     * @return array<int,string>
     */
    public function getFields(string $tableName): array
    {
        $config = $this->getTableConfig($tableName);
        return $config['fields'] ?? [];
    }

    /**
     * @param string $tableName
     * @param array<int,string> $columns
     */
    public function generateUpsertSql(string $tableName, array $columns): string
    {
        $config = $this->getTableConfig($tableName);
        $keys = $this->extractUniqueKeys($config);
        if ($keys === []) {
            throw new RuntimeException(sprintf('Keine eindeutigen Schlüssel für Tabelle "%s".', $tableName));
        }
        $normalized = array_values(array_unique(array_map('strval', $columns)));
        sort($normalized);
        return $this->buildUpsertSql($tableName, $normalized, $keys);
    }

    /**
     * @param string $tableName
     * @param array<int,string> $whereColumns
     */
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

    /**
     * @param string $tableName
     * @return array<int,string>
     */
    public function getUniqueKeyColumns(string $tableName): array
    {
        $config = $this->getTableConfig($tableName);
        return $this->extractUniqueKeys($config);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildTablesFromDefinitions(): array
    {
        $tables = [];

        foreach ($this->entities as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $tableName = (string)($definition['table'] ?? $name);
            $tables[$tableName] = $this->normalizeTableConfig($definition);
        }

        foreach ($this->relationships as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $tableName = (string)($definition['table'] ?? $name);
            $tables[$tableName] = $this->normalizeTableConfig($definition);
        }

        if (is_array($this->config['tables'] ?? null)) {
            foreach ($this->config['tables'] as $name => $tableDef) {
                if (!isset($tables[$name]) && is_array($tableDef)) {
                    $tables[(string)$name] = $this->normalizeTableConfig($tableDef);
                }
            }
        }

        return $tables;
    }

    /**
     * @param array<string,mixed> $tables
     * @return array<string,array<string,mixed>>
     */
    private function normalizeTables(array $tables): array
    {
        $normalized = [];
        foreach ($tables as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $normalized[(string)$name] = $this->normalizeTableConfig($definition);
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function normalizeTableConfig(array $definition): array
    {
        $normalized = $this->normalizeDefinition($definition);
        return array_replace($definition, $normalized);
    }

    /**
     * @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function normalizeDefinition(array $definition): array
    {
        $fields = $this->extractFieldNames($definition['fields'] ?? []);
        $businessKey = $this->normalizeList($definition['business_key'] ?? []);
        $primaryKey = $this->normalizeList($definition['primary_key'] ?? []);
        $rawKeys = $definition['keys'] ?? [];
        $uniqueConstraint = $this->normalizeList($definition['unique_constraint'] ?? $rawKeys);
        $explicitUnique = $this->normalizeList($definition['explicit_unique'] ?? $definition['unique_key'] ?? $rawKeys);
        $legacyKeys = $this->normalizeList($definition['legacy_keys'] ?? $rawKeys);

        return [
            'fields' => $fields,
            'business_key' => $businessKey,
            'primary_key' => $primaryKey,
            'unique_constraint' => $uniqueConstraint,
            'explicit_unique' => $explicitUnique,
            'legacy_keys' => $legacyKeys,
            'definition' => $definition,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeList($value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $result[] = $item;
                }
            }
            return array_values(array_unique($result));
        }
        $value = trim((string)$value);
        return $value === '' ? [] : [$value];
    }

    /**
     * @param mixed $definition
     * @return array<int,string>
     */
    private function extractFieldNames($definition): array
    {
        if (!is_array($definition) || $definition === []) {
            return [];
        }
        if ($this->isAssoc($definition)) {
            return array_values(array_map('strval', array_keys($definition)));
        }
        return array_values(array_map('strval', $definition));
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * @param array<string,mixed> $tableConfig
     * @return array<int,string>
     */
    private function extractUniqueKeys(array $tableConfig): array
    {
        $candidates = [
            $tableConfig['explicit_unique'] ?? [],
            $tableConfig['business_key'] ?? [],
            $tableConfig['unique_constraint'] ?? [],
            $tableConfig['legacy_keys'] ?? [],
            $tableConfig['primary_key'] ?? [],
            $tableConfig['definition']['unique_key'] ?? [],
            $tableConfig['definition']['business_key'] ?? [],
            $tableConfig['definition']['unique_constraint'] ?? [],
            $tableConfig['definition']['keys'] ?? [],
        ];

        foreach ($candidates as $candidate) {
            $list = $this->normalizeList($candidate);
            if ($list !== []) {
                return $list;
            }
        }

        return [];
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,string> $keys
     */
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
