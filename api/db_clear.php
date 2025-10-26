<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    $tracker = createStatusTracker($config, 'categories');
    $pdo = createEvoPdo($config);
    $result = EVO_Reset::clear($pdo);

    $tracker->logInfo('EVO-Datenbank geleert', ['tables' => $result], 'maintenance');

    api_ok(['tables' => $result]);
} catch (\Throwable $e) {
    if (isset($tracker) && $tracker instanceof STATUS_Tracker) {
        $tracker->logError('Fehler beim Leeren der EVO-Datenbank', [
            'error' => $e->getMessage(),
        ], 'maintenance');
    }
    api_error($e->getMessage());
}

