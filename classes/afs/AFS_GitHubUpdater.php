<?php
declare(strict_types=1);

/**
 * AFS_GitHubUpdater
 * 
 * Handles automatic updates from GitHub repository.
 * Checks for updates and performs git pull when starting the application.
 * 
 * Features:
 * - Check for updates from GitHub
 * - Automatic git pull to update application
 * - Protects .env file (via .gitignore)
 * - Configurable via environment variables
 */
class AFS_GitHubUpdater
{
    private string $repoPath;
    private bool $autoUpdate;
    private string $branch;
    
    /**
     * @param string $repoPath Path to the repository root
     * @param bool $autoUpdate Whether to automatically update (default: false)
     * @param string $branch Branch to update from (default: current branch)
     */
    public function __construct(string $repoPath, bool $autoUpdate = false, string $branch = '')
    {
        $this->repoPath = rtrim($repoPath, '/');
        $this->autoUpdate = $autoUpdate;
        $this->branch = $branch;
        
        if (!is_dir($this->repoPath . '/.git')) {
            throw new RuntimeException("Not a git repository: {$this->repoPath}");
        }
    }
    
    /**
     * Check if updates are available from GitHub
     * 
     * @return array{available: bool, current_commit: string, remote_commit: string, commits_behind: int}
     */
    public function checkForUpdates(): array
    {
        try {
            // Get current branch if not specified
            $currentBranch = $this->branch;
            if ($currentBranch === '') {
                $currentBranch = $this->getCurrentBranch();
            }
            
            // Fetch from remote (without pulling)
            $this->execGit('fetch origin');
            
            // Get current local commit
            $currentCommit = $this->execGit('rev-parse HEAD');
            
            // Get remote commit
            $remoteCommit = $this->execGit("rev-parse origin/{$currentBranch}");
            
            // Count commits behind
            $commitsBehind = 0;
            if ($currentCommit !== $remoteCommit) {
                $revList = $this->execGit("rev-list --count HEAD..origin/{$currentBranch}");
                $commitsBehind = (int)$revList;
            }
            
            return [
                'available' => $currentCommit !== $remoteCommit,
                'current_commit' => substr($currentCommit, 0, 7),
                'remote_commit' => substr($remoteCommit, 0, 7),
                'commits_behind' => $commitsBehind,
                'branch' => $currentBranch,
            ];
        } catch (RuntimeException $e) {
            // If we can't check for updates, return no updates available
            return [
                'available' => false,
                'current_commit' => '',
                'remote_commit' => '',
                'commits_behind' => 0,
                'branch' => '',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Perform git pull to update the application
     * 
     * @return array{success: bool, message: string, output?: string}
     */
    public function performUpdate(): array
    {
        try {
            // Check for local changes
            $status = $this->execGit('status --porcelain');
            if (!empty(trim($status))) {
                return [
                    'success' => false,
                    'message' => 'Es gibt lokale Änderungen im Repository. Update abgebrochen.',
                    'output' => $status,
                ];
            }
            
            // Get current branch
            $currentBranch = $this->branch ?: $this->getCurrentBranch();
            
            // Perform git pull
            $output = $this->execGit("pull origin {$currentBranch}");
            
            return [
                'success' => true,
                'message' => 'Update erfolgreich durchgeführt.',
                'output' => $output,
            ];
        } catch (RuntimeException $e) {
            return [
                'success' => false,
                'message' => 'Update fehlgeschlagen: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check for updates and automatically update if enabled
     * 
     * @return array{checked: bool, updated: bool, info: array, message?: string, result?: array}
     */
    public function checkAndUpdate(): array
    {
        $updateInfo = $this->checkForUpdates();
        
        if (!$updateInfo['available']) {
            return [
                'checked' => true,
                'updated' => false,
                'info' => $updateInfo,
            ];
        }
        
        if (!$this->autoUpdate) {
            return [
                'checked' => true,
                'updated' => false,
                'info' => $updateInfo,
                'message' => 'Updates verfügbar, aber Auto-Update ist deaktiviert.',
            ];
        }
        
        // Perform update
        $updateResult = $this->performUpdate();
        
        return [
            'checked' => true,
            'updated' => $updateResult['success'],
            'info' => $updateInfo,
            'result' => $updateResult,
            'message' => $updateResult['message'] ?? null,
        ];
    }
    
    /**
     * Get the current git branch name
     * 
     * @return string
     */
    private function getCurrentBranch(): string
    {
        return $this->execGit('rev-parse --abbrev-ref HEAD');
    }
    
    /**
     * Execute a git command
     * 
     * @param string $command Git command (without 'git' prefix)
     * @return string Command output
     * @throws RuntimeException On command failure
     */
    private function execGit(string $command): string
    {
        $fullCommand = sprintf(
            'cd %s && git %s 2>&1',
            escapeshellarg($this->repoPath),
            $command
        );
        
        $output = [];
        $returnCode = 0;
        exec($fullCommand, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new RuntimeException("Git command failed: {$command}\n" . implode("\n", $output));
        }
        
        return trim(implode("\n", $output));
    }
}
