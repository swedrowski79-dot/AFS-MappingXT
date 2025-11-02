#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript darf nur im CLI-Modus ausgeführt werden.\n");
    exit(1);
}

if (function_exists('set_time_limit')) {
    set_time_limit(1200); // 20 Minuten für längere Läufe
}
@ini_set('max_execution_time', '1200');

require_once __DIR__ . '/autoload.php';

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php wurde nicht gefunden.\n");
    exit(1);
}
$config = require $configPath;

// Security check: If security is enabled, only allow CLI access from API context
SecurityValidator::validateCliAccess($config, 'indexcli.php');

/**
 * Very small option parser: command [--key=value] [--flag]
 */
class CliArgs
{
    public string $command = 'run';
    /** @var array<string,string|bool> */
    public array $options = [];

    public function __construct(array $argv)
    {
        array_shift($argv); // script name
        if ($argv && !str_starts_with($argv[0], '--')) {
            $this->command = strtolower(array_shift($argv));
        }
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $opt = substr($arg, 2);
            if (strpos($opt, '=') !== false) {
                [$key, $value] = explode('=', $opt, 2);
                $this->options[strtolower($key)] = $value;
            } else {
                $this->options[strtolower($opt)] = true;
            }
        }
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->options[strtolower($key)] ?? null;
        return $value === null || $value === true ? $default : (string)$value;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->options[strtolower($key)] ?? null;
        if ($value === null) {
            return $default;
        }
        if ($value === true) {
            return true;
        }
        $normalized = strtolower((string)$value);
        return in_array($normalized, ['1', 'true', 'yes', 'ja', 'on'], true);
    }
}

$args = new CliArgs($_SERVER['argv']);
if (isset($args->options['help']) || $args->command === 'help') {
    echo <<<TXT
AFS Sync CLI
=============

Aufruf:
  php indexcli.php [command] [--option=value]

Verfügbare Kommandos:
  run                 Mapping starten (erfordert --mapping)
  status              Aktuellen Status aus status.db anzeigen
  log                 Protokollausgabe (alle Ebenen) – optional: --level=info|warning|error
  errors              Alias für "log --level=error"
  clear-errors        Protokoll leeren
  update              Manuell nach Updates suchen und installieren

Optionen:
  --job=NAME               Name des Sync-Jobs (Standard: categories)
  --mapping=/pfad.yml      Mapping-Manifest (YML) auswählen (überschreibt Standard)
  --copy-images[=1|0]      Bilddateien mitkopieren (Standard: 0)
  --image-source=/pfad     Quellverzeichnis für Bilder (erforderlich bei --copy-images)
  --image-dest=/pfad       Zielverzeichnis (optional)
  --copy-documents[=1|0]   Dokumentdateien mitkopieren (Standard: 0)
  --document-source=/pfad  Quellverzeichnis (erforderlich bei --copy-documents)
  --document-dest=/pfad    Zielverzeichnis (optional)
  --max-errors=ZAHL        Maximale Logeinträge überschreibt config.php
  --limit=ZAHL             Anzahl Logeinträge bei log/errors (Standard 200)
  --skip-update            GitHub-Update beim Start überspringen

TXT;
    exit(0);
}

/**
 * Check and perform GitHub update if enabled
 */
function checkGitHubUpdate(array $config, bool $skipUpdate = false): void
{
    if ($skipUpdate) {
        return;
    }
    
    $githubConfig = $config['github'] ?? [];
    $autoUpdate = $githubConfig['auto_update'] ?? false;
    $branch = $githubConfig['branch'] ?? '';
    
    if (!$autoUpdate) {
        return;
    }
    
    try {
        $updater = new AFS_GitHubUpdater(__DIR__, $autoUpdate, $branch);
        echo "Prüfe auf GitHub-Updates...\n";
        
        $result = $updater->checkAndUpdate();
        
        if ($result['checked']) {
            $info = $result['info'];
            if ($info['available'] ?? false) {
                echo sprintf(
                    "Updates verfügbar: %d Commit(s) hinter remote (%s -> %s)\n",
                    $info['commits_behind'],
                    $info['current_commit'],
                    $info['remote_commit']
                );
                
                if ($result['updated']) {
                    echo "✓ Update erfolgreich durchgeführt.\n";
                } else {
                    echo "× Update nicht durchgeführt: " . ($result['message'] ?? 'Unbekannter Fehler') . "\n";
                }
            } else {
                echo "✓ Anwendung ist auf dem neuesten Stand.\n";
            }
        }
        echo "\n";
    } catch (Throwable $e) {
        // Don't fail on update errors, just warn
        echo "Warnung: GitHub-Update fehlgeschlagen: " . $e->getMessage() . "\n\n";
    }
}

$job = $args->getString('job') ?? 'categories';
$mappingOverride = $args->getString('mapping');
$maxErrorsCfg = $config['status']['max_errors'] ?? 200;
$maxErrors = (int)($args->getString('max-errors', (string)$maxErrorsCfg));

function createStatusTrackerCli(array $config, string $job, int $maxErrors): STATUS_Tracker
{
    $statusDb = $config['paths']['status_db'] ?? (__DIR__ . '/db/status.db');
    if (!is_file($statusDb)) {
        throw new AFS_DatabaseException("status.db nicht gefunden: {$statusDb}");
    }
    return new STATUS_Tracker($statusDb, $job, $maxErrors);
}

function createMappingLoggerCli(array $config): ?STATUS_MappingLogger
{
    $loggingConfig = $config['logging'] ?? [];
    $enableFileLogging = $loggingConfig['enable_file_logging'] ?? true;
    
    if (!$enableFileLogging) {
        return null;
    }
    
    $logDir = $config['paths']['log_dir'] ?? (__DIR__ . '/logs');
    $mappingVersion = $loggingConfig['mapping_version'] ?? '1.0.0';
    
    return new STATUS_MappingLogger($logDir, $mappingVersion);
}

/**
 * @return array{tracker:STATUS_Tracker,evo:EVO,mssql:MSSQL_Connection}
 */
function createSyncEnvironmentCli(array $config, string $job, int $maxErrors): array
{
    $tracker = createStatusTrackerCli($config, $job, $maxErrors);

    $status = $tracker->getStatus();
    if (($status['state'] ?? '') === 'running') {
        throw new AFS_SyncBusyException('Es läuft bereits eine Synchronisation.');
    }

    $dataDb = $config['paths']['data_db'] ?? (__DIR__ . '/db/evo.db');
    if (!is_file($dataDb)) {
        throw new AFS_DatabaseException("SQLite-Datei nicht gefunden: {$dataDb}");
    }
    $pdo = new PDO('sqlite:' . $dataDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $mssqlCfg = $config['mssql'] ?? [];
    $host = $mssqlCfg['host'] ?? 'localhost';
    $port = (int)($mssqlCfg['port'] ?? 1433);
    $server = $host . ',' . $port;

    $mssql = new MSSQL_Connection(
        $server,
        (string)($mssqlCfg['username'] ?? ''),
        (string)($mssqlCfg['password'] ?? ''),
        (string)($mssqlCfg['database'] ?? ''),
        [
            'encrypt' => $mssqlCfg['encrypt'] ?? true,
            'trust_server_certificate' => $mssqlCfg['trust_server_certificate'] ?? false,
            'appname' => $mssqlCfg['appname'] ?? 'AFS-Sync',
        ]
    );
    try {
        $mssql->scalar('SELECT 1');
    } catch (Throwable $e) {
        $mssql->close();
        throw new AFS_DatabaseException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
    }

    $sourceMappingPath = $config['sync_mappings']['primary']['source'] ?? afs_prefer_path('afs.yml', 'schemas');
    $dataSource = new AFS_Get_Data($mssql, is_string($sourceMappingPath) ? $sourceMappingPath : null);
    $afs = new AFS($dataSource, $config);
    $logger = createMappingLoggerCli($config);
    $evo = new EVO($pdo, $afs, $tracker, $config, $logger);
    $evo->setSourceConnection($mssql);

    return [
        'tracker' => $tracker,
        'evo' => $evo,
        'mssql' => $mssql,
    ];
}

function createMappingOnlyEnvironmentCli(array $config, string $job, int $maxErrors, ?string $manifestOverride = null): array
{
    $tracker = createStatusTrackerCli($config, $job, $maxErrors);
    $status = $tracker->getStatus();
    if (($status['state'] ?? '') === 'running') {
        throw new AFS_SyncBusyException('Es läuft bereits eine Synchronisation.');
    }

    $dataDb = $config['paths']['data_db'] ?? (__DIR__ . '/db/evo.db');
    if (!is_file($dataDb)) {
        throw new AFS_DatabaseException("SQLite-Datei nicht gefunden: {$dataDb}");
    }
    $pdo = new PDO('sqlite:' . $dataDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $primary = $config['sync_mappings']['primary'] ?? [];
    $manifestPath = isset($primary['rules']) && is_string($primary['rules']) && $primary['rules'] !== ''
        ? $primary['rules']
        : afs_prefer_path('afs_evo.yml', 'mapping');

    if (is_string($manifestOverride) && $manifestOverride !== '') {
        $candidate = afs_config_resolve_path($manifestOverride);
        $manifestPath = is_file($candidate) ? $candidate : $manifestOverride;
    }

    if (!is_file($manifestPath)) {
        throw new AFS_ConfigurationException('Mapping-Manifest nicht gefunden: ' . $manifestPath);
    }

    $manifest = YamlMappingLoader::load($manifestPath);
    $projectRoot = $config['paths']['root'] ?? __DIR__;

    $sourcesManifest = $manifest['sources'] ?? [];
    if (!is_array($sourcesManifest)) {
        $sourcesManifest = [];
    }

    $sourceDefinitions = [];
    $sharedConnections = [];
    $connectionsToClose = [];

    foreach ($sourcesManifest as $sourceId => $sourceInfo) {
        if (!is_array($sourceInfo)) continue;
        $schemaRef = (string)($sourceInfo['schema'] ?? '');
        if ($schemaRef === '') continue;
        $schemaPath = (str_starts_with($schemaRef, '/') || preg_match('#^[A-Za-z]:[\\/]#', $schemaRef))
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
                    $mssqlCfg = $config['mssql'] ?? [];
                    $host = $mssqlCfg['host'] ?? 'localhost';
                    $port = (int)($mssqlCfg['port'] ?? 1433);
                    $server = $host . ',' . $port;
                    $mssql = new MSSQL_Connection(
                        $server,
                        (string)($mssqlCfg['username'] ?? ''),
                        (string)($mssqlCfg['password'] ?? ''),
                        (string)($mssqlCfg['database'] ?? ''),
                        [
                            'encrypt' => $mssqlCfg['encrypt'] ?? true,
                            'trust_server_certificate' => $mssqlCfg['trust_server_certificate'] ?? false,
                            'appname' => $mssqlCfg['appname'] ?? 'AFS-Sync',
                        ]
                    );
                    try { $mssql->scalar('SELECT 1'); } catch (Throwable $e) { $mssql->close(); throw new AFS_DatabaseException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e); }
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
                $dbPath = $config['paths']['data_db'] ?? (__DIR__ . '/db/evo.db');
                $connection = new SQLite_Connection($dbPath);
                $sourceDefinitions[$sourceId] = [
                    'type' => 'mapper',
                    'mapper' => SourceMapper::fromFile($schemaPath),
                    'connection' => $connection,
                ];
                $connectionsToClose[] = $connection;
                break;

            case 'filecatcher':
                $sourceDefinitions[$sourceId] = [ 'type' => 'filecatcher' ];
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
    $targetSchemaPath = (str_starts_with($targetSchemaRef, '/') || preg_match('#^[A-Za-z]:[\\/]#', $targetSchemaRef))
        ? $targetSchemaRef
        : $projectRoot . '/' . ltrim($targetSchemaRef, '/');
    if (!is_file($targetSchemaPath)) {
        throw new AFS_ConfigurationException('Target-Schema nicht gefunden: ' . $targetSchemaPath);
    }
    $targetMapper = TargetMapper::fromFile($targetSchemaPath);

    $engine = new MappingSyncEngine($sourceDefinitions, $targetMapper, $manifest);

    return [ 'tracker' => $tracker, 'engine' => $engine, 'connections' => $connectionsToClose, 'pdo' => $pdo ];
}

function printStatus(array $status): void
{
    $state = $status['state'] ?? 'unknown';
    $stage = $status['stage'] ?? null;
    $message = $status['message'] ?? '';
    $processed = $status['processed'] ?? 0;
    $total = $status['total'] ?? 0;
    $percent = $total > 0 ? sprintf('%.1f', ($processed / $total) * 100) : (in_array($state, ['done', 'ready'], true) ? '100' : '0');
    $duration = '-';
    if (!empty($status['started_at']) && !empty($status['finished_at'])) {
        $start = strtotime((string)$status['started_at']);
        $end = strtotime((string)$status['finished_at']);
        if ($start && $end && $end >= $start) {
            $seconds = $end - $start;
            if ($seconds >= 3600) {
                $h = floor($seconds / 3600);
                $m = floor(($seconds % 3600) / 60);
                $s = $seconds % 60;
                $duration = sprintf('%dh %02dm %02ds', $h, $m, $s);
            } elseif ($seconds >= 60) {
                $m = floor($seconds / 60);
                $s = $seconds % 60;
                $duration = sprintf('%dm %02ds', $m, $s);
            } else {
                $duration = sprintf('%.2fs', $seconds);
            }
        }
    }

    echo "Status: {$state}\n";
    if ($stage) {
        echo "Phase : {$stage}\n";
    }
    if ($message) {
        echo "Hinweis: {$message}\n";
    }
    echo "Fortschritt: {$processed} / {$total} ({$percent} %)\n";
    echo "Laufzeit   : {$duration}\n";
    echo "Gestartet : " . ($status['started_at'] ?? '-') . "\n";
    echo "Aktualisiert: " . ($status['updated_at'] ?? '-') . "\n";
    echo "Beendet   : " . ($status['finished_at'] ?? '-') . "\n";
}

switch ($args->command) {
    case 'update':
        // Manual update command
        try {
            $githubConfig = $config['github'] ?? [];
            $branch = $githubConfig['branch'] ?? '';
            
            $updater = new AFS_GitHubUpdater(__DIR__, true, $branch);
            
            echo "Prüfe auf GitHub-Updates...\n";
            $result = $updater->checkAndUpdate();
            
            if ($result['checked']) {
                $info = $result['info'];
                if ($info['available'] ?? false) {
                    echo sprintf(
                        "Updates verfügbar: %d Commit(s) hinter remote (%s -> %s)\n",
                        $info['commits_behind'],
                        $info['current_commit'],
                        $info['remote_commit']
                    );
                    
                    if ($result['updated']) {
                        echo "✓ Update erfolgreich durchgeführt.\n";
                        exit(0);
                    } else {
                        $message = $result['message'] ?? 'Unbekannter Fehler';
                        echo "× Update fehlgeschlagen: {$message}\n";
                        if (isset($result['result']['output'])) {
                            echo "Details: {$result['result']['output']}\n";
                        }
                        exit(1);
                    }
                } else {
                    echo "✓ Anwendung ist bereits auf dem neuesten Stand.\n";
                    exit(0);
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "Fehler beim Update: " . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'status':
        $tracker = createStatusTrackerCli($config, $job, $maxErrors);
        $status = $tracker->getStatus();
        printStatus($status);
        exit(0);

    case 'log':
    case 'errors':
        $tracker = createStatusTrackerCli($config, $job, $maxErrors);
        $limitOpt = $args->getString('limit', '200');
        $limit = max(1, (int)$limitOpt);
        $levelOpt = $args->getString('level', $args->command === 'errors' ? 'error' : 'all');

        $levels = null;
        if ($levelOpt !== null) {
            $levelOpt = strtolower(trim($levelOpt));
            if ($levelOpt !== '' && $levelOpt !== 'all') {
                $levels = array_filter(array_map(static fn($item) => strtolower(trim($item)), explode(',', $levelOpt)));
            }
        }

        $entries = $tracker->getLogs($limit, $levels);
        if (!$entries) {
            echo "Keine Logeinträge vorhanden.\n";
            exit(0);
        }

        foreach ($entries as $entry) {
            $level = strtoupper($entry['level'] ?? 'info');
            $stage = $entry['stage'] ?? null;
            echo "[" . ($entry['created_at'] ?? '-') . "]";
            echo " [{$level}]";
            if ($stage) {
                echo " <{$stage}>";
            }
            echo " " . ($entry['message'] ?? '') . "\n";
            if (!empty($entry['context'])) {
                $context = is_array($entry['context'])
                    ? json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    : (string)$entry['context'];
                echo "    Kontext: {$context}\n";
            }
        }
        exit(0);

    case 'clear-errors':
        $tracker = createStatusTrackerCli($config, $job, $maxErrors);
        $tracker->clearLog();
        echo "Protokoll wurde geleert.\n";
        exit(0);

    case 'run':
        // Check for updates at startup (unless --skip-update is set)
        $skipUpdate = $args->getBool('skip-update', false);
        checkGitHubUpdate($config, $skipUpdate);

        try {
            if ($mappingOverride) {
                // Mapping-only Lauf mit Manifest
                $env = createMappingOnlyEnvironmentCli($config, $job, $maxErrors, $mappingOverride);
                /** @var STATUS_Tracker $tracker */
                $tracker = $env['tracker'];
                /** @var MappingSyncEngine $engine */
                $engine = $env['engine'];
                /** @var PDO $pdo */
                $pdo = $env['pdo'];
                $connections = $env['connections'];

                echo "Starte Mapping: {$mappingOverride}\n";
                $tracker->begin('mapping', 'CLI-Start');
                $overallStart = microtime(true);

                $totalSteps = 8; $done = 0;
                $summary = [];
                // Warengruppen
                $tracker->advance('warengruppen', ['message' => 'Synchronisiere Warengruppen...', 'total' => $totalSteps, 'processed' => $done]);
                try { $summary['warengruppe'] = $engine->syncEntity('warengruppe', $pdo); } catch (Throwable $e) {}
                $done++; $tracker->advance('warengruppen', ['processed' => $done, 'total' => $totalSteps]);
                // Artikel
                $tracker->advance('artikel', ['message' => 'Synchronisiere Artikel...', 'total' => $totalSteps, 'processed' => $done]);
                $summary['artikel'] = $engine->syncEntity('artikel', $pdo);
                $done++; $tracker->advance('artikel', ['processed' => $done, 'total' => $totalSteps]);
                // Artikel-Meta
                $tracker->advance('artikel_meta', ['message' => 'Aktualisiere Artikel-Metadaten...', 'total' => $totalSteps, 'processed' => $done]);
                try { $summary['artikel_meta'] = $engine->syncEntity('artikel_meta', $pdo); } catch (Throwable $e) {}
                $done++; $tracker->advance('artikel_meta', ['processed' => $done, 'total' => $totalSteps]);
                // Filecatcher + Media
                $tracker->advance('filecatcher', ['message' => 'Analysiere Mediendateien...', 'total' => $totalSteps, 'processed' => $done]);
                $done++; $tracker->advance('filecatcher', ['processed' => $done, 'total' => $totalSteps]);
                foreach (['media_bilder','media_dokumente','media_relation_bilder','media_relation_dokumente'] as $entity) {
                    $tracker->advance($entity, ['message' => 'Synchronisiere ' . $entity . '...', 'total' => $totalSteps, 'processed' => $done]);
                    try { $summary[$entity] = $engine->syncEntity($entity, $pdo); } catch (Throwable $e) {}
                    $done++; $tracker->advance($entity, ['processed' => $done, 'total' => $totalSteps]);
                }

                $duration = microtime(true) - $overallStart;
                $tracker->complete(['message' => sprintf('Mapping abgeschlossen (%.2fs)', $duration)]);

                echo "\nSynchronisation abgeschlossen.\n";
                echo str_repeat('=', 48) . "\n";
                printStatus($tracker->getStatus());

                // Verbindungen schließen
                foreach ($connections as $conn) { if ($conn instanceof MSSQL_Connection) { $conn->close(); } }
                exit(0);
            }

            // Fallback: Legacy Vollsync
            $copyImages = $args->getBool('copy-images', false);
            $imageSource = $args->getString('image-source');
            $imageDest = $args->getString('image-dest');
            $copyDocuments = $args->getBool('copy-documents', false);
            $documentSource = $args->getString('document-source');
            $documentDest = $args->getString('document-dest');

            if ($copyImages && $imageSource === null) {
                fwrite(STDERR, "Für --copy-images muss --image-source angegeben werden.\n");
                exit(1);
            }

            $env = createSyncEnvironmentCli($config, $job, $maxErrors);
            $tracker = $env['tracker'];
            $evo = $env['evo'];
            /** @var MSSQL $mssql */
            $mssql = $env['mssql'];

            echo "Starte Synchronisation für Job '{$job}'...\n";
            $summary = $evo->syncAll($copyImages, $imageSource, $imageDest, $copyDocuments, $documentSource, $documentDest);

            echo "\nSynchronisation abgeschlossen.\n";
            echo str_repeat('=', 48) . "\n";

            $status = $tracker->getStatus();
            printStatus($status);

            $mssql->close();
            exit(0);
        } catch (Throwable $e) {
            if ($e instanceof AFS_SyncBusyException) {
                fwrite(STDERR, $e->getMessage() . "\n");
                exit(1);
            }
            if (isset($tracker) && $tracker instanceof STATUS_Tracker) {
                $tracker->logError($e->getMessage(), ['exception' => get_class($e)], 'cli');
                $tracker->fail($e->getMessage(), 'cli');
            }
            fwrite(STDERR, "FEHLER: " . $e->getMessage() . "\n");
            exit(1);
        }

    default:
        fwrite(STDERR, "Unbekanntes Kommando: {$args->command}\n");
        fwrite(STDERR, "Nutze 'php indexcli.php help' für Hilfe.\n");
        exit(1);
}
