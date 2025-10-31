<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Nur POST-Anfragen sind erlaubt.']);
    exit;
}

$config = $config ?? ($GLOBALS['config'] ?? require __DIR__ . '/../config.php');

$rawInput = file_get_contents('php://input');
$decodedInput = [];
if ($rawInput !== false && $rawInput !== '') {
    $decodedInput = json_decode($rawInput, true);
    if (!is_array($decodedInput)) {
        $decodedInput = [];
    }
}

$serverIndex = $decodedInput['server_index'] ?? null;
if ($serverIndex === null || !is_numeric((string)$serverIndex)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Server-Index.']);
    exit;
}
$serverIndex = (int)$serverIndex;

$servers = $config['remote_servers']['servers'] ?? [];

// Try reading current servers directly from .env to avoid stale config
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
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Remote-Server nicht gefunden.']);
    exit;
}

$remote = $servers[$serverIndex];
if (empty($remote['url'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Remote-Server-URL fehlt.']);
    exit;
}

unset($decodedInput['server_index']);

$targetUrl = rtrim((string)$remote['url'], '/') . '/api/databases_test.php';

$headers = ['Accept: application/json', 'Content-Type: application/json'];
if (!empty($remote['api_key'])) {
    $headers[] = 'X-API-Key: ' . $remote['api_key'];
}

$ch = curl_init($targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($decodedInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$timeout = (int)($config['remote_servers']['timeout'] ?? 10);
curl_setopt($ch, CURLOPT_TIMEOUT, max(3, $timeout));
$allowInsecure = (bool)($config['remote_servers']['allow_insecure'] ?? false);
if ($allowInsecure) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$responseBody = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
http_response_code($status);

if ($responseBody === false) {
    echo json_encode(['ok' => false, 'error' => 'Remote-Anfrage fehlgeschlagen: ' . ($error ?: 'Unbekannter Fehler')]);
    exit;
}

$decoded = json_decode($responseBody, true);
if (!is_array($decoded)) {
    echo json_encode(['ok' => false, 'error' => 'Ungültige Antwort vom Remote-Server.', 'status' => $status]);
    exit;
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
