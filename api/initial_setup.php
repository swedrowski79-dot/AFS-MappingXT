<?php
declare(strict_types=1);

/**
 * Initial Setup API Endpoint
 * 
 * This endpoint allows creating the .env file for initial installation
 * without requiring an API key. Once the .env file exists, this endpoint
 * requires authentication via DATA_TRANSFER_API_KEY.
 * 
 * Methods:
 * - GET: Check if setup is needed (returns whether .env exists)
 * - POST: Create/update .env file
 * 
 * POST Parameters (JSON):
 * - settings: Array of key-value pairs for .env file
 * - api_key: Required if .env already exists
 */

$root = dirname(__DIR__);
$autoloadFile = $root . '/autoload.php';

if (!is_file($autoloadFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'System nicht korrekt eingerichtet.']);
    exit;
}

require_once $autoloadFile;

// Security headers
function sendSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header_remove('X-Powered-By');
    header_remove('Server');
}

function api_response(array $data, int $status = 200): void
{
    http_response_code($status);
    sendSecurityHeaders();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Load config only if .env exists
$envPath = $root . '/.env';
$envExists = is_file($envPath);
$config = null;

if ($envExists) {
    $configFile = $root . '/config.php';
    if (is_file($configFile)) {
        $config = require $configFile;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Return setup status
        api_response([
            'ok' => true,
            'setup_needed' => !$envExists,
            'env_exists' => $envExists,
            'env_writable' => $envExists ? is_writable($envPath) : is_writable(dirname($envPath)),
        ]);
    } elseif ($method === 'POST') {
        // Validate authentication if .env exists
        if ($envExists && $config !== null) {
            $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
            $configuredKey = $config['data_transfer']['api_key'] ?? '';
            
            if (empty($providedKey) || empty($configuredKey) || !hash_equals($configuredKey, $providedKey)) {
                api_response(['ok' => false, 'error' => 'Authentifizierung erforderlich. .env Datei existiert bereits.'], 403);
            }
        }
        
        // Get posted settings
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['settings'])) {
            api_response(['ok' => false, 'error' => 'Ungültige Eingabedaten'], 400);
        }
        
        $newSettings = $input['settings'];
        
        if (!is_array($newSettings)) {
            api_response(['ok' => false, 'error' => 'Einstellungen müssen ein Array sein'], 400);
        }
        
        // Validate essential settings for initial setup
        if (!$envExists) {
            $requiredKeys = ['DATA_TRANSFER_API_KEY'];
            foreach ($requiredKeys as $key) {
                if (!isset($newSettings[$key]) || empty(trim($newSettings[$key]))) {
                    api_response(['ok' => false, 'error' => "Erforderliche Einstellung fehlt: {$key}"], 400);
                }
            }
        }
        
        // Read .env.example as template
        $examplePath = $root . '/.env.example';
        $content = '';
        
        if ($envExists) {
            // Read existing .env
            $content = file_get_contents($envPath);
            if ($content === false) {
                api_response(['ok' => false, 'error' => 'Fehler beim Lesen der .env Datei'], 500);
            }
        } elseif (is_file($examplePath)) {
            // Use .env.example as template
            $content = file_get_contents($examplePath);
            if ($content === false) {
                api_response(['ok' => false, 'error' => 'Fehler beim Lesen der .env.example Datei'], 500);
            }
        } else {
            // Create minimal .env content
            $content = "# Environment Configuration for AFS-MappingXT\n# Created via Initial Setup API\n\n";
        }
        
        // Update settings in content
        foreach ($newSettings as $key => $value) {
            $value = sanitizeValue((string)$value);
            $content = updateEnvLine($content, $key, $value);
        }
        
        // Create backup if .env exists
        if ($envExists) {
            $backupPath = $envPath . '.backup.' . date('Y-m-d_H-i-s');
            file_put_contents($backupPath, file_get_contents($envPath));
        }
        
        // Write .env file
        if (file_put_contents($envPath, $content) === false) {
            api_response(['ok' => false, 'error' => 'Fehler beim Schreiben der .env Datei'], 500);
        }
        
        api_response([
            'ok' => true,
            'message' => $envExists ? 'Einstellungen erfolgreich aktualisiert' : 'Initiale Konfiguration erfolgreich erstellt',
            'created' => !$envExists,
            'updated_count' => count($newSettings),
        ]);
    } else {
        api_response(['ok' => false, 'error' => 'Methode nicht erlaubt'], 405);
    }
} catch (\Throwable $e) {
    api_response(['ok' => false, 'error' => 'Fehler: ' . $e->getMessage()], 500);
}

/**
 * Sanitize setting value
 */
function sanitizeValue(string $value): string
{
    // Remove any control characters except newline
    return preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/', '', $value);
}

/**
 * Update or add a key-value pair in .env content
 */
function updateEnvLine(string $content, string $key, string $value): string
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
