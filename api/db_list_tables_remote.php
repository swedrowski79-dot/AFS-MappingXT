<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$serverIndex = isset($_GET['server_index']) ? (int)$_GET['server_index'] : -1;
if ($serverIndex < 0) {
    api_error('server_index erforderlich', 400);
}

global $config;
$servers = $config['remote_servers']['servers'] ?? [];
if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden', 404);
}
$remote = $servers[$serverIndex];
$target = rtrim((string)$remote['url'], '/') . '/api/db_list_tables.php';

$params = [];
if (isset($_GET['db'])) $params['db'] = (string)$_GET['db'];
if (isset($_GET['sqlite_path'])) $params['sqlite_path'] = (string)$_GET['sqlite_path'];
if ($params) $target .= '?' . http_build_query($params);

$ch = curl_init($target);
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
$body = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 500;
curl_close($ch);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo $body !== false ? $body : json_encode(['ok' => false, 'error' => $error ?: 'Unbekannter Fehler']);
