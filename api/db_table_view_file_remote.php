<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$serverIndex = isset($_GET['server_index']) ? (int)$_GET['server_index'] : -1;
$connId = isset($_GET['conn_id']) ? (string)$_GET['conn_id'] : '';
$table  = isset($_GET['table']) ? (string)$_GET['table'] : '';
$limit  = isset($_GET['limit']) ? (string)$_GET['limit'] : '100';
$page   = isset($_GET['page']) ? (string)$_GET['page'] : '1';

if ($serverIndex < 0 || $connId === '' || $table === '') {
    api_error('Parameter fehlen', 400);
}

global $config;
$servers = $config['remote_servers']['servers'] ?? [];
if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden', 404);
}
$remote = $servers[$serverIndex];
$target = rtrim((string)$remote['url'], '/') . '/api/db_table_view_file.php?' . http_build_query([
    'conn_id' => $connId,
    'table'   => $table,
    'limit'   => $limit,
    'page'    => $page,
]);

$ch = curl_init($target);
$headers = ['Accept: text/html'];
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

http_response_code($status);
header('Content-Type: text/html; charset=utf-8');
echo $body !== false ? $body : 'Remote-Anfrage fehlgeschlagen: ' . htmlspecialchars($error ?: 'Unbekannter Fehler', ENT_QUOTES, 'UTF-8');
