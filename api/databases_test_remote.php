<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_database_utils.php';
require_once __DIR__ . '/../classes/config/RemoteDatabaseConfig.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_error('Nur POST-Anfragen sind erlaubt.', 405);
}

$config = $config ?? ($GLOBALS['config'] ?? require __DIR__ . '/../config.php');

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    api_error('Ungültige Eingabe.', 400);
}

$serverIndex = $payload['server_index'] ?? null;
if ($serverIndex === null || !is_numeric((string)$serverIndex)) {
    api_error('Ungültiger Server-Index.', 400);
}
$serverIndex = (int)$serverIndex;

$servers = $config['remote_servers']['servers'] ?? [];

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $content = file_get_contents($envPath) ?: '';
    $lines = explode("\n", $content);
    $remoteServersValue = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $m)) {
            $remoteServersValue = trim($m[1], "\"' ");
            break;
        }
    }
    if ($remoteServersValue !== '') {
        $servers = [];
        foreach (array_filter(array_map('trim', explode(',', $remoteServersValue))) as $cfg) {
            $parts = array_map('trim', explode('|', $cfg));
            if (count($parts) >= 2) {
                $servers[] = [
                    'name' => $parts[0],
                    'url' => rtrim($parts[1], '/'),
                    'api_key' => $parts[2] ?? '',
                    'database' => $parts[3] ?? '',
                ];
            }
        }
    }
}

if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden.', 404);
}

$remote = $servers[$serverIndex];
$remoteConfig = RemoteDatabaseConfig::load($remote);
$connection = null;
$connectionIndex = null;

if (!empty($payload['id'])) {
    foreach ($remoteConfig['connections'] as $idx => $candidate) {
        if (($candidate['id'] ?? '') === $payload['id']) {
            $connection = $candidate;
            $connectionIndex = $idx;
            break;
        }
    }
    if ($connection === null) {
        api_error('Verbindung nicht gefunden.', 404);
    }
} elseif (!empty($payload['connection']) && is_array($payload['connection'])) {
    $connection = $payload['connection'];
} else {
    api_error('Es wurde keine Verbindung angegeben.', 400);
}

$remoteResult = dbm_test_remote_connection($remote, $connection, $config);

if ($connectionIndex !== null) {
    $remoteConfig['connections'][$connectionIndex]['last_status'] = $remoteResult;
    RemoteDatabaseConfig::save($remote, $remoteConfig['connections']);
}

api_ok([
    'status' => $remoteResult,
]);
