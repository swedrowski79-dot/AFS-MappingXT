<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    [$tracker, $evo, $mssql] = createSyncEnvironment($config, 'categories');
    $summary = $evo->syncAll();
    api_ok([
        'status' => $tracker->getStatus(),
        'summary' => $summary,
    ]);
} catch (AFS_SyncBusyException $e) {
    api_error($e->getMessage(), 409);
} catch (\Throwable $e) {
    if (isset($tracker)) {
        $tracker->logError($e->getMessage(), ['endpoint' => 'sync_start'], 'api');
        $tracker->fail($e->getMessage(), 'api');
    }
    api_error($e->getMessage());
} finally {
    if (isset($mssql) && $mssql instanceof MSSQL) {
        $mssql->close();
    }
}
