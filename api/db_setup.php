<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    $service = new SetupService();
    $result = $service->run($config);

    try {
        $tracker = createStatusTracker($config, 'categories');
        $tracker->logInfo('Setup-Skript ausgeführt', $result, 'maintenance');
    } catch (\Throwable $e) {
        // Tracker-Fehler sollen das Ergebnis nicht verhindern
    }

    api_ok(['databases' => $result]);
} catch (\Throwable $e) {
    api_error($e->getMessage());
}

/* legacy code removed after refactor */
/* legacy */ function _legacy_runSetup(array $config): array
{
    $root = dirname(__DIR__);
    $scriptsDir = $root . '/scripts';
    $dbDir = $config['paths']['db_dir'] ?? ($root . '/db');

    if (!is_dir($dbDir) && !@mkdir($dbDir, 0777, true) && !is_dir($dbDir)) {
        throw new AFS_DatabaseException("Konnte Datenbankverzeichnis nicht anlegen: {$dbDir}");
    }

    $summary = [];

    // 1) status.db weiterhin klassisch per SQL-Datei anlegen/aktualisieren
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
    $initStatusSql = file_get_contents($statusSql);
    if ($initStatusSql === false || trim($initStatusSql) === '') {
        throw new AFS_ConfigurationException("SQL-Datei ist leer oder nicht lesbar: {$statusSql}");
    }
    $pdoStatus->exec($initStatusSql);
    $summary['status'] = [
        'path' => $statusPath,
        'created' => !$statusWasPresent,
    ];

    // 2) evo.db: bevorzugt aus schemas/evo.yml erzeugen/aktualisieren
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

    $result = null;
    if (is_file($evoYaml)) {
        // YAML einlesen und Schema anwenden
        $yaml = YamlMappingLoader::load($evoYaml);
        $result = applyEvoSchemaFromYaml($pdoEvo, $yaml);
    } else {
        if (!is_file($evoSqlFallback)) {
            throw new AFS_ConfigurationException("Weder schemas/evo.yml noch SQL-Datei gefunden: {$evoSqlFallback}");
        }
        $initEvoSql = file_get_contents($evoSqlFallback);
        if ($initEvoSql === false || trim($initEvoSql) === '') {
            throw new AFS_ConfigurationException("SQL-Datei ist leer oder nicht lesbar: {$evoSqlFallback}");
        }
        $pdoEvo->exec($initEvoSql);
        $result = ['created' => !$evoWasPresent, 'tables_created' => []];
    }

    $summary['evo'] = array_merge([
        'path' => $evoPath,
        'created' => !$evoWasPresent,
    ], is_array($result) ? $result : []);

    return $summary;
}

/**
 * Wendet das Schema aus einer evo.yml an (Tabellen anlegen, fehlende Spalten ergänzen, Unique-Indices).
 *
 * @param array<string,mixed> $yaml
 * @return array<string,mixed>
 */
function applyEvoSchemaFromYaml(PDO $pdo, array $yaml): array
{
    $definitions = [];

    $entities = $yaml['entities'] ?? [];
    if (is_array($entities)) {
        foreach ($entities as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $table = (string)($definition['table'] ?? $name);
            $definitions[$table] = $definition;
        }
    }

    $relationships = $yaml['relationships'] ?? [];
    if (is_array($relationships)) {
        foreach ($relationships as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $table = (string)($definition['table'] ?? $name);
            $definitions[$table] = $definition;
        }
    }

    $created = [];
    $altered = [];

    if ($definitions === [] && isset($yaml['tables']) && is_array($yaml['tables'])) {
        foreach ($yaml['tables'] as $tableName => $def) {
            if (!is_array($def)) {
                continue;
            }
            $fieldsList = $def['fields'] ?? [];
            $fields = [];
            if (is_array($fieldsList)) {
                if (array_keys($fieldsList) === range(0, count($fieldsList) - 1)) {
                    foreach ($fieldsList as $fieldName) {
                        $fields[$fieldName] = [];
                    }
                } else {
                    $fields = $fieldsList;
                }
            }
            $definitions[$tableName] = [
                'fields' => $fields,
                'primary_key' => [],
                'business_key' => normalizeList($def['business_key'] ?? []),
                'unique_constraint' => normalizeList($def['keys'] ?? []),
            ];
        }
    }

    foreach ($definitions as $table => $definition) {
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            continue;
        }

        if (!tableExists($pdo, $table)) {
            createTableFromDefinition($pdo, $table, $definition);
            $created[] = $table;
        } else {
            $added = addMissingColumnsFromDefinition($pdo, $table, $definition);
            if ($added) {
                $altered[$table] = $added;
            }
            ensureUniqueIndexes($pdo, $table, $definition);
        }
    }

    return [
        'tables_created' => $created,
        'columns_added' => $altered,
    ];
}

function quoteIdent(string $ident): string
{
    return '"' . str_replace('"', '""', $ident) . '"';
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT name FROM sqlite_master WHERE type = "table" AND name = ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Erzeugt eine Tabelle aus Spaltenliste und PK/BK.
 * Datentypen: INTEGER für ID und *_ID, sonst TEXT (konservativ).
 */
function createTableFromDefinition(PDO $pdo, string $table, array $definition): void
{
    $fields = $definition['fields'] ?? [];
    if (!is_array($fields)) {
        return;
    }

    $primaryKey = normalizeList($definition['primary_key'] ?? []);
    $businessKey = normalizeList($definition['business_key'] ?? $definition['unique_key'] ?? []);
    $uniqueConstraint = normalizeList($definition['unique_constraint'] ?? []);
    $foreignKeys = $definition['foreign_keys'] ?? [];

    $columnParts = [];
    $autoPrimaryColumns = [];

    foreach ($fields as $columnName => $columnDef) {
        if (is_int($columnName)) {
            $columnName = (string)$columnDef;
            $columnDef = [];
        }
        if (!is_array($columnDef)) {
            $columnDef = [];
        }
        $columnParts[] = buildColumnDefinitionSql($columnName, $columnDef, $primaryKey, true, $autoPrimaryColumns, false);
    }

    $constraints = [];

    if ($primaryKey && empty($autoPrimaryColumns)) {
        $constraints[] = 'PRIMARY KEY (' . implode(', ', array_map('quoteIdent', $primaryKey)) . ')';
    }

    if ($uniqueConstraint) {
        $constraints[] = 'UNIQUE (' . implode(', ', array_map('quoteIdent', $uniqueConstraint)) . ')';
    }

    if (is_array($foreignKeys)) {
        foreach ($foreignKeys as $fk) {
            if (!is_array($fk)) {
                continue;
            }
            $columns = normalizeList($fk['column'] ?? $fk['columns'] ?? []);
            $references = (string)($fk['references'] ?? '');
            if ($columns === [] || $references === '') {
                continue;
            }
            if (!str_contains($references, '.')) {
                continue;
            }
            [$refTable, $refColumn] = array_map('trim', explode('.', $references, 2));
            if ($refTable === '' || $refColumn === '') {
                continue;
            }
            $constraint = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s(%s)',
                implode(', ', array_map('quoteIdent', $columns)),
                quoteIdent($refTable),
                quoteIdent($refColumn)
            );
            if (!empty($fk['on_delete'])) {
                $constraint .= ' ON DELETE ' . strtoupper((string)$fk['on_delete']);
            }
            if (!empty($fk['on_update'])) {
                $constraint .= ' ON UPDATE ' . strtoupper((string)$fk['on_update']);
            }
            $constraints[] = $constraint;
        }
    }

    $sql = 'CREATE TABLE IF NOT EXISTS ' . quoteIdent($table) . ' ('
        . implode(', ', array_merge($columnParts, $constraints)) . ')';
    $pdo->exec($sql);

    ensureUniqueIndexes($pdo, $table, $definition);
}

function addMissingColumnsFromDefinition(PDO $pdo, string $table, array $definition): array
{
    $fields = $definition['fields'] ?? [];
    if (!is_array($fields) || $fields === []) {
        return [];
    }

    $existing = currentColumns($pdo, $table);
    $primaryKey = normalizeList($definition['primary_key'] ?? []);
    $added = [];

    foreach ($fields as $columnName => $columnDef) {
        if (is_int($columnName)) {
            $columnName = (string)$columnDef;
            $columnDef = [];
        }
        if (isset($existing[$columnName])) {
            continue;
        }
        if (!is_array($columnDef)) {
            $columnDef = [];
        }
        $sql = buildColumnDefinitionSql($columnName, $columnDef, $primaryKey, false, $tmp = [], true);
        $pdo->exec('ALTER TABLE ' . quoteIdent($table) . ' ADD COLUMN ' . $sql);
        $added[] = $columnName;
    }

    ensureUniqueIndexes($pdo, $table, $definition);

    return $added;
}

/**
 * Liefert aktuelle Spaltennamen der Tabelle (case-sensitiv wie gemeldet).
 * @return array<string,bool>
 */
function currentColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('PRAGMA table_info(' . quoteIdent($table) . ')');
    $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name !== '') $cols[$name] = true;
    }
    return $cols;
}

function normalizeList($value): array
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

function mapSqliteType(?string $type): string
{
    $type = strtolower($type ?? '');
    return match ($type) {
        'int', 'integer', 'smallint', 'bigint', 'tinyint' => 'INTEGER',
        'real', 'float', 'double', 'decimal', 'numeric' => 'REAL',
        'bool', 'boolean' => 'INTEGER',
        'blob' => 'BLOB',
        default => 'TEXT',
    };
}

function formatDefaultValue($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $str = (string)$value;
    return "'" . str_replace("'", "''", $str) . "'";
}

function buildColumnDefinitionSql(string $column, array $definition, array $primaryKey, bool $allowPrimaryClause, array &$autoPrimaryColumns, bool $forAlter): string
{
    $type = mapSqliteType($definition['type'] ?? null);
    $parts = [quoteIdent($column), $type];

    $autoIncrement = !empty($definition['auto_increment']);
    if ($autoIncrement && !$forAlter) {
        $parts[] = 'PRIMARY KEY AUTOINCREMENT';
        $autoPrimaryColumns[] = $column;
    } elseif (!$forAlter && $allowPrimaryClause && in_array($column, $primaryKey, true) && count($primaryKey) === 1) {
        $parts[] = 'PRIMARY KEY';
    }

    $nullable = true;
    if (array_key_exists('nullable', $definition)) {
        $nullable = (bool)$definition['nullable'];
    } elseif (array_key_exists('required', $definition)) {
        $nullable = !(bool)$definition['required'];
    }

    if (!$nullable && !$autoIncrement && !$forAlter) {
        $parts[] = 'NOT NULL';
    }

    if (array_key_exists('default', $definition)) {
        $parts[] = 'DEFAULT ' . formatDefaultValue($definition['default']);
    }

    return implode(' ', array_unique($parts));
}

function ensureUniqueIndexes(PDO $pdo, string $table, array $definition): void
{
    $businessKey = normalizeList($definition['business_key'] ?? $definition['unique_key'] ?? []);
    if ($businessKey) {
        ensureUniqueIndex($pdo, $table, $businessKey);
    }

    $uniqueConstraint = normalizeList($definition['unique_constraint'] ?? []);
    if ($uniqueConstraint) {
        ensureUniqueIndex($pdo, $table, $uniqueConstraint);
    }
}

function ensureUniqueIndex(PDO $pdo, string $table, array $columns): void
{
    $name = 'ux_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $table . '_' . implode('_', $columns));
    $sql = 'CREATE UNIQUE INDEX IF NOT EXISTS ' . quoteIdent($name) . ' ON ' . quoteIdent($table) . ' (' . implode(', ', array_map('quoteIdent', $columns)) . ')';
    $pdo->exec($sql);
}
