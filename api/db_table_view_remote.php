<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$config = $config ?? ($GLOBALS['config'] ?? require __DIR__ . '/../config.php');

$serverIndex = isset($_GET['server_index']) ? (int)$_GET['server_index'] : -1;
if ($serverIndex < 0) {
    api_error('server_index erforderlich', 400);
}

$servers = $config['remote_servers']['servers'] ?? [];
if (!isset($servers[$serverIndex])) {
    // Try reading from .env directly
    $envPath = dirname(__DIR__) . '/.env';
    if (is_file($envPath)) {
        $content = file_get_contents($envPath) ?: '';
        if ($content !== '') {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', trim($line), $m)) {
                    $value = trim($m[1], "\"' ");
                    $servers = [];
                    foreach (array_filter(array_map('trim', explode(',', $value))) as $cfg) {
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
                    break;
                }
            }
        }
    }
}
if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden', 404);
}
$remote = $servers[$serverIndex];
if (empty($remote['url'])) {
    api_error('Remote-Server-URL fehlt', 400);
}

$table = $_GET['table'] ?? '';
$db = $_GET['db'] ?? 'main';
$limit = $_GET['limit'] ?? '100';
if ($table === '') {
    api_error('Parameter table fehlt', 400);
}

$targetUrl = rtrim((string)$remote['url'], '/') . '/api/db_table_view.php?' . http_build_query([
    'table' => $table,
    'db' => $db,
    'limit' => $limit,
]);

$ch = curl_init($targetUrl);
$headers = ['Accept: application/json'];
if (!empty($remote['api_key'])) {
    $headers[] = 'X-API-Key: ' . $remote['api_key'];
}
$timeout = (int)($config['remote_servers']['timeout'] ?? 10);
$allowInsecure = (bool)($config['remote_servers']['allow_insecure'] ?? false);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => max(3, $timeout),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => !$allowInsecure,
    CURLOPT_SSL_VERIFYHOST => $allowInsecure ? 0 : 2,
]);
$responseBody = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');

if ($responseBody === false) {
    echo json_encode(['ok' => false, 'error' => 'Remote-Anfrage fehlgeschlagen: ' . ($error ?: 'Unbekannter Fehler')]);
    exit;
}

echo $responseBody;
