<?php
// Simple health check endpoint for Docker healthcheck
// Returns 200 OK if PHP-FPM is working

declare(strict_types=1);

header('Content-Type: application/json');

$health = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
];

http_response_code(200);
echo json_encode($health);
