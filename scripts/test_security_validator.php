#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Test script for SecurityValidator
 * 
 * Tests the security validation mechanism for index.php and indexcli.php
 */

require_once __DIR__ . '/../autoload.php';

echo "Testing SecurityValidator functionality...\n\n";

// Test 1: Check if SecurityValidator class exists
echo "Test 1: SecurityValidator class exists... ";
if (class_exists('SecurityValidator')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 2: Check isSecurityEnabled with security disabled
echo "Test 2: isSecurityEnabled with security disabled... ";
$configDisabled = ['security' => ['enabled' => false]];
if (!SecurityValidator::isSecurityEnabled($configDisabled)) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 3: Check isSecurityEnabled with security enabled
echo "Test 3: isSecurityEnabled with security enabled... ";
$configEnabled = ['security' => ['enabled' => true]];
if (SecurityValidator::isSecurityEnabled($configEnabled)) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 4: Check isCalledFromApi (should return false when called directly)
echo "Test 4: isCalledFromApi (direct call)... ";
if (!SecurityValidator::isCalledFromApi()) {
    echo "✓ PASS (correctly returns false for direct call)\n";
} else {
    echo "✗ FAIL (should return false for direct call)\n";
    exit(1);
}

// Test 5: Simulate API call by creating a backtrace with an API file
echo "Test 5: isCalledFromApi (simulated from API)... ";
// Create a temporary API test file
$apiTestFile = __DIR__ . '/../api/test_security.php';
$apiTestContent = <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
// This simulates a call from the API directory
echo json_encode(['isFromApi' => SecurityValidator::isCalledFromApi()]);
PHP;
file_put_contents($apiTestFile, $apiTestContent);

// Execute the API test file and capture output
ob_start();
include $apiTestFile;
$output = ob_get_clean();
unlink($apiTestFile);

$result = json_decode($output, true);
if (isset($result['isFromApi']) && $result['isFromApi']) {
    echo "✓ PASS (correctly detects API call)\n";
} else {
    echo "✗ FAIL (should detect API call)\n";
    exit(1);
}

// Test 6: Configuration reading
echo "Test 6: Configuration security setting... ";
$configPath = __DIR__ . '/../config.php';
$config = require $configPath;
if (array_key_exists('security', $config) && array_key_exists('enabled', $config['security'])) {
    echo "✓ PASS (security configuration exists)\n";
} else {
    echo "✗ FAIL (security configuration missing)\n";
    exit(1);
}

echo "\n✅ All tests passed!\n";
echo "\nSecurity Mode Status: " . ($config['security']['enabled'] ? 'ENABLED' : 'DISABLED') . "\n";
echo "\nTo enable security mode, add to .env:\n";
echo "AFS_SECURITY_ENABLED=true\n";
