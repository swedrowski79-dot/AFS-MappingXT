#!/usr/bin/env php
<?php
/**
 * Verify YAML Extension Installation
 * 
 * This script checks if the YAML extension is properly installed and working
 * in the PHP environment (useful for Docker container verification).
 */

// Color output helpers
function colorize($text, $color)
{
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'reset' => "\033[0m"
    ];
    return $colors[$color] . $text . $colors['reset'];
}

function printSuccess($message)
{
    echo colorize("✓ ", 'green') . $message . PHP_EOL;
}

function printError($message)
{
    echo colorize("✗ ", 'red') . $message . PHP_EOL;
}

function printInfo($message)
{
    echo colorize("ℹ ", 'blue') . $message . PHP_EOL;
}

// Header
echo PHP_EOL;
echo colorize("=== YAML Extension Verification ===", 'blue') . PHP_EOL;
echo PHP_EOL;

$exitCode = 0;

// Test 1: Check if YAML extension is loaded
echo "Test 1: Checking if YAML extension is loaded..." . PHP_EOL;
if (extension_loaded('yaml')) {
    printSuccess("YAML extension is loaded");
    
    // Get version
    $version = phpversion('yaml');
    printInfo("YAML extension version: " . $version);
} else {
    printError("YAML extension is NOT loaded");
    $exitCode = 1;
}

echo PHP_EOL;

// Test 2: Check YAML functions availability
echo "Test 2: Checking YAML functions..." . PHP_EOL;
$requiredFunctions = ['yaml_parse', 'yaml_parse_file', 'yaml_emit', 'yaml_emit_file'];
$missingFunctions = [];

foreach ($requiredFunctions as $func) {
    if (function_exists($func)) {
        printSuccess("Function '{$func}' is available");
    } else {
        printError("Function '{$func}' is NOT available");
        $missingFunctions[] = $func;
    }
}

if (!empty($missingFunctions)) {
    $exitCode = 1;
}

echo PHP_EOL;

// Test 3: Test YAML parsing functionality
echo "Test 3: Testing YAML parsing..." . PHP_EOL;
try {
    $testYaml = <<<YAML
test:
  name: "YAML Extension Test"
  version: 1.0
  features:
    - parsing
    - emitting
    - file operations
YAML;

    $parsed = yaml_parse($testYaml);
    
    if (is_array($parsed) && isset($parsed['test'])) {
        printSuccess("YAML parsing works correctly");
        printInfo("Parsed structure:");
        printInfo("  - Name: " . $parsed['test']['name']);
        printInfo("  - Version: " . $parsed['test']['version']);
        printInfo("  - Features: " . count($parsed['test']['features']) . " items");
    } else {
        printError("YAML parsing returned unexpected result");
        $exitCode = 1;
    }
} catch (Exception $e) {
    printError("YAML parsing failed: " . $e->getMessage());
    $exitCode = 1;
}

echo PHP_EOL;

// Test 4: Test YAML file parsing (if mappings directory exists)
echo "Test 4: Testing YAML file parsing..." . PHP_EOL;

// Try multiple possible locations for the mapping file
$possiblePaths = [
    dirname(__DIR__) . '/mappings/source_afs.yml',
    '/var/www/html/mappings/source_afs.yml',
    getcwd() . '/mappings/source_afs.yml',
];

$mappingFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $mappingFile = $path;
        break;
    }
}

if ($mappingFile && file_exists($mappingFile)) {
    try {
        $parsed = yaml_parse_file($mappingFile);
        
        if (is_array($parsed) && isset($parsed['entities'])) {
            printSuccess("YAML file parsing works correctly");
            printInfo("Loaded mapping file: " . basename($mappingFile));
            printInfo("Entities found: " . count($parsed['entities']));
        } else {
            printError("YAML file parsing returned unexpected result");
            $exitCode = 1;
        }
    } catch (Exception $e) {
        printError("YAML file parsing failed: " . $e->getMessage());
        $exitCode = 1;
    }
} else {
    printInfo("Mapping file not found, skipping file parsing test");
}

echo PHP_EOL;

// Test 5: Test YAML emitting
echo "Test 5: Testing YAML emitting..." . PHP_EOL;
try {
    $testData = [
        'application' => 'AFS-MappingXT',
        'yaml_test' => [
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    $emitted = yaml_emit($testData);
    
    if (!empty($emitted) && strpos($emitted, 'AFS-MappingXT') !== false) {
        printSuccess("YAML emitting works correctly");
        printInfo("Generated YAML output length: " . strlen($emitted) . " bytes");
    } else {
        printError("YAML emitting returned unexpected result");
        $exitCode = 1;
    }
} catch (Exception $e) {
    printError("YAML emitting failed: " . $e->getMessage());
    $exitCode = 1;
}

echo PHP_EOL;

// Summary
echo colorize("=== Verification Summary ===", 'blue') . PHP_EOL;
echo PHP_EOL;

if ($exitCode === 0) {
    printSuccess("All YAML extension tests passed successfully!");
    echo PHP_EOL;
    printInfo("The YAML extension is properly installed and functional.");
} else {
    printError("Some YAML extension tests failed!");
    echo PHP_EOL;
    printInfo("Please check the installation:");
    printInfo("  1. Ensure libyaml-dev is installed");
    printInfo("  2. Reinstall YAML extension: pecl install yaml");
    printInfo("  3. Enable the extension: docker-php-ext-enable yaml");
    printInfo("  4. Restart PHP-FPM/Apache");
}

echo PHP_EOL;

exit($exitCode);
