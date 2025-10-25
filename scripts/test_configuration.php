#!/usr/bin/env php
<?php
/**
 * Test script for unified environment variable configuration
 * 
 * This script validates that:
 * - config.php correctly reads environment variables
 * - Fallback defaults work when env vars are not set
 * - All new environment variables are properly handled
 */

declare(strict_types=1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Configuration Management Test                                     ║\n";
echo "║  Testing unified .env variable handling                            ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$errors = [];
$warnings = [];
$success = [];

// Test 1: Load config without environment variables
echo "Test 1: Loading config with default values (no env vars)...\n";
echo str_repeat('─', 70) . "\n";

try {
    $config = require __DIR__ . '/../config.php';
    $success[] = "✓ config.php loaded successfully";
    
    // Verify structure
    if (!isset($config['paths'])) {
        $errors[] = "✗ Missing 'paths' section in config";
    } else {
        $success[] = "✓ 'paths' section exists";
    }
    
    if (!isset($config['mssql'])) {
        $errors[] = "✗ Missing 'mssql' section in config";
    } else {
        $success[] = "✓ 'mssql' section exists";
    }
    
    if (!isset($config['logging'])) {
        $errors[] = "✗ Missing 'logging' section in config";
    } else {
        $success[] = "✓ 'logging' section exists";
    }
    
    if (!isset($config['status'])) {
        $errors[] = "✗ Missing 'status' section in config";
    } else {
        $success[] = "✓ 'status' section exists";
    }
} catch (Exception $e) {
    $errors[] = "✗ Failed to load config.php: " . $e->getMessage();
}

// Test 2: Verify default MSSQL values
echo "\nTest 2: Verifying MSSQL default values...\n";
echo str_repeat('─', 70) . "\n";

if (isset($config['mssql'])) {
    $defaults = [
        'host' => '10.0.1.82',
        'port' => 1435,
        'database' => 'AFS_WAWI_DB',
        'username' => 'sa',
    ];
    
    foreach ($defaults as $key => $expectedValue) {
        if (!isset($config['mssql'][$key])) {
            $errors[] = "✗ Missing mssql.{$key}";
        } elseif ($config['mssql'][$key] !== $expectedValue) {
            $errors[] = "✗ mssql.{$key} = {$config['mssql'][$key]}, expected {$expectedValue}";
        } else {
            $success[] = "✓ mssql.{$key} = {$expectedValue}";
        }
    }
}

// Test 3: Test environment variable override
echo "\nTest 3: Testing environment variable overrides...\n";
echo str_repeat('─', 70) . "\n";

// Set test environment variables
putenv('AFS_MSSQL_HOST=test-host.example.com');
putenv('AFS_MSSQL_PORT=9999');
putenv('AFS_MSSQL_DB=TEST_DB');
putenv('AFS_MSSQL_USER=test_user');
putenv('AFS_MSSQL_PASS=test_password');
putenv('AFS_MEDIA_SOURCE=/test/media/path');
putenv('AFS_MAX_ERRORS=500');
putenv('AFS_LOG_ROTATION_DAYS=60');
putenv('AFS_MAPPING_VERSION=2.0.0');

// Reload config
$testConfig = require __DIR__ . '/../config.php';

$testCases = [
    ['mssql.host', 'test-host.example.com', $testConfig['mssql']['host']],
    ['mssql.port', 9999, $testConfig['mssql']['port']],
    ['mssql.database', 'TEST_DB', $testConfig['mssql']['database']],
    ['mssql.username', 'test_user', $testConfig['mssql']['username']],
    ['mssql.password', 'test_password', $testConfig['mssql']['password']],
    ['paths.media.images.source', '/test/media/path', $testConfig['paths']['media']['images']['source']],
    ['paths.media.documents.source', '/test/media/path', $testConfig['paths']['media']['documents']['source']],
    ['status.max_errors', 500, $testConfig['status']['max_errors']],
    ['logging.log_rotation_days', 60, $testConfig['logging']['log_rotation_days']],
    ['logging.mapping_version', '2.0.0', $testConfig['logging']['mapping_version']],
];

foreach ($testCases as [$name, $expected, $actual]) {
    if ($actual !== $expected) {
        $errors[] = "✗ {$name} = " . var_export($actual, true) . ", expected " . var_export($expected, true);
    } else {
        $success[] = "✓ {$name} = " . var_export($expected, true);
    }
}

// Clean up environment
putenv('AFS_MSSQL_HOST');
putenv('AFS_MSSQL_PORT');
putenv('AFS_MSSQL_DB');
putenv('AFS_MSSQL_USER');
putenv('AFS_MSSQL_PASS');
putenv('AFS_MEDIA_SOURCE');
putenv('AFS_MAX_ERRORS');
putenv('AFS_LOG_ROTATION_DAYS');
putenv('AFS_MAPPING_VERSION');

// Test 4: Verify new configuration options
echo "\nTest 4: Verifying new configuration options...\n";
echo str_repeat('─', 70) . "\n";

$newOptions = [
    'paths.media.images.source' => '/var/www/data',
    'paths.media.documents.source' => '/var/www/data',
    'status.max_errors' => 200,
    'logging.log_rotation_days' => 30,
    'logging.mapping_version' => '1.0.0',
];

$config = require __DIR__ . '/../config.php';

foreach ($newOptions as $path => $expectedValue) {
    $keys = explode('.', $path);
    $value = $config;
    foreach ($keys as $key) {
        $value = $value[$key] ?? null;
    }
    
    if ($value !== $expectedValue) {
        $errors[] = "✗ {$path} = " . var_export($value, true) . ", expected " . var_export($expectedValue, true);
    } else {
        $success[] = "✓ {$path} = " . var_export($expectedValue, true);
    }
}

// Test 5: Check .env.example file
echo "\nTest 5: Checking .env.example file...\n";
echo str_repeat('─', 70) . "\n";

$envExamplePath = __DIR__ . '/../.env.example';
if (!file_exists($envExamplePath)) {
    $errors[] = "✗ .env.example file not found";
} else {
    $success[] = "✓ .env.example file exists";
    
    $envContent = file_get_contents($envExamplePath);
    $requiredVars = [
        'HTTP_PORT',
        'ADMINER_PORT',
        'PHP_MEMORY_LIMIT',
        'PHP_MAX_EXECUTION_TIME',
        'TZ',
        'AFS_MSSQL_HOST',
        'AFS_MSSQL_PORT',
        'AFS_MSSQL_DB',
        'AFS_MSSQL_USER',
        'AFS_MSSQL_PASS',
        'AFS_MEDIA_SOURCE',
        'AFS_MAX_ERRORS',
        'AFS_LOG_ROTATION_DAYS',
        'AFS_MAPPING_VERSION',
    ];
    
    foreach ($requiredVars as $var) {
        if (strpos($envContent, $var) === false) {
            $errors[] = "✗ .env.example missing variable: {$var}";
        } else {
            $success[] = "✓ .env.example contains: {$var}";
        }
    }
}

// Test 6: Check docker-entrypoint.sh
echo "\nTest 6: Checking docker-entrypoint.sh...\n";
echo str_repeat('─', 70) . "\n";

$entrypointPath = __DIR__ . '/../docker/docker-entrypoint.sh';
if (!file_exists($entrypointPath)) {
    $errors[] = "✗ docker-entrypoint.sh not found";
} else {
    $success[] = "✓ docker-entrypoint.sh exists";
    
    if (!is_executable($entrypointPath)) {
        $warnings[] = "⚠ docker-entrypoint.sh is not executable";
    } else {
        $success[] = "✓ docker-entrypoint.sh is executable";
    }
    
    $entrypointContent = file_get_contents($entrypointPath);
    $requiredStrings = [
        'PHP_MEMORY_LIMIT',
        'PHP_MAX_EXECUTION_TIME',
        'TZ',
        'AFS_MSSQL_HOST',
        'custom.ini',
        'zz-custom.conf',
    ];
    
    foreach ($requiredStrings as $str) {
        if (strpos($entrypointContent, $str) === false) {
            $errors[] = "✗ docker-entrypoint.sh missing: {$str}";
        } else {
            $success[] = "✓ docker-entrypoint.sh contains: {$str}";
        }
    }
}

// Test 7: Check documentation
echo "\nTest 7: Checking documentation...\n";
echo str_repeat('─', 70) . "\n";

$docsPath = __DIR__ . '/../docs/CONFIGURATION_MANAGEMENT.md';
if (!file_exists($docsPath)) {
    $errors[] = "✗ CONFIGURATION_MANAGEMENT.md not found";
} else {
    $success[] = "✓ CONFIGURATION_MANAGEMENT.md exists";
    
    $docsContent = file_get_contents($docsPath);
    if (strlen($docsContent) < 1000) {
        $warnings[] = "⚠ CONFIGURATION_MANAGEMENT.md seems too short";
    } else {
        $success[] = "✓ CONFIGURATION_MANAGEMENT.md has substantial content";
    }
}

// Output results
echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Test Results                                                      ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

if (!empty($success)) {
    echo "✅ Success (" . count($success) . " checks passed):\n";
    foreach ($success as $msg) {
        echo "  {$msg}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠️  Warnings (" . count($warnings) . "):\n";
    foreach ($warnings as $msg) {
        echo "  {$msg}\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "❌ Errors (" . count($errors) . "):\n";
    foreach ($errors as $msg) {
        echo "  {$msg}\n";
    }
    echo "\n";
}

// Summary
echo str_repeat('═', 70) . "\n";
if (empty($errors)) {
    echo "✅ ALL TESTS PASSED - Configuration management is working correctly!\n";
    echo "\n";
    echo "Configuration can now be managed through:\n";
    echo "  1. .env file (recommended)\n";
    echo "  2. Environment variables\n";
    echo "  3. Fallback defaults in config.php\n";
    echo "\n";
    echo "See docs/CONFIGURATION_MANAGEMENT.md for details.\n";
    exit(0);
} else {
    echo "❌ TESTS FAILED - Please fix the errors above\n";
    exit(1);
}
