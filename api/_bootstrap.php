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
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header_remove('X-Powered-By');
    header_remove('Server');
    echo json_encode(['ok' => false, 'error' => 'System nicht korrekt eingerichtet.']);
    exit;
}

$config = require $configFile;
require_once $autoloadFile;

function api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    
    // Security headers for API responses
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Remove server signature
    header_remove('X-Powered-By');
    header_remove('Server');
    
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

function createStatusTracker(array $config, string $job = 'categories'): STATUS_Tracker
{
    $statusDb = $config['paths']['status_db'] ?? (dirname(__DIR__) . '/db/status.db');
    if (!is_file($statusDb)) {
        throw new AFS_DatabaseException("status.db nicht gefunden: {$statusDb}");
    }
    $maxErrors = $config['status']['max_errors'] ?? 200;
    $logLevel = $config['logging']['log_level'] ?? 'warning';
    return new STATUS_Tracker($statusDb, $job, (int)$maxErrors, $logLevel);
}

function createEvoPdo(array $config): PDO
{
    $dataDb = $config['paths']['data_db'] ?? (dirname(__DIR__) . '/db/evo.db');
    if (!is_file($dataDb)) {
        throw new AFS_DatabaseException("evo.db nicht gefunden: {$dataDb}");
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
        throw new AFS_DatabaseException("Delta-Datenbank nicht gefunden: {$deltaDb}");
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
        throw new AFS_DatabaseException("status.db nicht gefunden: {$statusDb}");
    }
    $pdo = new PDO('sqlite:' . $statusDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function createMssql(array $config): MSSQL_Connection
{
    $mssqlCfg = $config['mssql'] ?? [];
    $host = $mssqlCfg['host'] ?? 'localhost';
    $port = (int)($mssqlCfg['port'] ?? 1433);
    $server = $host . ',' . $port;

    return new MSSQL_Connection(
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

function createMappingLogger(array $config): ?STATUS_MappingLogger
{
    $loggingConfig = $config['logging'] ?? [];
    $enableFileLogging = $loggingConfig['enable_file_logging'] ?? true;
    
    if (!$enableFileLogging) {
        return null;
    }
    
    $logDir = $config['paths']['log_dir'] ?? (dirname(__DIR__) . '/logs');
    $mappingVersion = $loggingConfig['mapping_version'] ?? '1.0.0';
    $logLevel = $loggingConfig['log_level'] ?? 'warning';
    
    return new STATUS_MappingLogger($logDir, $mappingVersion, $logLevel);
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
    } catch (\Throwable $e) {
        $mssql->close();
        throw new AFS_DatabaseException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
    }
    $dataSource = new AFS_Get_Data($mssql);
    $afs = new AFS($dataSource, $config);
    $logger = createMappingLogger($config);
    $evo = new EVO($pdo, $afs, $tracker, $config, $logger);

    return [$tracker, $evo, $mssql];
}

set_exception_handler(static function (Throwable $e): void {
    api_error($e->getMessage());
});

set_error_handler(static function ($errno, $errstr, $errfile, $errline): void {
    api_error(sprintf('%s in %s:%d', $errstr, $errfile, $errline));
});
