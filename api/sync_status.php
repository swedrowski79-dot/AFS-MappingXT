<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;
$tracker = createStatusTracker($config, 'categories');

api_ok([
    'status' => $tracker->getStatus(),
]);
