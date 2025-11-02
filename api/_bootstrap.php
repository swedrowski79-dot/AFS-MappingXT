<?php
declare(strict_types=1);

$root = dirname(__DIR__);

if (function_exists('set_time_limit')) {
    set_time_limit(1200); // 20 Minuten für lange Sync-Läufe
}
@ini_set('max_execution_time', '1200');
$configFile = $root . '/config.php';
$autoloadFile = $root . '/autoload.php';

try {
    if (!is_file($configFile) || !is_file($autoloadFile)) {
        throw new RuntimeException('Konfiguration oder Autoloader nicht gefunden.');
    }

    $config = require $configFile;
    require_once $autoloadFile;
} catch (Throwable $e) {
    error_log('[bootstrap_api] ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header_remove('X-Powered-By');
    header_remove('Server');

    $detail = $e->getMessage() . ' in ' . ($e->getFile() ?? 'n/a') . ':' . ($e->getLine() ?? 0);
    echo json_encode(['ok' => false, 'error' => 'Konfiguration kann nicht geladen werden: ' . $detail]);
    exit;
}

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
    // Log API errors into status log so they appear in the main protocol
    try {
        global $config;
        if (isset($config)) {
            $tracker = createStatusTracker($config, 'categories');
            $tracker->logError(
                'API-Fehler: ' . $message,
                [
                    'endpoint' => basename($_SERVER['SCRIPT_NAME'] ?? ''),
                    'status' => $status,
                ],
                'api_error'
            );
        }
    } catch (Throwable $e) {
        // Ignore logging failures to not mask the original error
    }
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
    $defaultSource = afs_prefer_path('afs.yml', 'schemas');
    $sourceMappingPath = $config['sync_mappings']['primary']['source'] ?? $defaultSource;
    $dataSource = new AFS_Get_Data($mssql, is_string($sourceMappingPath) ? $sourceMappingPath : null);
    $afs = new AFS($dataSource, $config);
    $logger = createMappingLogger($config);
    $evo = new EVO($pdo, $afs, $tracker, $config, $logger);
    $evo->setSourceConnection($mssql);

    return [$tracker, $evo, $mssql];
}

function createMappingOnlyEnvironment(array $config, string $job = 'categories', ?string $manifestOverride = null): array
{
    $tracker = createStatusTracker($config, $job);
    $status = $tracker->getStatus();
    if (($status['state'] ?? '') === 'running') {
        throw new AFS_SyncBusyException('Synchronisation läuft bereits. Bitte warten.');
    }

    $pdo = createEvoPdo($config);

    $primary = $config['sync_mappings']['primary'] ?? [];
    $manifestPath = isset($primary['rules']) && is_string($primary['rules']) && $primary['rules'] !== ''
        ? $primary['rules']
        : afs_prefer_path('afs_evo.yml', 'mapping');

    if (is_string($manifestOverride) && $manifestOverride !== '') {
        // Allow relative paths from project root
        $candidate = afs_config_resolve_path($manifestOverride);
        $manifestPath = is_file($candidate) ? $candidate : $manifestOverride;
    }

    if (!is_file($manifestPath)) {
        throw new AFS_ConfigurationException('Mapping-Manifest nicht gefunden: ' . $manifestPath);
    }

    $manifest = YamlMappingLoader::load($manifestPath);
    $projectRoot = $config['paths']['root'] ?? dirname(__DIR__);

    $sourcesManifest = $manifest['sources'] ?? [];
    if (!is_array($sourcesManifest)) {
        $sourcesManifest = [];
    }

    $sourceDefinitions = [];
    $sharedConnections = [];
    $connectionsToClose = [];

    foreach ($sourcesManifest as $sourceId => $sourceInfo) {
        if (!is_array($sourceInfo)) {
            continue;
        }
        $schemaRef = (string)($sourceInfo['schema'] ?? '');
        if ($schemaRef === '') {
            continue;
        }
        $schemaPath = str_starts_with($schemaRef, '/') || preg_match('#^[A-Za-z]:[\\/]#', $schemaRef)
            ? $schemaRef
            : $projectRoot . '/' . ltrim($schemaRef, '/');
        if (!is_file($schemaPath)) {
            throw new AFS_ConfigurationException(sprintf('Schema-Datei für Quelle "%s" nicht gefunden (%s).', $sourceId, $schemaPath));
        }

        $schema = YamlMappingLoader::load($schemaPath);
        $driver = strtolower((string)($schema['driver'] ?? 'mssql'));

        switch ($driver) {
            case 'mssql':
                if (!isset($sharedConnections['__mssql'])) {
                    $mssql = createMssql($config);
                    try {
                        $mssql->scalar('SELECT 1');
                    } catch (Throwable $e) {
                        $mssql->close();
                        throw new AFS_DatabaseException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
                    }
                    $sharedConnections['__mssql'] = $mssql;
                    $connectionsToClose[] = $mssql;
                }
                $sourceDefinitions[$sourceId] = [
                    'type' => 'mapper',
                    'mapper' => SourceMapper::fromFile($schemaPath),
                    'connection' => $sharedConnections['__mssql'],
                ];
                break;

            case 'filedb':
                $connection = FileDB_Connection::fromConfig($schema, $projectRoot);
                $sourceDefinitions[$sourceId] = [
                    'type' => 'mapper',
                    'mapper' => SourceMapper::fromFile($schemaPath),
                    'connection' => $connection,
                ];
                break;

            case 'sqlite':
                // Use EVO SQLite as default source for sqlite driver
                $dbPath = $config['paths']['data_db'] ?? ($projectRoot . '/db/evo.db');
                $connection = new SQLite_Connection($dbPath);
                $sourceDefinitions[$sourceId] = [
                    'type' => 'mapper',
                    'mapper' => SourceMapper::fromFile($schemaPath),
                    'connection' => $connection,
                ];
                $connectionsToClose[] = $connection;
                break;

            case 'filecatcher':
                $sourceDefinitions[$sourceId] = [
                    'type' => 'filecatcher',
                ];
                break;

            default:
                throw new RuntimeException(sprintf('Unbekannter Treiber "%s" für Quelle "%s".', $driver, $sourceId));
        }
    }

    $targetsManifest = $manifest['target'] ?? [];
    if (!is_array($targetsManifest) || $targetsManifest === []) {
        throw new RuntimeException('Manifest enthält kein target-Objekt.');
    }
    $targetEntry = reset($targetsManifest);
    if (!is_array($targetEntry) || empty($targetEntry['schema'])) {
        throw new RuntimeException('Target-Konfiguration unvollständig (schema fehlt).');
    }
    $targetSchemaRef = (string)$targetEntry['schema'];
    $targetSchemaPath = str_starts_with($targetSchemaRef, '/') || preg_match('#^[A-Za-z]:[\\/]#', $targetSchemaRef)
        ? $targetSchemaRef
        : $projectRoot . '/' . ltrim($targetSchemaRef, '/');
    if (!is_file($targetSchemaPath)) {
        throw new AFS_ConfigurationException('Target-Schema nicht gefunden: ' . $targetSchemaPath);
    }
    $targetMapper = TargetMapper::fromFile($targetSchemaPath);

    $engine = new MappingSyncEngine($sourceDefinitions, $targetMapper, $manifest);

    return [$tracker, $engine, $connectionsToClose, $pdo];
}

/**
 * Check for GitHub updates and notify main server if updated
 * This function is called automatically for all API requests (except initial_setup and update_notification)
 * 
 * @param array $config Configuration array
 * @return array|null Update result or null if no update performed
 */
function performAutoUpdateCheck(array $config): ?array
{
    // Skip auto-update for specific endpoints
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $skipEndpoints = ['initial_setup.php', 'update_notification.php', 'github_update.php'];
    
    if (in_array($scriptName, $skipEndpoints, true)) {
        return null;
    }
    
    $githubConfig = $config['github'] ?? [];
    $autoUpdate = $githubConfig['auto_update'] ?? false;
    
    // If auto-update is disabled, skip
    if (!$autoUpdate) {
        return null;
    }
    
    $branch = $githubConfig['branch'] ?? '';
    $repoPath = $config['paths']['root'] ?? dirname(__DIR__);
    
    try {
        $updater = new AFS_GitHubUpdater($repoPath, $autoUpdate, $branch);
        $result = $updater->checkAndUpdate();
        
        // If an update was performed, notify the main server
        if ($result['updated'] ?? false) {
            $logger = createMappingLogger($config);
            $notifier = new AFS_UpdateNotifier($config, $logger);
            
            $notificationResult = $notifier->notifyUpdate($result['info'] ?? []);
            $result['notification'] = $notificationResult;
        }
        
        return $result;
    } catch (\Throwable $e) {
        // Don't fail API calls on update errors, just log
        error_log('Auto-update check failed: ' . $e->getMessage());
        return [
            'checked' => true,
            'updated' => false,
            'error' => $e->getMessage(),
        ];
    }
}

// Perform automatic update check for all API requests
// This ensures the interface is always up-to-date before processing API calls
$autoUpdateResult = performAutoUpdateCheck($config);

// Store update result in a global variable so endpoints can access it
$GLOBALS['auto_update_result'] = $autoUpdateResult;

set_exception_handler(static function (Throwable $e): void {
    api_error($e->getMessage());
});

set_error_handler(static function ($errno, $errstr, $errfile, $errline): void {
    api_error(sprintf('%s in %s:%d', $errstr, $errfile, $errline));
});
