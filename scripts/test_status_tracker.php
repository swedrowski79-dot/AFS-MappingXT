<?php
/**
 * Test script for STATUS_Tracker with SQLite_Connection
 */
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "Testing STATUS_Tracker with SQLite_Connection...\n\n";

try {
    $config = require __DIR__ . '/../config.php';
    $statusDbPath = $config['paths']['status_db'];
    
    echo "1. Creating STATUS_Tracker instance...\n";
    $tracker = new STATUS_Tracker($statusDbPath, 'test_job', 100, 'info');
    echo "   ✓ Tracker created successfully\n\n";
    
    echo "2. Testing begin stage...\n";
    $tracker->begin('initialization', 'Starting test');
    echo "   ✓ Stage started\n\n";
    
    echo "3. Testing logInfo...\n";
    $tracker->logInfo('Test info message', ['key' => 'value'], 'initialization');
    echo "   ✓ Info logged\n\n";
    
    echo "4. Testing logWarning...\n";
    $tracker->logWarning('Test warning message', ['warning' => 'test'], 'initialization');
    echo "   ✓ Warning logged\n\n";
    
    echo "5. Testing logError...\n";
    $tracker->logError('Test error message', ['error' => 'test'], 'initialization');
    echo "   ✓ Error logged\n\n";
    
    echo "6. Testing advance...\n";
    $tracker->advance('processing', ['processed' => 50, 'total' => 100]);
    echo "   ✓ Progress advanced\n\n";
    
    echo "7. Getting status...\n";
    $status = $tracker->getStatus();
    echo "   ✓ Status retrieved:\n";
    echo "      Job: " . $status['job'] . "\n";
    echo "      State: " . $status['state'] . "\n";
    echo "      Stage: " . $status['stage'] . "\n";
    echo "      Processed: " . $status['processed'] . " / " . $status['total'] . "\n\n";
    
    echo "8. Getting logs...\n";
    $logs = $tracker->getLogs(10);
    echo "   ✓ Found " . count($logs) . " log entries\n\n";
    
    echo "9. Testing complete...\n";
    $tracker->complete(['message' => 'Test completed']);
    echo "   ✓ Job completed\n\n";
    
    echo "10. Clearing logs...\n";
    $tracker->clearLog();
    echo "   ✓ Logs cleared\n\n";
    
    echo "All tests passed! ✓\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
