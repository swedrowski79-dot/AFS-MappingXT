<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    // Auto-update is now handled in _bootstrap.php
    $updateResult = $GLOBALS['auto_update_result'] ?? null;
    
    $raw = file_get_contents('php://input') ?: '';
    $body = $raw !== '' ? json_decode($raw, true) : [];
    $mapping = is_array($body) && isset($body['mapping']) && is_string($body['mapping']) ? $body['mapping'] : null;

    if ($mapping === null || $mapping === '') {
        api_error('Kein Mapping angegeben', 400);
    }

    $service = new SyncService($config);
    $result = $service->run($mapping);

    api_ok([
        'status' => $result['status'] ?? [],
        'summary' => $result['summary'] ?? [],
        'duration_seconds' => $result['duration_seconds'] ?? 0,
        'github_update' => $updateResult,
    ]);
} catch (AFS_SyncBusyException $e) {
    try {
        $tracker = createStatusTracker($config, 'categories');
        $status = $tracker->getStatus();
        api_json(['ok' => false, 'error' => $e->getMessage(), 'data' => ['status' => $status]], 409);
    } catch (Throwable $te) {
        api_error($e->getMessage(), 409);
    }
} catch (\Throwable $e) {
    if (isset($tracker)) {
        $tracker->logError($e->getMessage(), ['endpoint' => 'sync_start'], 'api');
        $tracker->fail($e->getMessage(), 'api');
    }
    api_error($e->getMessage());
} finally {
    if (isset($mssql) && $mssql instanceof MSSQL_Connection) {
        $mssql->close();
    }
}
