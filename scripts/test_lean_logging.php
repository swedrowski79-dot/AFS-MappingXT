#!/usr/bin/env php
<?php
/**
 * Test script for lean logging functionality
 * 
 * Validates:
 * 1. Log level filtering works correctly
 * 2. INFO messages are filtered when log_level is 'warning'
 * 3. WARNING and ERROR messages are always logged
 * 4. Sample sizes are appropriately reduced
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== Lean Logging Test ===\n\n";

// Create temporary test directory
$testLogDir = sys_get_temp_dir() . '/test_lean_logs_' . uniqid();
@mkdir($testLogDir, 0755, true);

try {
    // Test 1: Log level filtering with 'warning' level
    echo "1. Testing log level filtering (level: warning)...\n";
    $logger = new AFS_MappingLogger($testLogDir, '1.0.0', 'warning');
    
    // These should be filtered out
    $logger->info('test_operation', 'Info message - should be filtered', ['data' => 'test']);
    $logger->logSyncStart(['test' => 'data']);  // info level
    $logger->logStageComplete('test_stage', 10.5, ['records' => 100]);  // info level
    
    // These should be logged
    $logger->warning('test_operation', 'Warning message - should be logged', ['warning' => 'data']);
    $logger->error('test_operation', 'Error message - should be logged', ['error' => 'data']);
    
    $logEntries = $logger->readLogs();
    $expectedCount = 2; // Only warning and error
    
    if (count($logEntries) !== $expectedCount) {
        echo "   ✗ ERROR: Expected {$expectedCount} log entries (warning + error only), got " . count($logEntries) . "\n";
        echo "   Log entries: " . json_encode($logEntries, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
    echo "   ✓ Log level filtering works - only WARNING and ERROR logged\n";
    
    // Verify the logged entries are correct levels
    $levels = array_column($logEntries, 'level');
    if (!in_array('warning', $levels, true) || !in_array('error', $levels, true)) {
        echo "   ✗ ERROR: Expected both 'warning' and 'error' levels in logs\n";
        exit(1);
    }
    echo "   ✓ Correct log levels present\n";
    
    // Test 2: Log level filtering with 'error' level
    echo "\n2. Testing log level filtering (level: error)...\n";
    $testLogDir2 = sys_get_temp_dir() . '/test_lean_logs2_' . uniqid();
    @mkdir($testLogDir2, 0755, true);
    $logger2 = new AFS_MappingLogger($testLogDir2, '1.0.0', 'error');
    
    $logger2->info('test', 'Info - filtered');
    $logger2->warning('test', 'Warning - filtered');
    $logger2->error('test', 'Error - logged');
    
    $logEntries2 = $logger2->readLogs();
    if (count($logEntries2) !== 1) {
        echo "   ✗ ERROR: Expected 1 log entry (error only), got " . count($logEntries2) . "\n";
        exit(1);
    }
    if ($logEntries2[0]['level'] !== 'error') {
        echo "   ✗ ERROR: Expected 'error' level, got '" . $logEntries2[0]['level'] . "'\n";
        exit(1);
    }
    echo "   ✓ Error-only logging works correctly\n";
    
    // Test 3: Log level filtering with 'info' level (verbose)
    echo "\n3. Testing log level filtering (level: info - verbose)...\n";
    $testLogDir3 = sys_get_temp_dir() . '/test_lean_logs3_' . uniqid();
    @mkdir($testLogDir3, 0755, true);
    $logger3 = new AFS_MappingLogger($testLogDir3, '1.0.0', 'info');
    
    $logger3->info('test', 'Info - logged');
    $logger3->warning('test', 'Warning - logged');
    $logger3->error('test', 'Error - logged');
    
    $logEntries3 = $logger3->readLogs();
    if (count($logEntries3) !== 3) {
        echo "   ✗ ERROR: Expected 3 log entries (all levels), got " . count($logEntries3) . "\n";
        exit(1);
    }
    echo "   ✓ Verbose logging works - all levels logged\n";
    
    // Test 4: StatusTracker log level filtering
    echo "\n4. Testing StatusTracker log level filtering...\n";
    $statusDbPath = sys_get_temp_dir() . '/test_status_' . uniqid() . '.db';
    
    // Create a simple status database
    $db = new PDO('sqlite:' . $statusDbPath);
    $db->exec('CREATE TABLE IF NOT EXISTS sync_status (
        job TEXT PRIMARY KEY,
        state TEXT,
        stage TEXT,
        message TEXT,
        processed INTEGER DEFAULT 0,
        total INTEGER DEFAULT 0,
        started_at TEXT,
        updated_at TEXT,
        finished_at TEXT
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job TEXT NOT NULL,
        level TEXT NOT NULL,
        stage TEXT,
        message TEXT NOT NULL,
        context TEXT,
        created_at TEXT NOT NULL
    )');
    
    $tracker = new AFS_Evo_StatusTracker($statusDbPath, 'test_job', 200, 'warning');
    
    // These should be filtered
    $tracker->logInfo('Info message - should be filtered', [], 'test_stage');
    
    // These should be logged
    $tracker->logWarning('Warning message - should be logged', [], 'test_stage');
    $tracker->logError('Error message - should be logged', [], 'test_stage');
    
    $logs = $tracker->getLogs(100);
    if (count($logs) !== 2) {
        echo "   ✗ ERROR: Expected 2 log entries in StatusTracker, got " . count($logs) . "\n";
        exit(1);
    }
    echo "   ✓ StatusTracker log level filtering works\n";
    
    // Test 5: Verify ERROR_SAMPLE_SIZE is reduced
    echo "\n5. Testing reduced sample size constant...\n";
    $reflection = new ReflectionClass('AFS_Evo_Base');
    $constants = $reflection->getConstants();
    $sampleSize = $constants['ERROR_SAMPLE_SIZE'] ?? null;
    
    if ($sampleSize !== 5) {
        echo "   ✗ ERROR: Expected ERROR_SAMPLE_SIZE to be 5, got {$sampleSize}\n";
        exit(1);
    }
    echo "   ✓ ERROR_SAMPLE_SIZE is now 5 (reduced from 12)\n";
    
    echo "\n✓ All tests passed!\n";
    echo "\nSummary:\n";
    echo "  - Log level filtering works for MappingLogger\n";
    echo "  - Log level filtering works for StatusTracker\n";
    echo "  - INFO messages are filtered with 'warning' level\n";
    echo "  - WARNING and ERROR always pass through\n";
    echo "  - Sample sizes reduced for lean logging\n";
    
} catch (\Throwable $e) {
    echo "\n✗ Test failed with exception:\n";
    echo "  " . $e->getMessage() . "\n";
    echo "  in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
} finally {
    // Cleanup
    if (isset($testLogDir) && is_dir($testLogDir)) {
        array_map('unlink', glob($testLogDir . '/*'));
        @rmdir($testLogDir);
    }
    if (isset($testLogDir2) && is_dir($testLogDir2)) {
        array_map('unlink', glob($testLogDir2 . '/*'));
        @rmdir($testLogDir2);
    }
    if (isset($testLogDir3) && is_dir($testLogDir3)) {
        array_map('unlink', glob($testLogDir3 . '/*'));
        @rmdir($testLogDir3);
    }
    if (isset($statusDbPath) && file_exists($statusDbPath)) {
        @unlink($statusDbPath);
    }
}
