<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    $tracker = createStatusTracker($config, 'categories');
    $tracker->updateStatus([
        'state' => 'ready',
        'stage' => null,
        'message' => 'Bereit für Synchronisation',
        'processed' => 0,
        'total' => 0,
        'started_at' => null,
        'finished_at' => null,
    ]);

    api_ok([
        'status' => $tracker->getStatus(),
    ]);
} catch (\Throwable $e) {
    api_error($e->getMessage());
}
