#!/usr/bin/env php
<?php
/**
 * Validation script for Apache mpm_event + PHP-FPM configuration
 * 
 * This script validates:
 * - Configuration files exist and are readable
 * - PHP syntax is correct
 * - YAML files are valid (if yaml extension is available)
 * - Docker files are present
 * 
 * Usage: php scripts/validate_server_config.php
 */

declare(strict_types=1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Configuration Validation                                          ║\n";
echo "║  Apache mpm_event + PHP-FPM Setup                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$errors = [];
$warnings = [];
$success = [];

// Check required files
$requiredFiles = [
    'Dockerfile',
    'docker-compose.yml',
    '.env.example',
    '.htaccess',
    '.dockerignore',
    'docker/apache2.conf',
    'docker/afs-mappingxt.conf',
    'docker/php-fpm.conf',
    'docker/php.ini',
    'docker/docker-entrypoint.sh',
    'api/health.php',
    'scripts/benchmark_server.php',
    'docs/APACHE_PHP_FPM_SETUP.md',
    'docs/QUICK_START_DOCKER.md',
    'docs/CONFIGURATION_MANAGEMENT.md',
];

echo "Checking configuration files...\n";
echo str_repeat('─', 70) . "\n";

foreach ($requiredFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path) && is_readable($path)) {
        $success[] = "✓ {$file} exists and is readable";
    } else {
        $errors[] = "✗ {$file} is missing or not readable";
    }
}

// Validate PHP syntax
echo "\nValidating PHP syntax...\n";
echo str_repeat('─', 70) . "\n";

$phpFiles = [
    'api/health.php',
    'scripts/benchmark_server.php',
];

foreach ($phpFiles as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $returnVar);
        
        if ($returnVar === 0) {
            $success[] = "✓ {$file} syntax OK";
        } else {
            $errors[] = "✗ {$file} has syntax errors: " . implode("\n", $output);
        }
    }
}

// Check YAML extension
echo "\nChecking PHP extensions...\n";
echo str_repeat('─', 70) . "\n";

$extensions = ['pdo', 'pdo_sqlite', 'json', 'mbstring'];
$optionalExtensions = ['yaml', 'sqlsrv', 'pdo_sqlsrv', 'opcache'];

foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        $success[] = "✓ Extension '{$ext}' is loaded";
    } else {
        $errors[] = "✗ Extension '{$ext}' is NOT loaded (required)";
    }
}

foreach ($optionalExtensions as $ext) {
    if (extension_loaded($ext)) {
        $success[] = "✓ Extension '{$ext}' is loaded (optional)";
    } else {
        $warnings[] = "⚠ Extension '{$ext}' is NOT loaded (optional, needed for full functionality)";
    }
}

// Check YAML files if extension is available
if (extension_loaded('yaml')) {
    echo "\nValidating YAML files...\n";
    echo str_repeat('─', 70) . "\n";
    
    $yamlFiles = [
        'mappings/source_afs.yml',
        'mappings/target_sqlite.yml',
    ];
    
    foreach ($yamlFiles as $file) {
        $path = __DIR__ . '/../' . $file;
        if (file_exists($path)) {
            try {
                $data = yaml_parse_file($path);
                if ($data !== false) {
                    $success[] = "✓ {$file} is valid YAML";
                } else {
                    $errors[] = "✗ {$file} is invalid YAML";
                }
            } catch (Exception $e) {
                $errors[] = "✗ {$file} YAML parse error: " . $e->getMessage();
            }
        }
    }
}

// Check Docker is available
echo "\nChecking Docker availability...\n";
echo str_repeat('─', 70) . "\n";

$dockerPath = trim((string)shell_exec('which docker 2>/dev/null'));
if ($dockerPath) {
    $success[] = "✓ Docker is available at: {$dockerPath}";
    
    // Check docker version
    $dockerVersion = trim((string)shell_exec('docker --version 2>/dev/null'));
    if ($dockerVersion) {
        $success[] = "✓ {$dockerVersion}";
    }
} else {
    $warnings[] = "⚠ Docker is not available (needed for containerized deployment)";
}

$dockerComposePath = trim((string)shell_exec('which docker-compose 2>/dev/null'));
if ($dockerComposePath) {
    $success[] = "✓ Docker Compose is available at: {$dockerComposePath}";
} else {
    $warnings[] = "⚠ Docker Compose is not available (needed for orchestration)";
}

// Check Apache Bench for benchmarking
echo "\nChecking benchmark tools...\n";
echo str_repeat('─', 70) . "\n";

$abPath = trim((string)shell_exec('which ab 2>/dev/null'));
if ($abPath) {
    $success[] = "✓ Apache Bench (ab) is available at: {$abPath}";
} else {
    $warnings[] = "⚠ Apache Bench (ab) is not available (benchmark will use PHP fallback)";
    $warnings[] = "  Install with: apt-get install apache2-utils";
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
}

// Summary
echo str_repeat('═', 70) . "\n";
if (empty($errors)) {
    echo "✅ Validation PASSED - Configuration is ready!\n";
    echo "\n";
    echo "Next steps:\n";
    echo "  1. Review configuration: docker/php-fpm.conf, docker/apache2.conf\n";
    echo "  2. Start Docker: docker-compose up -d\n";
    echo "  3. Run benchmark: php scripts/benchmark_server.php\n";
    echo "  4. Check docs: docs/APACHE_PHP_FPM_SETUP.md\n";
    exit(0);
} else {
    echo "❌ Validation FAILED - Please fix the errors above\n";
    exit(1);
}
