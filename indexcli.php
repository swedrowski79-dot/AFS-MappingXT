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
  run                 Kompletten Synchronisationslauf starten (Standard)
  status              Aktuellen Status aus status.db anzeigen
  log                 Protokollausgabe (alle Ebenen) – optional: --level=info|warning|error
  errors              Alias für "log --level=error"
  clear-errors        Protokoll leeren

Optionen:
  --job=NAME               Name des Sync-Jobs (Standard: categories)
  --copy-images[=1|0]      Bilddateien mitkopieren (Standard: 0)
  --image-source=/pfad     Quellverzeichnis für Bilder (erforderlich bei --copy-images)
  --image-dest=/pfad       Zielverzeichnis (optional)
  --copy-documents[=1|0]   Dokumentdateien mitkopieren (Standard: 0)
  --document-source=/pfad  Quellverzeichnis für Dokumente (erforderlich bei --copy-documents)
  --document-dest=/pfad    Zielverzeichnis (optional)
  --max-errors=ZAHL        Maximale Logeinträge überschreibt config.php
  --limit=ZAHL             Anzahl Logeinträge bei log/errors (Standard 200)

TXT;
    exit(0);
}

$job = $args->getString('job') ?? 'categories';
$maxErrorsCfg = $config['status']['max_errors'] ?? 200;
$maxErrors = (int)($args->getString('max-errors', (string)$maxErrorsCfg));

function createStatusTrackerCli(array $config, string $job, int $maxErrors): AFS_Evo_StatusTracker
{
    $statusDb = $config['paths']['status_db'] ?? (__DIR__ . '/db/status.db');
    if (!is_file($statusDb)) {
        throw new RuntimeException("status.db nicht gefunden: {$statusDb}");
    }
    return new AFS_Evo_StatusTracker($statusDb, $job, $maxErrors);
}

/**
 * @return array{tracker:AFS_Evo_StatusTracker,evo:AFS_Evo,mssql:MSSQL}
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
        throw new RuntimeException("SQLite-Datei nicht gefunden: {$dataDb}");
    }
    $pdo = new PDO('sqlite:' . $dataDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $mssqlCfg = $config['mssql'] ?? [];
    $host = $mssqlCfg['host'] ?? 'localhost';
    $port = (int)($mssqlCfg['port'] ?? 1433);
    $server = $host . ',' . $port;

    $mssql = new MSSQL(
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
        throw new RuntimeException('MSSQL-Verbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
    }

    $dataSource = new AFS_Get_Data($mssql);
    $afs = new AFS($dataSource, $config);
    $evo = new AFS_Evo($pdo, $afs, $tracker, $config);

    return [
        'tracker' => $tracker,
        'evo' => $evo,
        'mssql' => $mssql,
    ];
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

        try {
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

            echo "\nDetails:\n";
            if (isset($summary['bilder'])) {
                echo sprintf(" - Bilder importiert: %d\n", count($summary['bilder']));
            }
            if (isset($summary['bilder_copy'])) {
                $copy = $summary['bilder_copy'];
                echo sprintf(
                    " - Bilder kopiert: %d (fehlend: %d, Fehler: %d)\n",
                    count($copy['copied'] ?? []),
                    count($copy['missing'] ?? []),
                    count($copy['failed'] ?? [])
                );
            }
            if (isset($summary['dokumente'])) {
                echo sprintf(" - Dokumente importiert: %d\n", count($summary['dokumente']));
            }
            if (isset($summary['attribute'])) {
                echo sprintf(" - Attribute importiert: %d\n", count($summary['attribute']));
            }
            if (isset($summary['warengruppen'])) {
                $cat = $summary['warengruppen'];
                echo sprintf(
                    " - Warengruppen: %d eingefügt, %d aktualisiert, %d Parent-Links gesetzt\n",
                    $cat['inserted'] ?? 0,
                    $cat['updated'] ?? 0,
                    $cat['parent_set'] ?? 0
                );
            }
            if (isset($summary['artikel'])) {
                $art = $summary['artikel'];
                echo sprintf(
                    " - Artikel: %d verarbeitet, %d neu, %d aktualisiert, %d offline gesetzt\n",
                    $art['processed'] ?? 0,
                    $art['inserted'] ?? 0,
                    $art['updated'] ?? 0,
                    $art['deactivated'] ?? 0
                );
                echo sprintf(
                    "   └ Bilder-Verknüpfungen: %d · Dokument-Verknüpfungen: %d · Attribute-Verknüpfungen: %d\n",
                    $art['images'] ?? 0,
                    $art['documents'] ?? 0,
                    $art['attributes'] ?? 0
                );
            }
            if (array_key_exists('delta', $summary)) {
                if (!empty($summary['delta'])) {
                    $deltaRows = array_sum($summary['delta']);
                    echo " - Delta-Export: {$deltaRows} Datensätze\n";
                    foreach ($summary['delta'] as $table => $count) {
                        echo sprintf("   · %s: %d\n", $table, $count);
                    }
                } else {
                    echo " - Delta-Export: keine Änderungen\n";
                }
            }

            $errors = $tracker->getErrors(5);
            if ($errors) {
                echo "\nLetzte Fehler im Protokoll (max. 5):\n";
                foreach ($errors as $error) {
                    echo "- [" . ($error['created_at'] ?? '-') . "]";
                    if (!empty($error['stage'])) {
                        echo " <{$error['stage']}>";
                    }
                    echo " " . ($error['message'] ?? '') . "\n";
                }
            }

            $mssql->close();
            exit(0);
        } catch (Throwable $e) {
            if ($e instanceof AFS_SyncBusyException) {
                fwrite(STDERR, $e->getMessage() . "\n");
                exit(1);
            }
            if (isset($tracker) && $tracker instanceof AFS_Evo_StatusTracker) {
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
