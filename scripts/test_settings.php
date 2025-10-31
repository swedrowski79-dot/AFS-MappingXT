<?php
// Test script for settings functionality
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../autoload.php';

echo "Testing Settings Functionality\n";
echo "==============================\n\n";

// Test 1: Read .env file
echo "Test 1: Reading .env file\n";
function readEnvFile(string $envPath): array
{
    if (!is_file($envPath)) {
        return [];
    }

    $content = file_get_contents($envPath);
    if ($content === false) {
        return [];
    }

    $lines = explode("\n", $content);
    $settings = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            
            $settings[$key] = $value;
        }
    }
    
    return $settings;
}

$envPath = __DIR__ . '/../.env';
$settings = readEnvFile($envPath);
echo "✓ Found " . count($settings) . " settings\n";
echo "✓ AFS_MAX_ERRORS = " . ($settings['AFS_MAX_ERRORS'] ?? 'not set') . "\n";
echo "✓ AFS_MSSQL_HOST = " . ($settings['AFS_MSSQL_HOST'] ?? 'not set') . "\n\n";

// Test 2: Update .env content
echo "Test 2: Updating env content\n";
function updateEnvContent(string $content, string $key, string $value): string
{
    $lines = explode("\n", $content);
    $updated = false;
    $escapedKey = preg_quote($key, '/');
    
    $needsQuotes = preg_match('/[\s#]/', $value);
    $formattedValue = $needsQuotes ? '"' . addslashes($value) . '"' : $value;
    
    foreach ($lines as $i => $line) {
        $trimmedLine = trim($line);
        
        if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
            continue;
        }
        
        if (preg_match("/^{$escapedKey}\s*=/", $trimmedLine)) {
            $lines[$i] = "{$key}={$formattedValue}";
            $updated = true;
            break;
        }
    }
    
    if (!$updated) {
        $lines[] = "{$key}={$formattedValue}";
    }
    
    return implode("\n", $lines);
}

$testContent = "# Test config\nAFS_MAX_ERRORS=200\nAFS_LOG_LEVEL=warning";
$updatedContent = updateEnvContent($testContent, 'AFS_MAX_ERRORS', '300');
if (strpos($updatedContent, 'AFS_MAX_ERRORS=300') !== false) {
    echo "✓ Successfully updated existing key\n";
} else {
    echo "✗ Failed to update existing key\n";
}

$updatedContent2 = updateEnvContent($testContent, 'NEW_KEY', 'new_value');
if (strpos($updatedContent2, 'NEW_KEY=new_value') !== false) {
    echo "✓ Successfully added new key\n";
} else {
    echo "✗ Failed to add new key\n";
}

echo "\n";

// Test 3: Validation
echo "Test 3: Testing validation\n";
function validateSetting(string $key, string $value): bool
{
    if (str_ends_with($key, '_PORT')) {
        return ctype_digit($value) && (int)$value > 0 && (int)$value <= 65535;
    }
    
    if (in_array($key, ['AFS_SECURITY_ENABLED', 'AFS_GITHUB_AUTO_UPDATE'])) {
        return in_array(strtolower($value), ['true', 'false', '0', '1', 'yes', 'no', '']);
    }
    
    return true;
}

echo validateSetting('AFS_MSSQL_PORT', '1435') ? "✓ Valid port number\n" : "✗ Invalid port number\n";
echo validateSetting('AFS_MSSQL_PORT', '99999') ? "✗ Should reject invalid port\n" : "✓ Rejected invalid port\n";
echo validateSetting('AFS_SECURITY_ENABLED', 'true') ? "✓ Valid boolean\n" : "✗ Invalid boolean\n";
echo validateSetting('AFS_SECURITY_ENABLED', 'invalid') ? "✗ Should reject invalid boolean\n" : "✓ Rejected invalid boolean\n";

echo "\n";
echo "All tests completed!\n";
