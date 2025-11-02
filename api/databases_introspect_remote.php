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

$table = trim((string)($payload['table'] ?? ''));
if ($table === '') {
    api_error('Tabellenname fehlt.', 400);
}

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

$connectionId = (string)($payload['id'] ?? '');
if ($connectionId === '') {
    api_error('Verbindungs-ID erforderlich.', 400);
}

$connection = null;
foreach ($remoteConfig['connections'] as $candidate) {
    if (($candidate['id'] ?? '') === $connectionId) {
        $connection = $candidate;
        break;
    }
}

if ($connection === null) {
    api_error('Verbindung nicht gefunden.', 404);
}

$targetUrl = rtrim((string)$remote['url'], '/') . '/api/databases_introspect.php';
$payloadForRemote = [
    'table' => $table,
    'connection' => dbm_prepare_remote_payload($connection),
];
$headers = ['Accept: application/json', 'Content-Type: application/json'];
if (!empty($remote['api_key'])) {
    $headers[] = 'X-API-Key: ' . $remote['api_key'];
}

$timeout = max(3, (int)($config['remote_servers']['timeout'] ?? 10));
$allowInsecure = (bool)($config['remote_servers']['allow_insecure'] ?? false);

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($payloadForRemote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout,
]);
if ($allowInsecure) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

if ($responseBody === false) {
    api_error('Remote-Introspektion fehlgeschlagen: ' . ($curlError ?: 'Unbekannter Fehler'));
}

$decoded = json_decode($responseBody, true);
if (!is_array($decoded)) {
    api_error('Ungültige Antwort vom Remote-Server (HTTP ' . $status . ').', 502);
}

$columns = $decoded['data']['columns'] ?? null;
if (!is_array($columns)) {
    api_error('Keine Spalteninformationen vom Remote-Server erhalten.', 502);
}

api_ok(['columns' => $columns]);
