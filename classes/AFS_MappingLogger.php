<?php

declare(strict_types=1);

/**
 * AFS_MappingLogger - Unified JSON Logger for Mapping and Delta Operations
 * 
 * Provides consistent, structured logging to daily log files in JSON format.
 * Logs include mapping version, record counts, changes, duration, and context.
 */
class AFS_MappingLogger
{
    private string $logDir;
    private string $mappingVersion;
    private ?string $currentLogFile = null;
    private string $minLevel;
    
    private const LEVEL_PRIORITY = [
        'info' => 1,
        'warning' => 2,
        'error' => 3,
    ];

    /**
     * @param string $logDir Directory for log files (defaults to /logs)
     * @param string $mappingVersion Version identifier for the mapping configuration
     * @param string $minLevel Minimum log level to record ('info', 'warning', 'error')
     */
    public function __construct(string $logDir, string $mappingVersion = '1.0.0', string $minLevel = 'warning')
    {
        $this->logDir = rtrim($logDir, '/');
        $this->mappingVersion = $mappingVersion;
        $this->minLevel = strtolower($minLevel);
        $this->ensureLogDirectory();
    }

    /**
     * Log a mapping or delta operation
     * 
     * @param string $operation Operation type (e.g., 'sync_start', 'delta_export', 'stage_complete')
     * @param string $level Log level (info, warning, error)
     * @param string $message Human-readable message
     * @param array<string,mixed> $context Additional context data
     */
    public function log(string $operation, string $level, string $message, array $context = []): void
    {
        $levelLower = strtolower($level);
        
        // Filter based on minimum log level
        if (!$this->shouldLog($levelLower)) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('c'),
            'operation' => $operation,
            'level' => $levelLower,
            'message' => $message,
            'mapping_version' => $this->mappingVersion,
            'context' => $context,
        ];

        $this->writeToFile($logEntry);
    }

    /**
     * Log information message
     */
    public function info(string $operation, string $message, array $context = []): void
    {
        $this->log($operation, 'info', $message, $context);
    }

    /**
     * Log warning message
     */
    public function warning(string $operation, string $message, array $context = []): void
    {
        $this->log($operation, 'warning', $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $operation, string $message, array $context = []): void
    {
        $this->log($operation, 'error', $message, $context);
    }

    /**
     * Log the start of a sync operation
     * 
     * @param array<string,mixed> $context Additional context (e.g., config, parameters)
     */
    public function logSyncStart(array $context = []): void
    {
        $this->info('sync_start', 'Synchronisation gestartet', array_merge([
            'started_at' => date('c'),
        ], $context));
    }

    /**
     * Log the completion of a sync operation
     * 
     * @param float $duration Duration in seconds
     * @param array<string,mixed> $summary Summary statistics
     */
    public function logSyncComplete(float $duration, array $summary = []): void
    {
        $this->info('sync_complete', 'Synchronisation abgeschlossen', array_merge([
            'duration_seconds' => round($duration, 2),
            'duration_formatted' => $this->formatDuration($duration),
            'completed_at' => date('c'),
        ], $summary));
    }

    /**
     * Log a stage completion
     * 
     * @param string $stage Stage name (e.g., 'artikel', 'bilder', 'delta_export')
     * @param float $duration Duration in seconds
     * @param array<string,mixed> $statistics Stage-specific statistics
     */
    public function logStageComplete(string $stage, float $duration, array $statistics = []): void
    {
        $this->info('stage_complete', "Stage abgeschlossen: {$stage}", array_merge([
            'stage' => $stage,
            'duration_seconds' => round($duration, 2),
            'duration_formatted' => $this->formatDuration($duration),
        ], $statistics));
    }

    /**
     * Log delta export operation
     * 
     * @param float $duration Duration in seconds
     * @param array<string,int> $tableStats Table-wise export counts
     * @param int $totalRows Total rows exported
     * @param string $targetPath Target database path
     */
    public function logDeltaExport(float $duration, array $tableStats, int $totalRows, string $targetPath): void
    {
        $this->info('delta_export', 'Delta-Export abgeschlossen', [
            'duration_seconds' => round($duration, 2),
            'duration_formatted' => $this->formatDuration($duration),
            'total_tables' => count($tableStats),
            'total_rows' => $totalRows,
            'tables' => $tableStats,
            'target_path' => $targetPath,
        ]);
    }

    /**
     * Log record count changes
     * 
     * @param string $entity Entity name (e.g., 'Artikel', 'Bilder')
     * @param int $inserted Number of inserted records
     * @param int $updated Number of updated records
     * @param int $deleted Number of deleted/deactivated records
     * @param int $total Total records processed
     */
    public function logRecordChanges(string $entity, int $inserted, int $updated, int $deleted, int $total): void
    {
        $this->info('record_changes', "Datensatzänderungen: {$entity}", [
            'entity' => $entity,
            'inserted' => $inserted,
            'updated' => $updated,
            'deleted' => $deleted,
            'unchanged' => max(0, $total - $inserted - $updated - $deleted),
            'total_processed' => $total,
        ]);
    }

    /**
     * Log an error with optional exception details
     * 
     * @param string $operation Operation that failed
     * @param string $message Error message
     * @param \Throwable|null $exception Optional exception
     * @param array<string,mixed> $context Additional context
     */
    public function logError(string $operation, string $message, ?\Throwable $exception = null, array $context = []): void
    {
        $errorContext = $context;
        
        if ($exception !== null) {
            $errorContext['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        $this->error($operation, $message, $errorContext);
    }

    /**
     * Get the current log file path for today
     */
    public function getCurrentLogFile(): string
    {
        if ($this->currentLogFile === null) {
            $this->currentLogFile = $this->logDir . '/' . date('Y-m-d') . '.log';
        }
        return $this->currentLogFile;
    }

    /**
     * Rotate logs older than specified days
     * 
     * @param int $keepDays Number of days to keep logs (default: 30)
     * @return int Number of files deleted
     */
    public function rotateLogs(int $keepDays = 30): int
    {
        $deleted = 0;
        $cutoffTime = time() - ($keepDays * 86400);

        $files = glob($this->logDir . '/*.log');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Write log entry to file
     * 
     * @param array<string,mixed> $entry Log entry
     */
    private function writeToFile(array $entry): void
    {
        $logFile = $this->getCurrentLogFile();
        $jsonLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        // Use file locking for concurrent writes
        $fp = @fopen($logFile, 'a');
        if ($fp === false) {
            // Silently fail if we can't write logs
            return;
        }

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $jsonLine);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
    }

    /**
     * Ensure log directory exists and is writable
     */
    private function ensureLogDirectory(): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Format duration in human-readable format
     * 
     * @param float $seconds Duration in seconds
     * @return string Formatted duration (e.g., "1m 23s" or "45.2s")
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds - ($minutes * 60);

        if ($minutes < 60) {
            return sprintf('%dm %.0fs', $minutes, $secs);
        }

        $hours = floor($minutes / 60);
        $mins = $minutes - ($hours * 60);
        return sprintf('%dh %dm', $hours, $mins);
    }
    
    /**
     * Check if a message with the given level should be logged
     * 
     * @param string $level Log level to check
     * @return bool True if the message should be logged
     */
    private function shouldLog(string $level): bool
    {
        $minPriority = self::LEVEL_PRIORITY[$this->minLevel] ?? 1;
        $msgPriority = self::LEVEL_PRIORITY[$level] ?? 1;
        
        return $msgPriority >= $minPriority;
    }

    /**
     * Read log entries from a specific date
     * 
     * @param string $date Date in YYYY-MM-DD format (defaults to today)
     * @param int|null $limit Maximum number of entries to return
     * @return array<int,array<string,mixed>> Array of log entries
     */
    public function readLogs(string $date = '', int $limit = null): array
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $logFile = $this->logDir . '/' . $date . '.log';
        if (!is_file($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        if ($limit !== null && $limit > 0) {
            return array_slice($entries, -$limit);
        }

        return $entries;
    }
}
