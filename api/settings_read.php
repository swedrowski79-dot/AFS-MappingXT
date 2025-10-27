<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

/**
 * Read and parse the .env file
 * Returns all environment variables as key-value pairs
 */
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
        
        // Skip empty lines and comments
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            
            $settings[$key] = $value;
        }
    }
    
    return $settings;
}

/**
 * Get setting categories for organized display
 */
function getSettingCategories(): array
{
    return [
        'data_transfer' => [
            'label' => '🔐 Data Transfer API (Remote Server Verbindung)',
            'description' => 'API-Schlüssel und Konfiguration für die sichere Verbindung mit Remote-Servern',
            'keys' => ['DATA_TRANSFER_API_KEY', 'DB_TRANSFER_SOURCE', 'DB_TRANSFER_TARGET',
                       'IMAGES_TRANSFER_SOURCE', 'IMAGES_TRANSFER_TARGET',
                       'DOCUMENTS_TRANSFER_SOURCE', 'DOCUMENTS_TRANSFER_TARGET',
                       'DATA_TRANSFER_ENABLE_DB', 'DATA_TRANSFER_ENABLE_IMAGES',
                       'DATA_TRANSFER_ENABLE_DOCUMENTS', 'DATA_TRANSFER_MAX_FILE_SIZE',
                       'DATA_TRANSFER_LOG_TRANSFERS'],
        ],
        'remote_servers' => [
            'label' => '🌐 Remote Server Monitoring',
            'description' => 'Überwachung und Verwaltung von entfernten Servern',
            'keys' => ['REMOTE_SERVERS_ENABLED', 'REMOTE_SERVERS', 'REMOTE_SERVER_TIMEOUT'],
        ],
        'security' => [
            'label' => '🔒 Sicherheit',
            'description' => 'Sicherheitseinstellungen für die Anwendung',
            'keys' => ['AFS_SECURITY_ENABLED'],
        ],
        'application' => [
            'label' => '⚙️ Anwendung',
            'description' => 'Allgemeine Anwendungseinstellungen',
            'keys' => ['AFS_MEDIA_SOURCE', 'AFS_METADATA_ARTICLES', 'AFS_METADATA_CATEGORIES',
                       'AFS_MAX_ERRORS', 'AFS_LOG_ROTATION_DAYS', 'AFS_MAPPING_VERSION',
                       'AFS_ENABLE_FILE_LOGGING', 'AFS_LOG_LEVEL', 'AFS_LOG_SAMPLE_SIZE'],
        ],
        'github' => [
            'label' => '🔄 GitHub Auto-Update',
            'description' => 'Automatische Updates von GitHub',
            'keys' => ['AFS_GITHUB_AUTO_UPDATE', 'AFS_GITHUB_BRANCH'],
        ],
        'sync' => [
            'label' => '🔄 Multi-Database Sync',
            'description' => 'Konfiguration für mehrere Datenbank-Synchronisationen',
            'keys' => ['SOURCE_MAPPING', 'TARGET_MAPPING', 'SOURCE_MAPPING_2', 'TARGET_MAPPING_2',
                       'SOURCE_MAPPING_3', 'TARGET_MAPPING_3', 'ORDERS_DB_PATH', 'ORDERS_DELTA_DB_PATH',
                       'SYNC_ENABLED_ACTIONS', 'SYNC_BIDIRECTIONAL'],
        ],
        'php' => [
            'label' => '🔧 PHP Konfiguration',
            'description' => 'PHP Runtime Einstellungen',
            'keys' => ['PHP_MEMORY_LIMIT', 'PHP_MAX_EXECUTION_TIME', 'TZ'],
        ],
        'opcache' => [
            'label' => '⚡ OPcache',
            'description' => 'PHP Bytecode Cache Einstellungen',
            'keys' => ['OPCACHE_MEMORY_CONSUMPTION', 'OPCACHE_INTERNED_STRINGS_BUFFER', 
                       'OPCACHE_MAX_ACCELERATED_FILES', 'OPCACHE_REVALIDATE_FREQ',
                       'OPCACHE_VALIDATE_TIMESTAMPS', 'OPCACHE_HUGE_CODE_PAGES'],
        ],
        'jit' => [
            'label' => '⚡ JIT Kompilierung',
            'description' => 'Just-In-Time Compiler Einstellungen',
            'keys' => ['OPCACHE_JIT_MODE', 'OPCACHE_JIT_BUFFER_SIZE'],
        ],
        'docker' => [
            'label' => '🐳 Docker/Web Server',
            'description' => 'Docker und Web Server Ports',
            'keys' => ['HTTP_PORT', 'ADMINER_PORT'],
        ],
    ];
}

try {
    global $config;
    $root = dirname(__DIR__);
    $envPath = $root . '/.env';
    
    // Read current settings
    $settings = readEnvFile($envPath);
    $categories = getSettingCategories();
    
    api_ok([
        'settings' => $settings,
        'categories' => $categories,
        'env_file_exists' => is_file($envPath),
        'env_file_writable' => is_writable($envPath),
    ]);
} catch (\Throwable $e) {
    api_error('Fehler beim Lesen der Einstellungen: ' . $e->getMessage());
}
