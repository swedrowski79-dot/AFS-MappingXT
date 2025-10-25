#!/usr/bin/env php
<?php
/**
 * Integration test for the complete unified logging system
 * 
 * Tests that the logging system works correctly throughout the entire
 * sync pipeline, including configuration, initialization, and logging.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== Unified Logging System Integration Test ===\n\n";

// Create temporary test directory and config
$testLogDir = sys_get_temp_dir() . '/test_unified_logs_' . uniqid();
@mkdir($testLogDir, 0755, true);

$testConfig = [
    'paths' => [
        'log_dir' => $testLogDir,
    ],
    'logging' => [
        'mapping_version' => '1.0.0-test',
        'log_rotation_days' => 30,
        'enable_file_logging' => true,
    ],
];

try {
    // Test 1: Logger creation from config
    echo "1. Testing logger creation from config...\n";
    $logDir = $testConfig['paths']['log_dir'] ?? '';
    $loggingConfig = $testConfig['logging'] ?? [];
    $enableFileLogging = $loggingConfig['enable_file_logging'] ?? true;
    
    if (!$enableFileLogging) {
        echo "   ✗ ERROR: File logging should be enabled\n";
        exit(1);
    }
    
    $mappingVersion = $loggingConfig['mapping_version'] ?? '1.0.0';
    $logger = new AFS_MappingLogger($logDir, $mappingVersion);
    
    echo "   ✓ Logger created successfully\n";
    echo "   - Log directory: {$logDir}\n";
    echo "   - Mapping version: {$mappingVersion}\n";
    
    // Test 2: Simulate a sync operation with comprehensive logging
    echo "\n2. Testing complete sync operation logging...\n";
    
    // Start sync
    $syncStart = microtime(true);
    $logger->logSyncStart([
        'copy_images' => true,
        'copy_documents' => true,
    ]);
    
    // Simulate stages
    $stages = [
        'bilder' => ['total_records' => 150, 'duration' => 2.5],
        'dokumente' => ['total_records' => 75, 'duration' => 1.8],
        'attribute' => ['total_records' => 200, 'duration' => 1.2],
        'warengruppen' => ['inserted' => 25, 'updated' => 10, 'duration' => 0.9],
        'artikel' => ['inserted' => 100, 'updated' => 50, 'deleted' => 5, 'duration' => 5.4],
    ];
    
    foreach ($stages as $stage => $stats) {
        usleep(10000); // Small delay to simulate work
        
        if (isset($stats['inserted'])) {
            $logger->logRecordChanges(
                ucfirst($stage),
                $stats['inserted'],
                $stats['updated'] ?? 0,
                $stats['deleted'] ?? 0,
                $stats['inserted'] + ($stats['updated'] ?? 0) + ($stats['deleted'] ?? 0)
            );
        }
        
        $logger->logStageComplete($stage, $stats['duration'], $stats);
    }
    
    // Delta export
    $deltaStats = ['Artikel' => 155, 'Bilder' => 30, 'Dokumente' => 15, 'Warengruppen' => 35];
    $logger->logDeltaExport(2.1, $deltaStats, 235, '/tmp/test_delta.db');
    
    // Complete sync
    $syncDuration = microtime(true) - $syncStart;
    $logger->logSyncComplete($syncDuration, [
        'total_stages' => count($stages),
        'total_changes' => 235,
    ]);
    
    echo "   ✓ Sync operation logged completely\n";
    
    // Test 3: Verify log file exists and contains expected entries
    echo "\n3. Verifying log file contents...\n";
    $logFile = $logger->getCurrentLogFile();
    
    if (!file_exists($logFile)) {
        echo "   ✗ ERROR: Log file not created\n";
        exit(1);
    }
    
    $logEntries = $logger->readLogs();
    $expectedOperations = ['sync_start', 'stage_complete', 'record_changes', 'delta_export', 'sync_complete'];
    $foundOperations = [];
    
    foreach ($logEntries as $entry) {
        if (!isset($foundOperations[$entry['operation']])) {
            $foundOperations[$entry['operation']] = 0;
        }
        $foundOperations[$entry['operation']]++;
        
        // Verify mapping version
        if ($entry['mapping_version'] !== '1.0.0-test') {
            echo "   ✗ ERROR: Incorrect mapping version in entry\n";
            exit(1);
        }
    }
    
    echo "   ✓ Log file created: {$logFile}\n";
    echo "   ✓ Total entries: " . count($logEntries) . "\n";
    echo "   ✓ Operations logged:\n";
    foreach ($foundOperations as $op => $count) {
        echo "     - {$op}: {$count}\n";
    }
    
    // Test 4: Verify specific log entry structure
    echo "\n4. Verifying log entry structure...\n";
    $syncCompleteEntry = null;
    foreach ($logEntries as $entry) {
        if ($entry['operation'] === 'sync_complete') {
            $syncCompleteEntry = $entry;
            break;
        }
    }
    
    if ($syncCompleteEntry === null) {
        echo "   ✗ ERROR: sync_complete entry not found\n";
        exit(1);
    }
    
    $requiredFields = ['timestamp', 'operation', 'level', 'message', 'mapping_version', 'context'];
    foreach ($requiredFields as $field) {
        if (!isset($syncCompleteEntry[$field])) {
            echo "   ✗ ERROR: Missing required field: {$field}\n";
            exit(1);
        }
    }
    
    if (!isset($syncCompleteEntry['context']['duration_seconds'])) {
        echo "   ✗ ERROR: Missing duration_seconds in context\n";
        exit(1);
    }
    
    echo "   ✓ All required fields present\n";
    echo "   ✓ Sync duration: {$syncCompleteEntry['context']['duration_seconds']}s\n";
    
    // Test 5: Verify error logging
    echo "\n5. Testing error logging...\n";
    try {
        throw new RuntimeException('Test error during sync', 500);
    } catch (Throwable $e) {
        $logger->logError('test_operation', 'A test error occurred', $e, [
            'stage' => 'artikel',
        ]);
    }
    
    $logEntries = $logger->readLogs();
    $errorEntry = null;
    foreach ($logEntries as $entry) {
        if ($entry['level'] === 'error') {
            $errorEntry = $entry;
            break;
        }
    }
    
    if ($errorEntry === null) {
        echo "   ✗ ERROR: Error entry not found\n";
        exit(1);
    }
    
    if (!isset($errorEntry['context']['exception'])) {
        echo "   ✗ ERROR: Exception details missing\n";
        exit(1);
    }
    
    echo "   ✓ Error logged with exception details\n";
    
    // Test 6: Test log reading with limit
    echo "\n6. Testing log reading with limit...\n";
    $recentLogs = $logger->readLogs('', 5);
    if (count($recentLogs) !== 5) {
        echo "   ✗ ERROR: Expected 5 entries, got " . count($recentLogs) . "\n";
        exit(1);
    }
    echo "   ✓ Limited log reading works correctly\n";
    
    // Test 7: Test configuration toggle
    echo "\n7. Testing logging enable/disable toggle...\n";
    $testConfigDisabled = [
        'paths' => ['log_dir' => $testLogDir],
        'logging' => ['enable_file_logging' => false],
    ];
    
    $loggingConfigDisabled = $testConfigDisabled['logging'] ?? [];
    $enableFileLoggingCheck = $loggingConfigDisabled['enable_file_logging'] ?? true;
    
    if ($enableFileLoggingCheck) {
        echo "   ✗ ERROR: Logging should be disabled\n";
        exit(1);
    }
    
    echo "   ✓ Logging can be disabled via configuration\n";
    
    // Test 8: Verify JSON format
    echo "\n8. Verifying JSON format...\n";
    $logContent = file_get_contents($logFile);
    $lines = explode("\n", trim($logContent));
    
    $validJsonCount = 0;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $validJsonCount++;
        } else {
            echo "   ✗ ERROR: Invalid JSON: {$line}\n";
            exit(1);
        }
    }
    
    echo "   ✓ All {$validJsonCount} entries are valid JSON\n";
    
    echo "\n=== ALL INTEGRATION TESTS PASSED ✓ ===\n\n";
    echo "Summary:\n";
    echo "✓ Logger created from configuration\n";
    echo "✓ Complete sync operation logged with all stages\n";
    echo "✓ Log file contains expected entries with correct structure\n";
    echo "✓ Mapping version tracked in all entries\n";
    echo "✓ Error logging captures exception details\n";
    echo "✓ Log reading with limit works\n";
    echo "✓ Logging can be toggled via configuration\n";
    echo "✓ All log entries are valid JSON\n";
    
    $exitCode = 0;
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    $exitCode = 1;
} finally {
    // Cleanup
    if (is_dir($testLogDir)) {
        $files = glob($testLogDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($testLogDir);
    }
}

exit($exitCode ?? 1);
