#!/usr/bin/env php
<?php
/**
 * Example script demonstrating the unified logging system
 * 
 * This script shows how to use AFS_MappingLogger for custom operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

// Load configuration
$config = require __DIR__ . '/../config.php';

// Initialize logger
$logDir = $config['paths']['log_dir'] ?? __DIR__ . '/../logs';
$mappingVersion = $config['logging']['mapping_version'] ?? '1.0.0';
$logger = new AFS_MappingLogger($logDir, $mappingVersion);

echo "=== Unified Logging System Example ===\n\n";
echo "Log directory: {$logDir}\n";
echo "Mapping version: {$mappingVersion}\n";
echo "Today's log file: {$logger->getCurrentLogFile()}\n\n";

// Example 1: Simple info logging
echo "Example 1: Simple info message\n";
$logger->info('example_operation', 'This is an example info message', [
    'user' => 'admin',
    'action' => 'test',
]);
echo "✓ Info message logged\n\n";

// Example 2: Warning logging
echo "Example 2: Warning message\n";
$logger->warning('example_operation', 'This is an example warning', [
    'warning_type' => 'configuration',
    'details' => 'Some setting is missing',
]);
echo "✓ Warning logged\n\n";

// Example 3: Error logging with exception
echo "Example 3: Error with exception\n";
try {
    throw new RuntimeException('Example error for demonstration', 500);
} catch (Throwable $e) {
    $logger->logError('example_operation', 'An error occurred', $e, [
        'context' => 'example script',
    ]);
    echo "✓ Error with exception logged\n\n";
}

// Example 4: Stage completion
echo "Example 4: Stage completion\n";
$logger->logStageComplete('example_stage', 2.5, [
    'records_processed' => 150,
    'records_changed' => 25,
]);
echo "✓ Stage completion logged\n\n";

// Example 5: Record changes
echo "Example 5: Record changes\n";
$logger->logRecordChanges('ExampleEntity', 10, 5, 2, 17);
echo "✓ Record changes logged\n\n";

// Example 6: Read recent logs
echo "Example 6: Reading recent logs\n";
$recentLogs = $logger->readLogs('', 5);
echo "Found " . count($recentLogs) . " recent entries:\n";
foreach ($recentLogs as $i => $entry) {
    echo "  " . ($i + 1) . ". [{$entry['level']}] {$entry['operation']}: {$entry['message']}\n";
}
echo "\n";

// Example 7: Log rotation (dry run)
echo "Example 7: Log rotation\n";
echo "Note: This would delete logs older than 30 days\n";
echo "Current retention: " . ($config['logging']['log_rotation_days'] ?? 30) . " days\n";
// Uncomment to actually rotate:
// $deleted = $logger->rotateLogs(30);
// echo "Deleted {$deleted} old log files\n";
echo "\n";

echo "=== Examples completed ===\n";
echo "Check the log file at: {$logger->getCurrentLogFile()}\n";
echo "\nView logs with:\n";
echo "  cat {$logger->getCurrentLogFile()} | jq .\n";
echo "  cat {$logger->getCurrentLogFile()} | jq 'select(.level == \"error\")'\n";
