#!/usr/bin/env php
<?php
/**
 * Security Headers Test
 * 
 * This script tests that security headers are properly implemented
 * in the application responses.
 */

declare(strict_types=1);

echo "=== Security Headers Test ===\n\n";

// Test 1: Check if index.php has security headers
echo "Test 1: Checking index.php security headers...\n";
$indexPath = __DIR__ . '/../index.php';
$indexContent = file_get_contents($indexPath);

$checks = [
    'X-Content-Type-Options' => false,
    'X-Frame-Options' => false,
    'X-XSS-Protection' => false,
    'Referrer-Policy' => false,
    'header_remove' => false,
];

foreach ($checks as $header => $found) {
    if (stripos($indexContent, $header) !== false) {
        echo "  ✓ Found: $header\n";
        $checks[$header] = true;
    } else {
        echo "  ✗ Missing: $header\n";
    }
}

// Test 2: Check if API bootstrap has security headers
echo "\nTest 2: Checking API bootstrap security headers...\n";
$bootstrapPath = __DIR__ . '/../api/_bootstrap.php';
$bootstrapContent = file_get_contents($bootstrapPath);

$apiChecks = [
    'X-Content-Type-Options' => false,
    'X-Frame-Options' => false,
    'X-XSS-Protection' => false,
    'Referrer-Policy' => false,
    'header_remove' => false,
];

foreach ($apiChecks as $header => $found) {
    if (stripos($bootstrapContent, $header) !== false) {
        echo "  ✓ Found: $header\n";
        $apiChecks[$header] = true;
    } else {
        echo "  ✗ Missing: $header\n";
    }
}

// Test 3: Check if .htaccess has security headers
echo "\nTest 3: Checking .htaccess security headers...\n";
$htaccessPath = __DIR__ . '/../.htaccess';
$htaccessContent = file_get_contents($htaccessPath);

$htaccessChecks = [
    'X-Content-Type-Options' => false,
    'X-Frame-Options' => false,
    'X-XSS-Protection' => false,
    'Referrer-Policy' => false,
    'Content-Security-Policy' => false,
    'Permissions-Policy' => false,
];

foreach ($htaccessChecks as $header => $found) {
    if (stripos($htaccessContent, $header) !== false) {
        echo "  ✓ Found: $header\n";
        $htaccessChecks[$header] = true;
    } else {
        echo "  ✗ Missing: $header\n";
    }
}

// Test 4: Check if Apache config has security headers
echo "\nTest 4: Checking Apache configuration security headers...\n";
$apacheConfigPath = __DIR__ . '/../docker/apache2.conf';
$apacheContent = file_get_contents($apacheConfigPath);

$apacheChecks = [
    'X-Content-Type-Options' => false,
    'X-Frame-Options' => false,
    'X-XSS-Protection' => false,
    'Referrer-Policy' => false,
    'Content-Security-Policy' => false,
    'Permissions-Policy' => false,
];

foreach ($apacheChecks as $header => $found) {
    if (stripos($apacheContent, $header) !== false) {
        echo "  ✓ Found: $header\n";
        $apacheChecks[$header] = true;
    } else {
        echo "  ✗ Missing: $header\n";
    }
}

// Test 5: Check PHP security settings
echo "\nTest 5: Checking PHP security configuration...\n";
$phpIniPath = __DIR__ . '/../docker/php.ini';
$phpIniContent = file_get_contents($phpIniPath);

$phpChecks = [
    'expose_php = Off' => false,
    'allow_url_include = Off' => false,
    'session.use_strict_mode = 1' => false,
    'session.cookie_httponly = 1' => false,
    'session.cookie_samesite' => false,
    'display_errors = Off' => false,
];

foreach ($phpChecks as $setting => $found) {
    if (stripos($phpIniContent, $setting) !== false) {
        echo "  ✓ Found: $setting\n";
        $phpChecks[$setting] = true;
    } else {
        echo "  ✗ Missing: $setting\n";
    }
}

// Test 6: Check if HTML has CSP meta tag
echo "\nTest 6: Checking HTML CSP meta tag...\n";
if (stripos($indexContent, 'Content-Security-Policy') !== false && 
    stripos($indexContent, '<meta http-equiv') !== false) {
    echo "  ✓ Found: CSP meta tag in HTML\n";
} else {
    echo "  ✗ Missing: CSP meta tag in HTML\n";
}

// Test 7: Check sensitive file protection in .htaccess
echo "\nTest 7: Checking sensitive file protection...\n";
$protectionChecks = [
    'config\.php' => false,
    'composer\.' => false,
    '\.env' => false,
    'database files' => false,
    'db-shm' => false,
    '\.git' => false,
];

foreach ($protectionChecks as $pattern => $found) {
    if (stripos($htaccessContent, $pattern) !== false) {
        echo "  ✓ Protected: $pattern\n";
        $protectionChecks[$pattern] = true;
    } else {
        echo "  ✗ Not protected: $pattern\n";
    }
}

// Summary
echo "\n=== Test Summary ===\n";
$totalChecks = count($checks) + count($apiChecks) + count($htaccessChecks) + 
               count($apacheChecks) + count($phpChecks) + count($protectionChecks) + 1;
$passedChecks = array_sum($checks) + array_sum($apiChecks) + array_sum($htaccessChecks) + 
                array_sum($apacheChecks) + array_sum($phpChecks) + array_sum($protectionChecks);

// Add CSP meta tag check to passed count
if (stripos($indexContent, 'Content-Security-Policy') !== false && 
    stripos($indexContent, '<meta http-equiv') !== false) {
    $passedChecks++;
}

$passRate = round(($passedChecks / $totalChecks) * 100, 1);

echo "Passed: $passedChecks / $totalChecks ($passRate%)\n";

if ($passedChecks === $totalChecks) {
    echo "\n✓ All security checks passed!\n";
    exit(0);
} else {
    echo "\n✗ Some security checks failed. Please review the output above.\n";
    exit(1);
}
