<?php
declare(strict_types=1);

$root = dirname(__DIR__);

if (function_exists('set_time_limit')) {
    set_time_limit(1200); // 20 Minuten für lange Sync-Läufe
}
@ini_set('max_execution_time', '1200');
$configFile = $root . '/config.php';
$autoloadFile = $root . '/autoload.php';

if (!is_file($configFile) || !is_file($autoloadFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'System nicht korrekt eingerichtet.']);
    exit;
}

$config = require $configFile;
require_once $autoloadFile;

function api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 500): void
{
    api_json(['ok' => false, 'error' => $message], $status);
}

function api_ok($data = null): void
{
    api_json(['ok' => true, 'data' => $data ?? []]);
}

function createStatusTracker(array $config, string $job = 'categories'): AFS_Evo_StatusTracker
{
    $statusDb = $config['paths']['status_db'] ?? (dirname(__DIR__) . '/db/status.db');
    if (!is_file($statusDb)) {
        throw new RuntimeException("status.db nicht gefunden: {$statusDb}");
    }
    $maxErrors = $config['status']['max_errors'] ?? 200;
    return new AFS_Evo_StatusTracker($statusDb, $job, (int)$maxErrors);
}

function createEvoPdo(array $config): PDO
{
    $dataDb = $config['paths']['data_db'] ?? (dirname(__DIR__) . '/db/evo.db');
    if (!is_file($dataDb)) {
        throw new RuntimeException("evo.db nicht gefunden: {$dataDb}");
    }
    $pdo = new PDO('sqlite:' . $dataDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function createEvoDeltaPdo(array $config): PDO
{
    $deltaDb = $config['paths']['delta_db'] ?? (dirname(__DIR__) . '/db/evo_delta.db');
    if (!is_file($deltaDb)) {
        throw new RuntimeException("Delta-Datenbank nicht gefunden: {$deltaDb}");
    }
    $pdo = new PDO('sqlite:' . $deltaDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function createStatusPdo(array $config): PDO
{
    $statusDb = $config['paths']['status_db'] ?? (dirname(__DIR__) . '/db/status.db');
    if (!is_file($statusDb)) {
        throw new RuntimeException("status.db nicht gefunden: {$statusDb}");
    }
    $pdo = new PDO('sqlite:' . $statusDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function createMssql(array $config): MSSQL
{
    $mssqlCfg = $config['mssql'] ?? [];
    $host = $mssqlCfg['host'] ?? 'localhost';
    $port = (int)($mssqlCfg['port'] ?? 1433);
    $server = $host . ',' . $port;

    return new MSSQL(
        $server,
        $mssqlCfg['username'] ?? '',
        $mssqlCfg['password'] ?? '',
        $mssqlCfg['database'] ?? '',
        [
            'encrypt' => $mssqlCfg['encrypt'] ?? true,
            'trust_server_certificate' => $mssqlCfg['trust_server_certificate'] ?? false,
            'appname' => $mssqlCfg['appname'] ?? 'AFS-Sync',
        ]
    );
}

function createSyncEnvironment(array $config, string $job = 'categories'): array
{
    $tracker = createStatusTracker($config, $job);
    $status = $tracker->getStatus();
    if (($status['state'] ?? '') === 'running') {
        throw new AFS_SyncBusyException('Synchronisation läuft bereits. Bitte warten.');
    }
    $pdo = createEvoPdo($config);
    $mssql = createMssql($config);
    try {
        $mssql->scalar('SELECT 1');
    } catch (Throwable $e) {
        $mssql->close();
        throw new RuntimeException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
    }
    $dataSource = new AFS_Get_Data($mssql);
    $afs = new AFS($dataSource, $config);
    $evo = new AFS_Evo($pdo, $afs, $tracker, $config);

    return [$tracker, $evo, $mssql];
}

set_exception_handler(static function (Throwable $e): void {
    api_error($e->getMessage());
});

set_error_handler(static function ($errno, $errstr, $errfile, $errline): void {
    api_error(sprintf('%s in %s:%d', $errstr, $errfile, $errline));
});
