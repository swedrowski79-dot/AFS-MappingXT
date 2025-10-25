#!/usr/bin/env php
<?php
/**
 * Test script for standardized exception handling
 * 
 * Tests:
 * - Custom exception classes exist and work correctly
 * - Exception hierarchy is correct
 * - Exception messages are properly preserved
 * - Exception chaining works
 */

require_once __DIR__ . '/../autoload.php';

echo "=== Exception Handling Test ===\n\n";

$testsPassed = 0;
$testsFailed = 0;

function runTest(string $name, callable $test): void
{
    global $testsPassed, $testsFailed;
    
    try {
        $test();
        echo "✓ {$name}\n";
        $testsPassed++;
    } catch (Throwable $e) {
        echo "✗ {$name}\n";
        echo "  Error: {$e->getMessage()}\n";
        $testsFailed++;
    }
}

// Test 1: AFS_SyncBusyException exists and extends RuntimeException
runTest('AFS_SyncBusyException exists and extends RuntimeException', function() {
    if (!class_exists('AFS_SyncBusyException')) {
        throw new Exception('AFS_SyncBusyException class not found');
    }
    
    $exception = new AFS_SyncBusyException('Test message');
    if (!($exception instanceof RuntimeException)) {
        throw new Exception('AFS_SyncBusyException does not extend RuntimeException');
    }
    
    if ($exception->getMessage() !== 'Test message') {
        throw new Exception('Exception message not preserved');
    }
});

// Test 2: AFS_ConfigurationException exists and extends RuntimeException
runTest('AFS_ConfigurationException exists and extends RuntimeException', function() {
    if (!class_exists('AFS_ConfigurationException')) {
        throw new Exception('AFS_ConfigurationException class not found');
    }
    
    $exception = new AFS_ConfigurationException('Config error');
    if (!($exception instanceof RuntimeException)) {
        throw new Exception('AFS_ConfigurationException does not extend RuntimeException');
    }
    
    if ($exception->getMessage() !== 'Config error') {
        throw new Exception('Exception message not preserved');
    }
});

// Test 3: AFS_DatabaseException exists and extends RuntimeException
runTest('AFS_DatabaseException exists and extends RuntimeException', function() {
    if (!class_exists('AFS_DatabaseException')) {
        throw new Exception('AFS_DatabaseException class not found');
    }
    
    $exception = new AFS_DatabaseException('Database error');
    if (!($exception instanceof RuntimeException)) {
        throw new Exception('AFS_DatabaseException does not extend RuntimeException');
    }
    
    if ($exception->getMessage() !== 'Database error') {
        throw new Exception('Exception message not preserved');
    }
});

// Test 4: AFS_ValidationException exists and extends InvalidArgumentException
runTest('AFS_ValidationException exists and extends InvalidArgumentException', function() {
    if (!class_exists('AFS_ValidationException')) {
        throw new Exception('AFS_ValidationException class not found');
    }
    
    $exception = new AFS_ValidationException('Validation error');
    if (!($exception instanceof InvalidArgumentException)) {
        throw new Exception('AFS_ValidationException does not extend InvalidArgumentException');
    }
    
    if ($exception->getMessage() !== 'Validation error') {
        throw new Exception('Exception message not preserved');
    }
});

// Test 5: Exception chaining works
runTest('Exception chaining preserves previous exception', function() {
    $original = new Exception('Original error');
    $wrapped = new AFS_DatabaseException('Wrapped error', 0, $original);
    
    if ($wrapped->getPrevious() !== $original) {
        throw new Exception('Exception chaining does not work');
    }
    
    if ($wrapped->getPrevious()->getMessage() !== 'Original error') {
        throw new Exception('Previous exception message not preserved');
    }
});

// Test 6: All custom exceptions can be caught as Throwable
runTest('All custom exceptions can be caught as Throwable', function() {
    $exceptions = [
        new AFS_SyncBusyException('Sync busy'),
        new AFS_ConfigurationException('Config error'),
        new AFS_DatabaseException('Database error'),
        new AFS_ValidationException('Validation error'),
    ];
    
    foreach ($exceptions as $exception) {
        if (!($exception instanceof Throwable)) {
            throw new Exception(get_class($exception) . ' is not instanceof Throwable');
        }
    }
});

// Test 7: AFS_MappingConfig throws AFS_ConfigurationException for missing file
runTest('AFS_MappingConfig throws AFS_ConfigurationException for missing file', function() {
    try {
        new AFS_MappingConfig('/nonexistent/path/config.yml');
        throw new Exception('Expected AFS_ConfigurationException was not thrown');
    } catch (AFS_ConfigurationException $e) {
        if (strpos($e->getMessage(), 'not found') === false) {
            throw new Exception('Exception message does not indicate file not found');
        }
    }
});

// Test 8: AFS_TargetMappingConfig throws AFS_ConfigurationException for missing file
runTest('AFS_TargetMappingConfig throws AFS_ConfigurationException for missing file', function() {
    try {
        new AFS_TargetMappingConfig('/nonexistent/path/config.yml');
        throw new Exception('Expected AFS_ConfigurationException was not thrown');
    } catch (AFS_ConfigurationException $e) {
        if (strpos($e->getMessage(), 'not found') === false) {
            throw new Exception('Exception message does not indicate file not found');
        }
    }
});

// Test 9: AFS_Get_Data throws AFS_ValidationException for invalid argument
runTest('AFS_Get_Data throws AFS_ValidationException for invalid argument', function() {
    try {
        new AFS_Get_Data('not_an_object');
        throw new Exception('Expected AFS_ValidationException was not thrown');
    } catch (AFS_ValidationException $e) {
        if (strpos($e->getMessage(), 'Objekt') === false) {
            throw new Exception('Exception message does not indicate invalid object');
        }
    }
});

// Test 10: Exception inheritance chain
runTest('Exception inheritance chain is correct', function() {
    $busyException = new AFS_SyncBusyException('test');
    $configException = new AFS_ConfigurationException('test');
    $dbException = new AFS_DatabaseException('test');
    $validException = new AFS_ValidationException('test');
    
    // All custom exceptions should be instanceof Exception
    if (!($busyException instanceof Exception)) {
        throw new Exception('AFS_SyncBusyException not instanceof Exception');
    }
    if (!($configException instanceof Exception)) {
        throw new Exception('AFS_ConfigurationException not instanceof Exception');
    }
    if (!($dbException instanceof Exception)) {
        throw new Exception('AFS_DatabaseException not instanceof Exception');
    }
    if (!($validException instanceof Exception)) {
        throw new Exception('AFS_ValidationException not instanceof Exception');
    }
});

echo "\n=== Test Results ===\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";

if ($testsFailed > 0) {
    echo "\n❌ SOME TESTS FAILED\n";
    exit(1);
} else {
    echo "\n✅ ALL TESTS PASSED\n";
    exit(0);
}
