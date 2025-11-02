<?php
declare(strict_types=1);

class SetupService
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function run(array $config): array
    {
        $root = dirname(__DIR__, 2);
        $scriptsDir = $root . '/scripts';
        $dbDir = $config['paths']['db_dir'] ?? ($root . '/db');
        if (!is_dir($dbDir) && !@mkdir($dbDir, 0777, true) && !is_dir($dbDir)) {
            throw new AFS_DatabaseException("Konnte Datenbankverzeichnis nicht anlegen: {$dbDir}");
        }
        $summary = [];
        // status.db
        $statusPath = $config['paths']['status_db'] ?? ($dbDir . '/status.db');
        $statusSql  = $scriptsDir . '/create_status.sql';
        if (!is_file($statusSql)) {
            throw new AFS_ConfigurationException("SQL-Datei nicht gefunden: {$statusSql}");
        }
        $dir = dirname($statusPath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new AFS_DatabaseException("Konnte Verzeichnis nicht anlegen: {$dir}");
        }
        $statusWasPresent = is_file($statusPath);
        $pdoStatus = new PDO('sqlite:' . $statusPath);
        $pdoStatus->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoStatus->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $initStatusSql = (string)file_get_contents($statusSql);
        if (trim($initStatusSql) === '') {
            throw new AFS_ConfigurationException("SQL-Datei ist leer oder nicht lesbar: {$statusSql}");
        }
        $pdoStatus->exec($initStatusSql);
        if (!$statusWasPresent) {
            @chmod($statusPath, 0666);
        }
        $summary['status'] = ['path' => $statusPath, 'created' => !$statusWasPresent];
        // evo.db
        $evoPath = $config['paths']['data_db'] ?? ($dbDir . '/evo.db');
        $evoYaml = $root . '/schemas/evo.yml';
        if (!is_file($evoYaml)) {
            $legacyYaml = $root . '/mappings/evo.yml';
            if (is_file($legacyYaml)) {
                $evoYaml = $legacyYaml;
            }
        }
        $evoSqlFallback = $scriptsDir . '/create_evo.sql';
        $dir = dirname($evoPath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new AFS_DatabaseException("Konnte Verzeichnis nicht anlegen: {$dir}");
        }
        $evoWasPresent = is_file($evoPath);
        $pdoEvo = new PDO('sqlite:' . $evoPath);
        $pdoEvo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoEvo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (is_file($evoYaml)) {
            $yaml = YamlMappingLoader::load($evoYaml);
            $result = $this->applyEvoSchemaFromYaml($pdoEvo, $yaml);
        } else {
            if (!is_file($evoSqlFallback)) {
                throw new AFS_ConfigurationException("Weder schemas/evo.yml noch SQL-Datei gefunden: {$evoSqlFallback}");
            }
            $initEvoSql = (string)file_get_contents($evoSqlFallback);
            if (trim($initEvoSql) === '') {
                throw new AFS_ConfigurationException("SQL-Datei ist leer oder nicht lesbar: {$evoSqlFallback}");
            }
            $pdoEvo->exec($initEvoSql);
            $result = ['created' => !$evoWasPresent, 'tables_created' => []];
        }
        if (!$evoWasPresent) {
            @chmod($evoPath, 0666);
        }
        $summary['evo'] = array_merge(['path' => $evoPath, 'created' => !$evoWasPresent], is_array($result) ? $result : []);
        return $summary;
    }

    /**
     * @param array<string,mixed> $yaml
     * @return array<string,mixed>
     */
    private function applyEvoSchemaFromYaml(PDO $pdo, array $yaml): array
    {
        // Minimaler Durchlauf über entities/relationships -> definitions
        $definitions = [];
        foreach (['entities', 'relationships'] as $section) {
            $items = $yaml[$section] ?? [];
            if (!is_array($items)) continue;
            foreach ($items as $name => $definition) {
                if (!is_array($definition)) continue;
                $table = (string)($definition['table'] ?? $name);
                $definitions[$table] = $definition;
            }
        }
        if ($definitions === [] && isset($yaml['tables']) && is_array($yaml['tables'])) {
            foreach ($yaml['tables'] as $tableName => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $fieldsList = $def['fields'] ?? [];
                $fields = [];
                if (is_array($fieldsList)) {
                    $isSequential = array_keys($fieldsList) === range(0, count($fieldsList) - 1);
                    if ($isSequential) {
                        foreach ($fieldsList as $fieldName) {
                            $fieldName = trim((string)$fieldName);
                            if ($fieldName === '') {
                                continue;
                            }
                            $fields[$fieldName] = [];
                        }
                    } else {
                        foreach ($fieldsList as $fieldName => $fieldDef) {
                            $fieldName = trim((string)$fieldName);
                            if ($fieldName === '') {
                                continue;
                            }
                            $fields[$fieldName] = is_array($fieldDef) ? $fieldDef : [];
                        }
                    }
                }
                $definitions[(string)$tableName] = [
                    'fields' => $fields,
                    'primary_key' => $def['primary_key'] ?? [],
                    'business_key' => $def['business_key'] ?? [],
                    'unique_constraint' => $def['unique_constraint'] ?? ($def['keys'] ?? []),
                    'explicit_unique' => $def['explicit_unique'] ?? [],
                    'foreign_keys' => $def['foreign_keys'] ?? [],
                ];
            }
        }
        $created = [];
        $altered = [];
        foreach ($definitions as $table => $definition) {
            $fields = $definition['fields'] ?? [];
            if (!is_array($fields) || $fields === []) continue;
            if (!$this->tableExists($pdo, $table)) {
                $this->createTableFromDefinition($pdo, $table, $definition);
                $created[] = $table;
            } else {
                $added = $this->addMissingColumnsFromDefinition($pdo, $table, $definition);
                if ($added) $altered[$table] = $added;
                $this->ensureUniqueIndexes($pdo, $table, $definition);
            }
        }
        return ['tables_created' => $created, 'columns_added' => $altered];
    }

    private function quoteIdent(string $ident): string { return '"' . str_replace('"', '""', $ident) . '"'; }
    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT name FROM sqlite_master WHERE type = "table" AND name = ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
    private function createTableFromDefinition(PDO $pdo, string $table, array $definition): void
    {
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields)) return;
        $primaryKey = $this->normalizeList($definition['primary_key'] ?? []);
        $uniqueConstraint = $this->normalizeList($definition['unique_constraint'] ?? []);
        $foreignKeys = $definition['foreign_keys'] ?? [];
        $columnParts = [];
        $autoPrimaryColumns = [];
        foreach ($fields as $columnName => $columnDef) {
            if (is_int($columnName)) { $columnName = (string)$columnDef; $columnDef = []; }
            if (!is_array($columnDef)) { $columnDef = []; }
            $columnParts[] = $this->buildColumnDefinitionSql($columnName, $columnDef, $primaryKey, true, $autoPrimaryColumns, false);
        }
        $constraints = [];
        if ($primaryKey && empty($autoPrimaryColumns)) {
            $constraints[] = 'PRIMARY KEY (' . implode(', ', array_map([$this,'quoteIdent'], $primaryKey)) . ')';
        }
        if ($uniqueConstraint) {
            $constraints[] = 'UNIQUE (' . implode(', ', array_map([$this,'quoteIdent'], $uniqueConstraint)) . ')';
        }
        if (is_array($foreignKeys)) {
            foreach ($foreignKeys as $fk) {
                if (!is_array($fk)) continue;
                $columns = $this->normalizeList($fk['column'] ?? $fk['columns'] ?? []);
                $references = (string)($fk['references'] ?? '');
                if ($columns === [] || $references === '' || !str_contains($references, '.')) continue;
                [$refTable, $refColumn] = array_map('trim', explode('.', $references, 2));
                if ($refTable === '' || $refColumn === '') continue;
                $constraint = sprintf(
                    'FOREIGN KEY (%s) REFERENCES %s(%s)',
                    implode(', ', array_map([$this,'quoteIdent'], $columns)),
                    $this->quoteIdent($refTable),
                    $this->quoteIdent($refColumn)
                );
                if (!empty($fk['on_delete'])) { $constraint .= ' ON DELETE ' . strtoupper((string)$fk['on_delete']); }
                if (!empty($fk['on_update'])) { $constraint .= ' ON UPDATE ' . strtoupper((string)$fk['on_update']); }
                $constraints[] = $constraint;
            }
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $this->quoteIdent($table) . ' (' . implode(', ', array_merge($columnParts, $constraints)) . ')';
        $pdo->exec($sql);
        $this->ensureUniqueIndexes($pdo, $table, $definition);
    }
    private function addMissingColumnsFromDefinition(PDO $pdo, string $table, array $definition): array
    {
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields) || $fields === []) return [];
        $existing = $this->currentColumns($pdo, $table);
        $primaryKey = $this->normalizeList($definition['primary_key'] ?? []);
        $added = [];
        foreach ($fields as $columnName => $columnDef) {
            if (is_int($columnName)) { $columnName = (string)$columnDef; $columnDef = []; }
            if (isset($existing[$columnName])) continue;
            if (!is_array($columnDef)) { $columnDef = []; }
            $autoPkCollector = [];
            $sql = $this->buildColumnDefinitionSql($columnName, $columnDef, $primaryKey, false, $autoPkCollector, true);
            $pdo->exec('ALTER TABLE ' . $this->quoteIdent($table) . ' ADD COLUMN ' . $sql);
            $added[] = $columnName;
        }
        $this->ensureUniqueIndexes($pdo, $table, $definition);
        return $added;
    }
    private function currentColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $this->quoteIdent($table) . ')');
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name !== '') $cols[$name] = true;
        }
        return $cols;
    }
    private function normalizeList($value): array
    {
        if ($value === null) return [];
        if (is_array($value)) { $out = []; foreach ($value as $v) { $v = trim((string)$v); if ($v !== '') $out[] = $v; } return array_values(array_unique($out)); }
        $value = trim((string)$value); return $value === '' ? [] : [$value];
    }
    private function mapSqliteType(?string $type): string
    {
        $type = strtolower($type ?? '');
        return match ($type) {
            'int','integer','smallint','bigint','tinyint' => 'INTEGER',
            'real','float','double','decimal','numeric' => 'REAL',
            'bool','boolean' => 'INTEGER',
            'blob' => 'BLOB',
            default => 'TEXT',
        };
    }
    private function buildColumnDefinitionSql(string $name, array $def, array $pk, bool $allowAutoPk, array &$autoPk, bool $forAlter): string
    {
        $auto = (bool)($def['auto_increment'] ?? false);
        $type = $this->mapSqliteType($def['type'] ?? null);
        $isPk = in_array($name, $pk, true);
        $parts = [$this->quoteIdent($name), $type];
        if ($auto && $allowAutoPk && $isPk && $type === 'INTEGER') {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
            $autoPk[] = $name;
        }
        if (!empty($def['not_null'])) $parts[] = 'NOT NULL';
        if (array_key_exists('default', $def)) $parts[] = 'DEFAULT ' . (is_numeric($def['default']) ? (string)$def['default'] : ('"' . str_replace('"','""',(string)$def['default']) . '"'));
        if (!$forAlter && !empty($def['unique'])) $parts[] = 'UNIQUE';
        return implode(' ', $parts);
    }
    private function ensureUniqueIndexes(PDO $pdo, string $table, array $definition): void
    {
        $seen = [];
        $lists = [
            $definition['unique_constraint'] ?? [],
            $definition['explicit_unique'] ?? [],
        ];
        foreach ($lists as $candidate) {
            $columns = $this->normalizeList($candidate);
            foreach ($columns as $col) {
                $key = strtolower($col);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $idx = 'uniq_' . strtolower($table . '_' . $col);
                $pdo->exec(
                    'CREATE UNIQUE INDEX IF NOT EXISTS '
                    . $this->quoteIdent($idx)
                    . ' ON '
                    . $this->quoteIdent($table)
                    . ' (' . $this->quoteIdent($col) . ')'
                );
            }
        }
    }
}
