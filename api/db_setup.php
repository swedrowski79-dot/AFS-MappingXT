<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    $result = runSetup($config);

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

/**
 * Erstellt/aktualisiert die SQLite-Datenbanken.
 * Für evo.db wird – falls vorhanden – das Schema aus mappings/evo.yml abgeleitet.
 *
 * @return array<string,mixed>
 */
function runSetup(array $config): array
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

    // 2) evo.db: bevorzugt aus mappings/evo.yml erzeugen/aktualisieren
    $evoPath = $config['paths']['data_db'] ?? ($dbDir . '/evo.db');
    $evoYaml = $root . '/mappings/evo.yml';
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
            throw new AFS_ConfigurationException("Weder mappings/evo.yml noch SQL-Datei gefunden: {$evoSqlFallback}");
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
    $tables = $yaml['tables'] ?? [];
    if (!is_array($tables)) {
        throw new AFS_ConfigurationException('Ungültige evo.yml: Abschnitt "tables" fehlt.');
    }

    $created = [];
    $altered = [];

    foreach ($tables as $tableName => $def) {
        if (!is_array($def)) continue;
        $fields = array_values(array_unique(array_map('strval', $def['fields'] ?? [])));
        $pk = array_values(array_unique(array_map('strval', $def['keys'] ?? [])));
        $bk = array_values(array_unique(array_map('strval', $def['business_key'] ?? [])));

        // Felder um PK/BK erweitern, damit vorhandene Spalten gesichert sind
        $allCols = $fields;
        foreach (array_merge($pk, $bk) as $c) {
            if ($c !== '' && !in_array($c, $allCols, true)) $allCols[] = $c;
        }

        if (!tableExists($pdo, (string)$tableName)) {
            createTableFromDef($pdo, (string)$tableName, $allCols, $pk, $bk);
            $created[] = (string)$tableName;
        } else {
            $added = addMissingColumns($pdo, (string)$tableName, $allCols);
            if ($added) {
                $altered[(string)$tableName] = $added;
            }
            // Sichere Unique-Indizes für business_key, wenn angegeben
            if ($bk) {
                ensureUniqueIndex($pdo, (string)$tableName, $bk);
            }
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
function createTableFromDef(PDO $pdo, string $table, array $columns, array $pk, array $bk): void
{
    $colDefs = [];
    $haveExplicitPk = !empty($pk);

    foreach ($columns as $col) {
        $type = inferType($col, $haveExplicitPk);
        // Wenn kein expliziter PK gesetzt ist und die Spalte genau "ID" heißt, Primärschlüssel verwenden
        if (!$haveExplicitPk && strcasecmp($col, 'ID') === 0) {
            $colDefs[] = quoteIdent($col) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        } else {
            $colDefs[] = quoteIdent($col) . ' ' . $type;
        }
    }

    $pkSql = '';
    if ($haveExplicitPk) {
        $pkQuoted = array_map('quoteIdent', $pk);
        $pkSql = ', PRIMARY KEY (' . implode(', ', $pkQuoted) . ')';
    }

    $sql = 'CREATE TABLE IF NOT EXISTS ' . quoteIdent($table) . ' (' . implode(', ', $colDefs) . $pkSql . ')';
    $pdo->exec($sql);

    // Business Key als Unique-Index absichern (falls vorhanden)
    if ($bk) {
        ensureUniqueIndex($pdo, $table, $bk);
    }
}

/**
 * Ergänzt fehlende Spalten (TEXT/INTEGER konservativ), PK wird nicht verändert.
 *
 * @return array<int,string> hinzugefügte Spaltennamen
 */
function addMissingColumns(PDO $pdo, string $table, array $columns): array
{
    $existing = currentColumns($pdo, $table);
    $added = [];
    foreach ($columns as $col) {
        if (!isset($existing[$col])) {
            $type = inferType($col, true);
            $pdo->exec('ALTER TABLE ' . quoteIdent($table) . ' ADD COLUMN ' . quoteIdent($col) . ' ' . $type);
            $added[] = $col;
        }
    }
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

function inferType(string $column, bool $hasExplicitPk): string
{
    $c = strtolower($column);
    if ($c === 'id' && !$hasExplicitPk) return 'INTEGER';
    if (str_ends_with($c, '_id')) return 'INTEGER';
    if (preg_match('/^(menge|anzahl|preis|rabatt|gewicht|betrag|nummer|laenge|breite|hoehe)$/i', $column)) return 'INTEGER';
    return 'TEXT';
}

function ensureUniqueIndex(PDO $pdo, string $table, array $columns): void
{
    $name = 'ux_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $table . '_' . implode('_', $columns));
    $sql = 'CREATE UNIQUE INDEX IF NOT EXISTS ' . quoteIdent($name) . ' ON ' . quoteIdent($table) . ' (' . implode(', ', array_map('quoteIdent', $columns)) . ')';
    $pdo->exec($sql);
}
