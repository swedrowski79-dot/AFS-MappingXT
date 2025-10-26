<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

/**
 * Read the entire .env file content
 */
function readEnvContent(string $envPath): string
{
    if (!is_file($envPath)) {
        return '';
    }
    
    $content = file_get_contents($envPath);
    return $content !== false ? $content : '';
}

/**
 * Update a specific key in the .env file content
 * Preserves comments and formatting
 */
function updateEnvContent(string $content, string $key, string $value): string
{
    $lines = explode("\n", $content);
    $updated = false;
    $escapedKey = preg_quote($key, '/');
    
    // Escape value if it contains special characters
    $needsQuotes = preg_match('/[\s#]/', $value);
    $formattedValue = $needsQuotes ? '"' . addslashes($value) . '"' : $value;
    
    foreach ($lines as $i => $line) {
        $trimmedLine = trim($line);
        
        // Skip comments and empty lines
        if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
            continue;
        }
        
        // Check if this line contains our key
        if (preg_match("/^{$escapedKey}\s*=/", $trimmedLine)) {
            $lines[$i] = "{$key}={$formattedValue}";
            $updated = true;
            break;
        }
    }
    
    // If key wasn't found, append it to the end
    if (!$updated) {
        $lines[] = "{$key}={$formattedValue}";
    }
    
    return implode("\n", $lines);
}

/**
 * Validate setting value
 */
function validateSetting(string $key, string $value): bool
{
    // Port numbers must be numeric
    if (str_ends_with($key, '_PORT')) {
        return ctype_digit($value) && (int)$value > 0 && (int)$value <= 65535;
    }
    
    // Boolean values
    if (in_array($key, ['AFS_SECURITY_ENABLED', 'AFS_GITHUB_AUTO_UPDATE', 'AFS_ENABLE_FILE_LOGGING',
                        'SYNC_BIDIRECTIONAL', 'DATA_TRANSFER_ENABLE_DB', 'DATA_TRANSFER_ENABLE_IMAGES',
                        'DATA_TRANSFER_ENABLE_DOCUMENTS', 'DATA_TRANSFER_LOG_TRANSFERS',
                        'REMOTE_SERVERS_ENABLED', 'OPCACHE_VALIDATE_TIMESTAMPS', 'OPCACHE_HUGE_CODE_PAGES'])) {
        return in_array(strtolower($value), ['true', 'false', '0', '1', 'yes', 'no', '']);
    }
    
    // Numeric values
    if (in_array($key, ['AFS_MAX_ERRORS', 'AFS_LOG_ROTATION_DAYS', 'AFS_LOG_SAMPLE_SIZE',
                        'DATA_TRANSFER_MAX_FILE_SIZE', 'REMOTE_SERVER_TIMEOUT',
                        'OPCACHE_MEMORY_CONSUMPTION', 'OPCACHE_INTERNED_STRINGS_BUFFER',
                        'OPCACHE_MAX_ACCELERATED_FILES', 'OPCACHE_REVALIDATE_FREQ'])) {
        return ctype_digit($value) || $value === '';
    }
    
    // Log level
    if ($key === 'AFS_LOG_LEVEL') {
        return in_array($value, ['info', 'warning', 'error', '']);
    }
    
    // JIT mode
    if ($key === 'OPCACHE_JIT_MODE') {
        return in_array($value, ['disable', 'tracing', 'function', '']) || ctype_digit($value);
    }
    
    // Paths - basic validation
    if (str_contains($key, '_PATH') || str_contains($key, '_SOURCE') || str_contains($key, '_TARGET') || 
        str_contains($key, '_DIR') || str_contains($key, 'MAPPING')) {
        // Allow empty or valid path-like strings
        return $value === '' || preg_match('#^[a-zA-Z0-9_./\-]+$#', $value);
    }
    
    // Default: allow any non-empty string or empty
    return true;
}

/**
 * Sanitize setting value
 */
function sanitizeSetting(string $value): string
{
    // Remove any control characters except newline
    return preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $value);
}

try {
    global $config;
    $root = dirname(__DIR__);
    $envPath = $root . '/.env';
    
    // Check if .env file exists and is writable
    if (!is_file($envPath)) {
        api_error('.env Datei wurde nicht gefunden', 404);
    }
    
    if (!is_writable($envPath)) {
        api_error('.env Datei ist nicht beschreibbar', 403);
    }
    
    // Get posted settings
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!is_array($input) || !isset($input['settings'])) {
        api_error('Ungültige Eingabedaten', 400);
    }
    
    $newSettings = $input['settings'];
    
    if (!is_array($newSettings)) {
        api_error('Einstellungen müssen ein Array sein', 400);
    }
    
    // Validate all settings before writing
    $errors = [];
    foreach ($newSettings as $key => $value) {
        $value = sanitizeSetting((string)$value);
        
        if (!validateSetting($key, $value)) {
            $errors[$key] = "Ungültiger Wert für {$key}";
        }
    }
    
    if (!empty($errors)) {
        api_error('Validierungsfehler: ' . implode(', ', $errors), 400);
    }
    
    // Read current .env content
    $content = readEnvContent($envPath);
    
    // Create backup before modifying
    $backupPath = $envPath . '.backup.' . date('Y-m-d_H-i-s');
    file_put_contents($backupPath, $content);
    
    // Update each setting
    foreach ($newSettings as $key => $value) {
        $value = sanitizeSetting((string)$value);
        $content = updateEnvContent($content, $key, $value);
    }
    
    // Write updated content
    if (file_put_contents($envPath, $content) === false) {
        api_error('Fehler beim Schreiben der .env Datei', 500);
    }
    
    // Clean up old backups (keep last 5)
    $backups = glob($root . '/.env.backup.*');
    if (count($backups) > 5) {
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        $toDelete = array_slice($backups, 0, count($backups) - 5);
        foreach ($toDelete as $oldBackup) {
            @unlink($oldBackup);
        }
    }
    
    api_ok([
        'message' => 'Einstellungen erfolgreich gespeichert',
        'backup' => basename($backupPath),
        'updated_count' => count($newSettings),
    ]);
    
} catch (\Throwable $e) {
    api_error('Fehler beim Speichern der Einstellungen: ' . $e->getMessage());
}
