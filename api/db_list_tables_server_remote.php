<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$serverIndex = isset($_GET['server_index']) ? (int)$_GET['server_index'] : -1;
$connId = isset($_GET['conn_id']) ? (string)$_GET['conn_id'] : '';
if ($serverIndex < 0 || $connId === '') {
    api_error('Parameter fehlen', 400);
}

global $config;
$servers = $config['remote_servers']['servers'] ?? [];
// Fallback: load from .env like databases_remote.php
if (!isset($servers[$serverIndex])) {
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
}
if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden', 404);
}
$remote = $servers[$serverIndex];
$target = rtrim((string)$remote['url'], '/') . '/api/db_list_tables_server.php?' . http_build_query(['conn_id' => $connId]);

$ch = curl_init($target);
$headers = ['Accept: application/json'];
if (!empty($remote['api_key'])) {
    $headers[] = 'X-API-Key: ' . $remote['api_key'];
}
$timeout = (int)($config['remote_servers']['timeout'] ?? 10);
$allowInsecure = (bool)($config['remote_servers']['allow_insecure'] ?? false);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => max(10, $timeout),
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => !$allowInsecure,
    CURLOPT_SSL_VERIFYHOST => $allowInsecure ? 0 : 2,
]);
$body = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

// Fallback for older remote versions: try sqlite via sqlite_path when possible
if ($body === false || $status >= 400) {
    // Load remote connections to resolve type
    $infoBody = null; $infoStatus = 0;
    $infoUrl = rtrim((string)$remote['url'], '/') . '/api/databases_manage.php';
    $ch2 = curl_init($infoUrl);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>max(3,$timeout), CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>!$allowInsecure, CURLOPT_SSL_VERIFYHOST=>$allowInsecure?0:2]);
    $infoBody = curl_exec($ch2); $infoStatus = curl_getinfo($ch2, CURLINFO_RESPONSE_CODE) ?: 500; curl_close($ch2);
    $fallbackDone = false;
    if ($infoBody !== false && $infoStatus < 400) {
        $decoded = json_decode($infoBody, true);
        if (is_array($decoded) && !empty($decoded['data']['connections'])) {
            foreach ($decoded['data']['connections'] as $c) {
                if ((string)($c['id'] ?? '') === $connId && ($c['type'] ?? '') === 'sqlite' && !empty($c['settings']['path'])) {
                    $alt = rtrim((string)$remote['url'], '/') . '/api/db_list_tables.php?sqlite_path=' . rawurlencode($c['settings']['path']);
                    $ch3 = curl_init($alt);
                    curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>max(3,$timeout), CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>!$allowInsecure, CURLOPT_SSL_VERIFYHOST=>$allowInsecure?0:2]);
                    $altBody = curl_exec($ch3); $altStatus = curl_getinfo($ch3, CURLINFO_RESPONSE_CODE) ?: 500; curl_close($ch3);
                    if ($altBody !== false && $altStatus < 400) { $body = $altBody; $status = $altStatus; $fallbackDone = true; }
                    break;
                }
            }
        }
    }
    if (!$fallbackDone && $body === false) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$error ?: 'Remote-Antwort ungültig']);
        return;
    }
}

if ($status === 404) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Remote-Endpoint fehlt (db_list_tables_server.php). Bitte Remote-Server aktualisieren.']);
    return;
}
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo $body;