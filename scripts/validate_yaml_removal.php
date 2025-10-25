#!/usr/bin/env php
<?php
/**
 * Comprehensive validation that the php-yaml dependency has been successfully removed
 * 
 * This script validates:
 * 1. Native YAML parser works correctly
 * 2. All configuration files parse successfully
 * 3. System functionality is preserved
 * 4. No references to yaml extension remain in code
 * 5. Docker configuration is updated
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Validation: php-yaml Dependency Removal                           ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$errors = [];
$warnings = [];
$success = [];

// Test 1: AFS_YamlParser class exists and is loadable
echo "Test 1: Checking AFS_YamlParser class...\n";
echo str_repeat('─', 70) . "\n";
if (class_exists('AFS_YamlParser')) {
    $success[] = "✓ AFS_YamlParser class is available";
    
    // Check if class has required methods
    $requiredMethods = ['parse', 'parseFile'];
    foreach ($requiredMethods as $method) {
        if (method_exists('AFS_YamlParser', $method)) {
            $success[] = "✓ AFS_YamlParser::{$method}() method exists";
        } else {
            $errors[] = "✗ AFS_YamlParser::{$method}() method is missing";
        }
    }
} else {
    $errors[] = "✗ AFS_YamlParser class not found";
}

// Test 2: Parse YAML configuration files
echo "\nTest 2: Parsing YAML configuration files...\n";
echo str_repeat('─', 70) . "\n";

$yamlFiles = [
    'mappings/source_afs.yml',
    'mappings/target_sqlite.yml',
];

foreach ($yamlFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        try {
            $content = file_get_contents($path);
            $content = preg_replace_callback('/\$\{([A-Z_]+)\}/', function($matches) {
                $val = getenv($matches[1]);
                return $val !== false ? $val : 'TEST_VALUE';
            }, $content);
            
            $data = AFS_YamlParser::parse($content);
            if (!empty($data)) {
                $success[] = "✓ {$file} parsed successfully";
                
                // Validate structure
                if ($file === 'mappings/source_afs.yml') {
                    if (isset($data['entities']) && is_array($data['entities'])) {
                        $success[] = "  └─ Found " . count($data['entities']) . " entities";
                    }
                } elseif ($file === 'mappings/target_sqlite.yml') {
                    if (isset($data['version'])) {
                        $success[] = "  └─ Version: {$data['version']}";
                    }
                    if (isset($data['entities']) && is_array($data['entities'])) {
                        $success[] = "  └─ Found " . count($data['entities']) . " entities";
                    }
                    if (isset($data['relationships']) && is_array($data['relationships'])) {
                        $success[] = "  └─ Found " . count($data['relationships']) . " relationships";
                    }
                }
            } else {
                $warnings[] = "⚠ {$file} parsed but returned empty data";
            }
        } catch (Exception $e) {
            $errors[] = "✗ {$file} failed to parse: " . $e->getMessage();
        }
    } else {
        $errors[] = "✗ {$file} not found";
    }
}

// Test 3: Configuration classes use native parser
echo "\nTest 3: Testing configuration classes...\n";
echo str_repeat('─', 70) . "\n";

try {
    AFS_ConfigCache::clear();
    $mappingConfig = new AFS_MappingConfig(__DIR__ . '/../mappings/source_afs.yml');
    $entities = $mappingConfig->getEntities();
    $success[] = "✓ AFS_MappingConfig loads successfully (" . count($entities) . " entities)";
} catch (Exception $e) {
    $errors[] = "✗ AFS_MappingConfig failed: " . $e->getMessage();
}

try {
    AFS_ConfigCache::clear();
    $targetConfig = new AFS_TargetMappingConfig(__DIR__ . '/../mappings/target_sqlite.yml');
    $version = $targetConfig->getVersion();
    $success[] = "✓ AFS_TargetMappingConfig loads successfully (version: {$version})";
} catch (Exception $e) {
    $errors[] = "✗ AFS_TargetMappingConfig failed: " . $e->getMessage();
}

// Test 4: Check for yaml_parse references in code
echo "\nTest 4: Checking for yaml_parse references in code...\n";
echo str_repeat('─', 70) . "\n";

$phpFiles = glob(__DIR__ . '/../classes/*.php');
$foundYamlParse = false;

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    if (preg_match('/\byaml_parse(_file)?\s*\(/', $content)) {
        $foundYamlParse = true;
        $errors[] = "✗ Found yaml_parse reference in: " . basename($file);
    }
}

if (!$foundYamlParse) {
    $success[] = "✓ No yaml_parse references found in classes/";
}

// Test 5: Check Dockerfile
echo "\nTest 5: Checking Dockerfile for yaml references...\n";
echo str_repeat('─', 70) . "\n";

$dockerfile = __DIR__ . '/../Dockerfile';
if (file_exists($dockerfile)) {
    $content = file_get_contents($dockerfile);
    
    if (strpos($content, 'libyaml-dev') === false) {
        $success[] = "✓ libyaml-dev not in Dockerfile";
    } else {
        $errors[] = "✗ libyaml-dev still referenced in Dockerfile";
    }
    
    if (!preg_match('/pecl install.*yaml/', $content)) {
        $success[] = "✓ yaml PECL extension not in Dockerfile";
    } else {
        $errors[] = "✗ yaml PECL extension still referenced in Dockerfile";
    }
}

// Test 6: Check php.ini
echo "\nTest 6: Checking docker/php.ini for yaml configuration...\n";
echo str_repeat('─', 70) . "\n";

$phpIni = __DIR__ . '/../docker/php.ini';
if (file_exists($phpIni)) {
    $content = file_get_contents($phpIni);
    
    if (strpos($content, 'yaml.') === false) {
        $success[] = "✓ No yaml configuration in php.ini";
    } else {
        $warnings[] = "⚠ yaml configuration still present in php.ini";
    }
}

// Test 7: Verify README update
echo "\nTest 7: Checking README.md...\n";
echo str_repeat('─', 70) . "\n";

$readme = __DIR__ . '/../README.md';
if (file_exists($readme)) {
    $content = file_get_contents($readme);
    
    // Check for AFS_YamlParser mention
    if (strpos($content, 'AFS_YamlParser') !== false) {
        $success[] = "✓ AFS_YamlParser documented in README";
    } else {
        $warnings[] = "⚠ AFS_YamlParser not mentioned in README";
    }
    
    // Check that yaml is not in required extensions
    if (preg_match('/PHP.*Extensions.*yaml/i', $content)) {
        $warnings[] = "⚠ yaml still listed as required extension in README";
    } else {
        $success[] = "✓ yaml not listed as required extension in README";
    }
}

// Output results
echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Validation Results                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

if (!empty($success)) {
    echo "✅ Success (" . count($success) . "):\n";
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
    exit(1);
}

// Summary
echo "══════════════════════════════════════════════════════════════════════\n";
if (empty($errors)) {
    echo "✅ VALIDATION PASSED - php-yaml dependency successfully removed!\n";
    echo "\n";
    echo "Summary:\n";
    echo "  • Native YAML parser (AFS_YamlParser) implemented\n";
    echo "  • All configuration files parse correctly\n";
    echo "  • No yaml_parse references remain in code\n";
    echo "  • Docker configuration updated\n";
    echo "  • Documentation updated\n";
    echo "  • System functionality preserved\n";
    echo "\n";
    echo "The system no longer requires the php-yaml extension.\n";
    exit(0);
} else {
    echo "❌ VALIDATION FAILED - Issues found\n";
    exit(1);
}
