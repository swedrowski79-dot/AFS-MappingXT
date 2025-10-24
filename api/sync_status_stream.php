<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;
$tracker = createStatusTracker($config, 'categories');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

echo 'data: ' . json_encode(['ok' => true, 'status' => $tracker->getStatus()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
flush();
