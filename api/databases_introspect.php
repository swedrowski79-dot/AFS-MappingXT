<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_database_utils.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_error('Nur POST-Anfragen sind erlaubt.', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    api_error('Ungültige Eingabe.', 400);
}

$table = trim((string)($payload['table'] ?? ''));
if ($table === '') {
    api_error('Tabellenname fehlt.', 400);
}

$connection = null;

try {
    if (!empty($payload['connection']) && is_array($payload['connection'])) {
        $connection = $payload['connection'];
    } else {
        $connectionId = (string)($payload['id'] ?? '');
        if ($connectionId === '') {
            api_error('Keine Verbindung angegeben.', 400);
        }
        $config = DatabaseConfig::load();
        foreach ($config['connections'] as $candidate) {
            if (($candidate['id'] ?? '') === $connectionId) {
                $connection = $candidate;
                break;
            }
        }
        if ($connection === null) {
            api_error('Verbindung nicht gefunden.', 404);
        }
    }

    $type = (string)($connection['type'] ?? '');
    $settings = $connection['settings'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }

    switch ($type) {
        case 'mysql':
            $columns = introspectMysqlTable($settings, $table);
            break;
        case 'sqlite':
            $columns = introspectSqliteTable($settings, $table);
            break;
        case 'mssql':
            $columns = introspectMssqlTable($settings, $table);
            break;
        default:
            api_error('Introspektion für Typ ' . $type . ' nicht implementiert.', 400);
    }

    api_ok(['columns' => $columns]);
} catch (Throwable $e) {
    api_error('Fehler bei der Introspektion: ' . $e->getMessage(), 500);
}

/**
 * @param array<string,mixed> $settings
 * @return array<int,array<string,mixed>>
 */
function introspectMysqlTable(array $settings, string $table): array
{
    if (!function_exists('mysqli_connect')) {
        throw new RuntimeException('mysqli-Erweiterung nicht verfügbar');
    }

    $host = (string)($settings['host'] ?? '');
    $database = (string)($settings['database'] ?? '');
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $port = (int)($settings['port'] ?? 3306);

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Unvollständige MySQL-Konfiguration');
    }

    $mysqli = @mysqli_connect($host, $username, $password, $database, $port);
    if (!$mysqli) {
        throw new RuntimeException('Verbindung fehlgeschlagen: ' . mysqli_connect_error());
    }

    $tableEscaped = '`' . str_replace('`', '``', $table) . '`';
    $res = mysqli_query($mysqli, 'SHOW COLUMNS FROM ' . $tableEscaped);
    if (!$res) {
        $error = mysqli_error($mysqli);
        mysqli_close($mysqli);
        throw new RuntimeException('SHOW COLUMNS fehlgeschlagen: ' . $error);
    }

    $columns = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $columns[] = [
            'name' => $row['Field'] ?? '',
            'type' => $row['Type'] ?? '',
            'nullable' => (($row['Null'] ?? '') === 'YES'),
            'default' => $row['Default'] ?? null,
            'extra' => $row['Extra'] ?? '',
            'key' => $row['Key'] ?? '',
        ];
    }
    mysqli_free_result($res);
    mysqli_close($mysqli);
    return $columns;
}

/**
 * @param array<string,mixed> $settings
 * @return array<int,array<string,mixed>>
 */
function introspectSqliteTable(array $settings, string $table): array
{
    $path = (string)($settings['path'] ?? '');
    if ($path === '') {
        throw new RuntimeException('Pfad nicht definiert');
    }
    $absolutePath = resolvePath($path);
    if (!is_file($absolutePath)) {
        throw new RuntimeException('SQLite-Datei nicht gefunden: ' . $absolutePath);
    }
    $pdo = new PDO('sqlite:' . $absolutePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare('PRAGMA table_info(' . quoteSqliteIdentifier($table) . ')');
    if (!$stmt->execute()) {
        throw new RuntimeException('PRAGMA table_info fehlgeschlagen.');
    }
    $columns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = [
            'name' => $row['name'] ?? '',
            'type' => $row['type'] ?? '',
            'nullable' => ((int)($row['notnull'] ?? 1) === 0),
            'default' => $row['dflt_value'] ?? null,
            'primary' => ((int)($row['pk'] ?? 0) === 1),
        ];
    }
    return $columns;
}

/**
 * @param array<string,mixed> $settings
 * @return array<int,array<string,mixed>>
 */
function introspectMssqlTable(array $settings, string $table): array
{
    if (!function_exists('sqlsrv_connect')) {
        throw new RuntimeException('sqlsrv-Erweiterung nicht verfügbar');
    }
    $host = (string)($settings['host'] ?? '');
    $database = (string)($settings['database'] ?? '');
    $username = (string)($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');
    $port = (int)($settings['port'] ?? 1433);

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Unvollständige MSSQL-Konfiguration');
    }

    $server = $host . ',' . $port;
    $connInfo = [
        'Database' => $database,
        'UID' => $username,
        'PWD' => $password,
    ];
    $conn = sqlsrv_connect($server, $connInfo);
    if (!$conn) {
        throw new RuntimeException('MSSQL-Verbindung fehlgeschlagen');
    }

    $stmt = sqlsrv_query(
        $conn,
        'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
         FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$table]
    );
    if ($stmt === false) {
        sqlsrv_close($conn);
        throw new RuntimeException('Spalten konnten nicht ermittelt werden.');
    }
    $columns = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $columns[] = [
            'name' => $row['COLUMN_NAME'] ?? '',
            'type' => $row['DATA_TYPE'] ?? '',
            'nullable' => (($row['IS_NULLABLE'] ?? '') === 'YES'),
            'max_length' => $row['CHARACTER_MAXIMUM_LENGTH'] ?? null,
            'precision' => $row['NUMERIC_PRECISION'] ?? null,
            'scale' => $row['NUMERIC_SCALE'] ?? null,
        ];
    }
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
    return $columns;
}

/**
 * @return string
 */
function resolvePath(string $path): string
{
    if ($path === '') {
        return $path;
    }
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
        return $path;
    }
    $base = dirname(__DIR__);
    return $base . '/' . ltrim($path, '/');
}

function quoteSqliteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}
