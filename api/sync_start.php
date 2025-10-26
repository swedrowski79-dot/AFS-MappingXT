<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

/**
 * Check and perform GitHub update if enabled
 */
function checkGitHubUpdateApi(array $config): ?array
{
    $githubConfig = $config['github'] ?? [];
    $autoUpdate = $githubConfig['auto_update'] ?? false;
    
    if (!$autoUpdate) {
        return null;
    }
    
    $branch = $githubConfig['branch'] ?? '';
    $repoPath = $config['paths']['root'] ?? dirname(__DIR__);
    
    try {
        $updater = new AFS_GitHubUpdater($repoPath, $autoUpdate, $branch);
        $result = $updater->checkAndUpdate();
        
        return [
            'checked' => true,
            'updated' => $result['updated'] ?? false,
            'info' => $result['info'] ?? [],
            'message' => $result['message'] ?? null,
        ];
    } catch (Throwable $e) {
        // Don't fail the sync on update errors, just log
        return [
            'checked' => true,
            'updated' => false,
            'error' => $e->getMessage(),
        ];
    }
}

try {
    // Check for GitHub updates before starting sync
    $updateResult = checkGitHubUpdateApi($config);
    
    [$tracker, $evo, $mssql] = createSyncEnvironment($config, 'categories');
    $summary = $evo->syncAll();
    api_ok([
        'status' => $tracker->getStatus(),
        'summary' => $summary,
        'github_update' => $updateResult,
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
