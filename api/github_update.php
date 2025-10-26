<?php
declare(strict_types=1);

/**
 * GitHub Update API Endpoint
 * 
 * Handles checking for updates and performing automatic updates from GitHub.
 * 
 * Methods:
 * - GET: Check for available updates (returns update info)
 * - POST: Perform update (if auto-update is enabled or forced)
 */

require_once __DIR__ . '/_bootstrap.php';

global $config;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$githubConfig = $config['github'] ?? [];
$autoUpdate = $githubConfig['auto_update'] ?? false;
$branch = $githubConfig['branch'] ?? '';
$repoPath = $config['paths']['root'] ?? __DIR__ . '/..';

try {
    $updater = new AFS_GitHubUpdater($repoPath, $autoUpdate, $branch);
    
    if ($method === 'GET') {
        // Check for updates
        $updateInfo = $updater->checkForUpdates();
        
        api_ok([
            'auto_update_enabled' => $autoUpdate,
            'update_info' => $updateInfo,
        ]);
    } elseif ($method === 'POST') {
        // Perform update
        $forceUpdate = isset($_POST['force']) && $_POST['force'];
        
        if (!$autoUpdate && !$forceUpdate) {
            api_error('Auto-Update ist deaktiviert. Aktivieren Sie AFS_GITHUB_AUTO_UPDATE in der .env-Datei oder verwenden Sie force=1.', 403);
        }
        
        // Use force parameter to override auto_update setting
        if ($forceUpdate) {
            $updater = new AFS_GitHubUpdater($repoPath, true, $branch);
        }
        
        $result = $updater->checkAndUpdate();
        
        api_ok([
            'result' => $result,
        ]);
    } else {
        api_error('Methode nicht erlaubt', 405);
    }
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 500);
} catch (Throwable $e) {
    api_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}
