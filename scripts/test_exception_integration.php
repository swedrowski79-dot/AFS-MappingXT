#!/usr/bin/env php
<?php
/**
 * Integration test for exception handling across the codebase
 * 
 * This test verifies that exception handling works correctly
 * when exceptions propagate through multiple layers.
 */

require_once __DIR__ . '/../autoload.php';

echo "=== Exception Handling Integration Test ===\n\n";

$testsPassed = 0;
$testsFailed = 0;

function runTest(string $name, callable $test): void
{
    global $testsPassed, $testsFailed;
    
    try {
        $test();
        echo "✓ {$name}\n";
        $testsPassed++;
    } catch (\Throwable $e) {
        echo "✗ {$name}\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  Type: " . get_class($e) . "\n";
        $testsFailed++;
    }
}

// Test 1: Verify no error_log in production code
runTest('No error_log() in production classes', function() {
    $classFiles = glob(__DIR__ . '/../classes/*.php');
    $errorLogFound = false;
    $filesWithErrorLog = [];
    
    foreach ($classFiles as $file) {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            // Skip comment lines
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            
            // Check for error_log call
            if (strpos($line, 'error_log') !== false && strpos($line, '(') !== false) {
                $errorLogFound = true;
                $filesWithErrorLog[] = basename($file);
                break;
            }
        }
    }
    
    if ($errorLogFound) {
        throw new Exception('error_log() found in: ' . implode(', ', $filesWithErrorLog));
    }
});

// Test 2: Verify consistent Throwable usage
runTest('All catch blocks use \\Throwable with backslash', function() {
    $files = array_merge(
        glob(__DIR__ . '/../classes/*.php'),
        glob(__DIR__ . '/../api/*.php')
    );
    
    $inconsistentFiles = [];
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            // Look for catch (Throwable without leading backslash
            if (preg_match('/catch\s*\(\s*Throwable\s+/', $line) && !preg_match('/catch\s*\(\s*\\\\Throwable\s+/', $line)) {
                $inconsistentFiles[] = basename($file) . ':' . ($lineNum + 1);
            }
        }
    }
    
    if (!empty($inconsistentFiles)) {
        throw new Exception('Inconsistent Throwable usage in: ' . implode(', ', $inconsistentFiles));
    }
});

// Test 3: Verify custom exceptions are used
runTest('Custom exception types are used in codebase', function() {
    $files = array_merge(
        glob(__DIR__ . '/../classes/*.php'),
        glob(__DIR__ . '/../api/*.php')
    );
    
    $customExceptions = [
        'AFS_ConfigurationException',
        'AFS_DatabaseException',
        'AFS_ValidationException',
    ];
    
    $usageCount = array_fill_keys($customExceptions, 0);
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        foreach ($customExceptions as $exception) {
            if (strpos($content, $exception) !== false) {
                $usageCount[$exception]++;
            }
        }
    }
    
    $notUsed = [];
    foreach ($usageCount as $exception => $count) {
        if ($count === 0) {
            $notUsed[] = $exception;
        }
    }
    
    if (!empty($notUsed)) {
        throw new Exception('Custom exceptions not used: ' . implode(', ', $notUsed));
    }
});

// Test 4: Configuration file loading error handling
runTest('Configuration errors throw AFS_ConfigurationException', function() {
    try {
        new AFS_MappingConfig('/tmp/nonexistent_' . uniqid() . '.yml');
        throw new Exception('Should have thrown AFS_ConfigurationException');
    } catch (AFS_ConfigurationException $e) {
        // Expected
        if (!str_contains($e->getMessage(), 'not found')) {
            throw new Exception('Exception message should indicate file not found');
        }
    }
});

// Test 5: Database-related errors
runTest('Database errors use AFS_DatabaseException', function() {
    // Create a PDO with a non-existent database file
    $tempDb = '/tmp/test_error_handling_' . uniqid() . '.db';
    
    // Test that we throw DatabaseException when file doesn't exist
    $config = ['paths' => ['data_db' => $tempDb]];
    
    try {
        require_once __DIR__ . '/../api/_bootstrap.php';
        createEvoPdo($config);
        throw new Exception('Should have thrown AFS_DatabaseException');
    } catch (AFS_DatabaseException $e) {
        // Expected
        if (!str_contains($e->getMessage(), 'nicht gefunden') && !str_contains($e->getMessage(), 'not found')) {
            throw new Exception('Exception message should indicate database not found');
        }
    }
});

// Test 6: Validation errors
runTest('Validation errors throw AFS_ValidationException', function() {
    try {
        // Pass invalid argument (not an object)
        new AFS_Get_Data('not_an_object');
        throw new Exception('Should have thrown AFS_ValidationException');
    } catch (AFS_ValidationException $e) {
        // Expected
        if (!str_contains($e->getMessage(), 'Objekt')) {
            throw new Exception('Exception message should indicate object type error');
        }
    }
});

// Test 7: Exception inheritance
runTest('All custom exceptions inherit from correct base classes', function() {
    $configException = new AFS_ConfigurationException('test');
    $dbException = new AFS_DatabaseException('test');
    $validException = new AFS_ValidationException('test');
    
    if (!($configException instanceof RuntimeException)) {
        throw new Exception('AFS_ConfigurationException should extend RuntimeException');
    }
    
    if (!($dbException instanceof RuntimeException)) {
        throw new Exception('AFS_DatabaseException should extend RuntimeException');
    }
    
    if (!($validException instanceof InvalidArgumentException)) {
        throw new Exception('AFS_ValidationException should extend InvalidArgumentException');
    }
    
    // All should be catchable as Throwable
    if (!($configException instanceof Throwable)) {
        throw new Exception('AFS_ConfigurationException not instanceof Throwable');
    }
    if (!($dbException instanceof Throwable)) {
        throw new Exception('AFS_DatabaseException not instanceof Throwable');
    }
    if (!($validException instanceof Throwable)) {
        throw new Exception('AFS_ValidationException not instanceof Throwable');
    }
});

// Test 8: Documentation exists
runTest('Error handling documentation exists', function() {
    $docFile = __DIR__ . '/../docs/ERROR_HANDLING.md';
    
    if (!file_exists($docFile)) {
        throw new Exception('ERROR_HANDLING.md not found');
    }
    
    $content = file_get_contents($docFile);
    
    // Check for key sections
    $requiredSections = [
        'AFS_ConfigurationException',
        'AFS_DatabaseException',
        'AFS_ValidationException',
        'Exception Handling Patterns',
        'Throwable',
    ];
    
    foreach ($requiredSections as $section) {
        if (strpos($content, $section) === false) {
            throw new Exception("Documentation missing section: {$section}");
        }
    }
});

echo "\n=== Integration Test Results ===\n";
echo "Passed: {$testsPassed}\n";
echo "Failed: {$testsFailed}\n";

if ($testsFailed > 0) {
    echo "\n❌ SOME TESTS FAILED\n";
    exit(1);
} else {
    echo "\n✅ ALL INTEGRATION TESTS PASSED\n";
    exit(0);
}
