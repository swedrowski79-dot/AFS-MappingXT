<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;
$tracker = createStatusTracker($config, 'categories');

// Security headers for SSE
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// Remove server signature
header_remove('X-Powered-By');
header_remove('Server');

echo 'data: ' . json_encode(['ok' => true, 'status' => $tracker->getStatus()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
flush();
