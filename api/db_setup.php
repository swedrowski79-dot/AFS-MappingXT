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
 * Erstellt/aktualisiert die SQLite-Datenbanken analog zu scripts/setup.php.
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

    $targets = [
        'status' => [
            'path' => $config['paths']['status_db'] ?? ($dbDir . '/status.db'),
            'sql'  => $scriptsDir . '/create_status.sql',
        ],
        'evo' => [
            'path' => $config['paths']['data_db'] ?? ($dbDir . '/evo.db'),
            'sql'  => $scriptsDir . '/create_evo.sql',
        ],
    ];

    $summary = [];

    foreach ($targets as $key => $info) {
        $path = (string)$info['path'];
        $sqlFile = (string)$info['sql'];

        if (!is_file($sqlFile)) {
            throw new AFS_ConfigurationException("SQL-Datei nicht gefunden: {$sqlFile}");
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new AFS_DatabaseException("Konnte Verzeichnis nicht anlegen: {$dir}");
        }

        $initSql = file_get_contents($sqlFile);
        if ($initSql === false || trim($initSql) === '') {
            throw new AFS_ConfigurationException("SQL-Datei ist leer oder nicht lesbar: {$sqlFile}");
        }

        $wasPresent = is_file($path);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec($initSql);

        $summary[$key] = [
            'path' => $path,
            'created' => !$wasPresent,
        ];
    }

    return $summary;
}
