<?php
// config.php
declare(strict_types=1);

require_once __DIR__ . '/classes/config/DatabaseConfig.php';
require_once __DIR__ . '/classes/mapping/YamlMappingLoader.php';

/**
 * Resolve relative paths (project root relative) to absolute paths.
 */
function afs_config_resolve_path(string $path): string
{
    if ($path === '') {
        return $path;
    }
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
        return $path;
    }
    return __DIR__ . '/' . ltrim($path, '/');
}

/**
 * Returns the preferred absolute path for a mapping-related file, preferring the new
 * directory structure but falling back to legacy locations when they still exist.
 */
function afs_prefer_path(string $relative, string $primaryDir, string $legacyDir = 'mappings'): string
{
    $relative = ltrim($relative, '/');
    $primary = __DIR__ . '/' . trim($primaryDir, '/') . '/' . $relative;
    if (file_exists($primary)) {
        return $primary;
    }

    $legacy = __DIR__ . '/' . trim($legacyDir, '/') . '/' . $relative;
    if (file_exists($legacy)) {
        return $legacy;
    }

    return $primary;
}

/**
 * Apply database connection mapping from config/databases/databases.json.
 *
 * @param array<string, mixed> $config
 */
function afs_apply_database_connections(array &$config): void
{
    try {
        $registry = DatabaseConfig::load();
    } catch (Throwable $e) {
        $config['databases'] = [
            'connections' => [],
            'error' => $e->getMessage(),
        ];
        return;
    }

    $connections = $registry['connections'] ?? [];
    $config['databases'] = ['connections' => $connections];

    $roleMap = [];
    foreach ($connections as $connection) {
        $roles = $connection['roles'] ?? [];
        if (!is_array($roles)) {
            continue;
        }
        foreach ($roles as $role) {
            if (!isset($roleMap[$role])) {
                $roleMap[$role] = $connection;
            }
        }
    }

    if (isset($roleMap['AFS_MSSQL']) && ($roleMap['AFS_MSSQL']['type'] ?? '') === 'mssql') {
        $settings = $roleMap['AFS_MSSQL']['settings'] ?? [];
        if (is_array($settings)) {
            $config['mssql'] = [
                'host' => $settings['host'] ?? ($config['mssql']['host'] ?? 'localhost'),
                'port' => (int)($settings['port'] ?? ($config['mssql']['port'] ?? 1433)),
                'database' => $settings['database'] ?? ($config['mssql']['database'] ?? ''),
                'username' => $settings['username'] ?? ($config['mssql']['username'] ?? ''),
                'password' => $settings['password'] ?? ($config['mssql']['password'] ?? ''),
                'encrypt' => (bool)($settings['encrypt'] ?? true),
                'trust_server_certificate' => (bool)($settings['trust_server_certificate'] ?? false),
                'appname' => $config['mssql']['appname'] ?? 'Welafix-Sync',
            ];
        }
    }

    if (isset($roleMap['XT_MYSQL']) && ($roleMap['XT_MYSQL']['type'] ?? '') === 'mysql') {
        $settings = $roleMap['XT_MYSQL']['settings'] ?? [];
        if (is_array($settings)) {
            $config['xt_mysql'] = [
                'host' => $settings['host'] ?? ($config['xt_mysql']['host'] ?? 'localhost'),
                'port' => (int)($settings['port'] ?? ($config['xt_mysql']['port'] ?? 3306)),
                'database' => $settings['database'] ?? ($config['xt_mysql']['database'] ?? ''),
                'username' => $settings['username'] ?? ($config['xt_mysql']['username'] ?? ''),
                'password' => $settings['password'] ?? ($config['xt_mysql']['password'] ?? ''),
            ];
        }
    }

    if (isset($roleMap['EVO_MAIN']) && ($roleMap['EVO_MAIN']['type'] ?? '') === 'sqlite') {
        $settings = $roleMap['EVO_MAIN']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['paths']['data_db'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['EVO_DELTA']) && ($roleMap['EVO_DELTA']['type'] ?? '') === 'sqlite') {
        $settings = $roleMap['EVO_DELTA']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['paths']['delta_db'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['EVO_STATUS']) && ($roleMap['EVO_STATUS']['type'] ?? '') === 'sqlite') {
        $settings = $roleMap['EVO_STATUS']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['paths']['status_db'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['ORDERS_MAIN']) && ($roleMap['ORDERS_MAIN']['type'] ?? '') === 'sqlite') {
        $settings = $roleMap['ORDERS_MAIN']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['additional_databases']['orders_evo'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['ORDERS_DELTA']) && ($roleMap['ORDERS_DELTA']['type'] ?? '') === 'sqlite') {
        $settings = $roleMap['ORDERS_DELTA']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['additional_databases']['orders_evo_delta'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['AFS_FILES_IMAGES']) && ($roleMap['AFS_FILES_IMAGES']['type'] ?? '') === 'file') {
        $settings = $roleMap['AFS_FILES_IMAGES']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['paths']['media']['images']['source'] = afs_config_resolve_path($settings['path']);
        }
    }

    if (isset($roleMap['AFS_FILES_DOCUMENTS']) && ($roleMap['AFS_FILES_DOCUMENTS']['type'] ?? '') === 'file') {
        $settings = $roleMap['AFS_FILES_DOCUMENTS']['settings'] ?? [];
        if (is_array($settings) && isset($settings['path'])) {
            $config['paths']['media']['documents']['source'] = afs_config_resolve_path($settings['path']);
        }
    }
}

/**
 * Zentrale Konfiguration für AFS → SQLite Sync
 * 
 * Alle Werte können über Umgebungsvariablen (.env) konfiguriert werden.
 * Die hier angegebenen Werte dienen als Fallback-Defaults.
 * 
 * Unterstützte Umgebungsvariablen:
 * - AFS_MSSQL_HOST, AFS_MSSQL_PORT, AFS_MSSQL_DB, AFS_MSSQL_USER, AFS_MSSQL_PASS
 * - AFS_MEDIA_SOURCE (Quellverzeichnis für Bilder und Dokumente)
 * - AFS_MAX_ERRORS (Maximum Logeinträge)
 * - AFS_LOG_ROTATION_DAYS (Log-Rotation in Tagen)
 * - AFS_MAPPING_VERSION (Mapping-Version für Logs)
 * - PHP_MEMORY_LIMIT, PHP_MAX_EXECUTION_TIME, TZ (Docker/PHP-Konfiguration)
 */
$config = [
    'paths' => [
        'root'       => __DIR__,
        'db_dir'     => __DIR__ . '/db',
        'data_db'    => __DIR__ . '/db/evo.db',        // evo.db
        'status_db'  => __DIR__ . '/db/status.db',     // status.db
        'delta_db'   => __DIR__ . '/db/evo_delta.db',  // enthält nur geänderte Datensätze
        'log_dir'    => __DIR__ . '/logs',             // Log-Verzeichnis für tägliche JSON-Logs
        'api_base'   => 'api/',                        // Pfad relativ zu index.php
        'media'      => [
            'images' => [
                'source' => getenv('AFS_MEDIA_SOURCE') ?: __DIR__ . '/srcFiles/Photos',   // Quellverzeichnis für Bilder
                'target' => __DIR__ . '/Files/Bilder',          // Zielverzeichnis Bilder
            ],
            'documents' => [
                'source' => getenv('AFS_MEDIA_SOURCE') ?:  __DIR__ . '/srcFiles/Dokumente',// Quellverzeichnis für Dokumente
                'target' => __DIR__ . '/Files/Dokumente',       // Zielverzeichnis Dokumente
            ],
        ],
        'metadata' => [
            'articles'   => getenv('AFS_METADATA_ARTICLES') ?:  __DIR__ . '/srcFiles/Data/Artikel', // Pfad zum Stammverzeichnis der Artikel-Metadaten (Ordner je Artikelnummer)
            'categories' => getenv('AFS_METADATA_CATEGORIES') ?:  __DIR__ . '/srcFiles/Data/Warengruppen', // Pfad zum Stammverzeichnis der Warengruppen-Metadaten (Ordner je Warengruppenname)
        ],
    ],

    'status' => [
        'max_errors' => (int)(getenv('AFS_MAX_ERRORS') ?: 200),
    ],

    'logging' => [
        'mapping_version' => getenv('AFS_MAPPING_VERSION') ?: '1.0.0',          // Mapping-Konfigurationsversion für Logging
        'log_rotation_days' => (int)(getenv('AFS_LOG_ROTATION_DAYS') ?: 30),   // Log-Dateien älter als X Tage werden gelöscht
        'enable_file_logging' => (bool)(getenv('AFS_ENABLE_FILE_LOGGING') !== false ? getenv('AFS_ENABLE_FILE_LOGGING') : true), // JSON-Logging in tägliche Dateien aktivieren
        'log_level' => getenv('AFS_LOG_LEVEL') ?: 'info',                    // Minimaler Log-Level: 'info', 'warning', 'error' (lean: nur warnings und errors)
        'sample_size' => (int)(getenv('AFS_LOG_SAMPLE_SIZE') ?: 5),            // Anzahl der Beispiele in Error-Arrays (schlank: 5 statt 10-15)
    ],

    'mssql' => [
        'host'     => getenv('AFS_MSSQL_HOST') ?: '10.0.1.82',
        'port'     => (int)(getenv('AFS_MSSQL_PORT') ?: 1435),
        'database' => getenv('AFS_MSSQL_DB')   ?: 'AFS_2018',
        'username' => getenv('AFS_MSSQL_USER') ?: 'sa',
        'password' => getenv('AFS_MSSQL_PASS') ?: 'W3laf!x',
        'encrypt'  => true,   // ODBC18-Default, lassen
        'trust_server_certificate' => true, // DEV: Zertifikat NICHT prüfen
        'appname'  => 'Welafix-Sync',
    ],

    // ▼ Neu: Tabellennamen/Feldmapping aus AFS
    'mssql_map' => [
        'table'          => 'dbo.Warengruppe',   // Tabellenname (Schema.Tab)
        'pk'             => 'Warengruppe',     // Primär- oder laufende ID
        'xtid'           => 'XTID',              // optional (falls vorhanden)
        'name'           => 'Bezeichnung',
        'online'         => 'Internet',          // optional
        'picture'        => 'Bild',              // optional
        'picture_big'    => 'Bild_gross',        // optional
        'description'    => 'Beschreibung',      // optional
        'deleted_flag'   => 'Geloescht',         // optional: wird nur verwendet, wenn Spalte existiert
        'deleted_is_true_when' => 1,             // 1 oder 'Y' etc., nur für Info – wir filtern != this
    ],

    'ui' => [
        'title'         => 'AFS-Schnittstelle',
    ],

    'security' => [
        'enabled' => filter_var(getenv('AFS_SECURITY_ENABLED'), FILTER_VALIDATE_BOOLEAN),  // Enable security checks
    ],

    'github' => [
        'auto_update' => filter_var(getenv('AFS_GITHUB_AUTO_UPDATE'), FILTER_VALIDATE_BOOLEAN),  // Automatically update from GitHub
        'branch' => getenv('AFS_GITHUB_BRANCH') ?: '',  // Branch to update from (empty = current branch)
    ],

    // ============================================================================
    // Multi-Database Sync Configuration
    // Support for multiple source-target mapping pairs
    // ============================================================================
    'sync_mappings' => [
        // Primary sync: AFS → EVO
        'primary' => [
            'enabled' => true,
            'source' => getenv('SOURCE_MAPPING') ?: null,
            'schema' => getenv('SCHEMA_MAPPING') ?: null,
            'rules'  => getenv('RULE_MAPPING') ?: afs_prefer_path('afs_evo.yml', 'mapping'),
            'target' => getenv('TARGET_MAPPING') ?: null,
            'action' => 'sync_afs_to_evo',
        ],
        // Secondary sync: XT Orders → EVO Orders
        'secondary' => [
            'enabled' => str_contains(getenv('SYNC_ENABLED_ACTIONS') ?: '', 'sync_xt_orders_to_evo'),
            'source' => getenv('SOURCE_MAPPING_2') ?: afs_prefer_path('xt-order.yaml', 'mapping'),
            'target' => getenv('TARGET_MAPPING_2') ?: afs_prefer_path('orders-evo.yaml', 'mapping'),
            'action' => 'sync_xt_orders_to_evo',
        ],
        // Tertiary sync: EVO Articles → XT Articles
        'tertiary' => [
            'enabled' => str_contains(getenv('SYNC_ENABLED_ACTIONS') ?: '', 'sync_evo_to_xt'),
            'source' => getenv('SOURCE_MAPPING_3') ?: afs_prefer_path('evo-artikel.yaml', 'mapping'),
            'target' => getenv('TARGET_MAPPING_3') ?: afs_prefer_path('xt-artikel.yaml', 'mapping'),
            'action' => 'sync_evo_to_xt',
        ],
    ],

    // XT-Commerce MySQL connection settings
    'xt_mysql' => [
        'host'     => getenv('XT_MYSQL_HOST') ?: 'localhost',
        'port'     => (int)(getenv('XT_MYSQL_PORT') ?: 3306),
        'database' => getenv('XT_MYSQL_DB')   ?: 'xtcommerce',
        'username' => getenv('XT_MYSQL_USER') ?: 'xt_user',
        'password' => getenv('XT_MYSQL_PASS') ?: 'xt_password',
    ],

    // Additional database paths
    'additional_databases' => [
        'orders_evo' => getenv('ORDERS_DB_PATH') ?: __DIR__ . '/db/orders_evo.db',
        'orders_evo_delta' => getenv('ORDERS_DELTA_DB_PATH') ?: __DIR__ . '/db/orders_evo_delta.db',
    ],

    // Sync options
    'sync_options' => [
        'bidirectional' => filter_var(getenv('SYNC_BIDIRECTIONAL'), FILTER_VALIDATE_BOOLEAN),
        'enabled_actions' => array_filter(
            array_map('trim', explode(',', getenv('SYNC_ENABLED_ACTIONS') ?: 'sync_afs_to_evo'))
        ),
    ],

    // ============================================================================
    // Server-to-Server Data Transfer API Configuration
    // Configuration for secure data transfer between different servers
    // ============================================================================
    'data_transfer' => [
        // API Key for authentication (required)
        'api_key' => getenv('DATA_TRANSFER_API_KEY') ?: '',
        
        // Database transfer paths
        'database' => [
            'enabled' => filter_var(getenv('DATA_TRANSFER_ENABLE_DB') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'source' => getenv('DB_TRANSFER_SOURCE') ?: __DIR__ . '/db/evo_delta.db',
            'target' => getenv('DB_TRANSFER_TARGET') ?: '/tmp/evo_delta.db',
            'generator_script' => getenv('DB_TRANSFER_GENERATOR') ?: 'scripts/sync_evo_to_xt.php',
        ],
        
        // Images transfer configuration (paths come from per-server settings)
        'images' => [
            'enabled' => filter_var(getenv('DATA_TRANSFER_ENABLE_IMAGES') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        ],
        
        // Documents transfer configuration (paths come from per-server settings)
        'documents' => [
            'enabled' => filter_var(getenv('DATA_TRANSFER_ENABLE_DOCUMENTS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        ],
        
        // Transfer options
        'max_file_size' => (int)(getenv('DATA_TRANSFER_MAX_FILE_SIZE') ?: 104857600), // 100MB
        'log_transfers' => filter_var(getenv('DATA_TRANSFER_LOG_TRANSFERS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
        'php_binary' => getenv('DATA_TRANSFER_PHP_BINARY') ?: PHP_BINARY,
    ],


    // ============================================================================
    // Remote Server Configuration
    // Configure remote/slave servers to monitor their status
    // ============================================================================
    'remote_servers' => [
        // Enable remote server monitoring
        'enabled' => filter_var(getenv('REMOTE_SERVERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        
        // List of remote servers to monitor (comma-separated in env)
        // Format: name|url|api_key|database (last two segments optional)
        'servers' => array_filter(
            array_map(function($serverConfig) {
                $parts = array_map('trim', explode('|', $serverConfig));
                if (count($parts) >= 2) {
                    return [
                        'name' => $parts[0],
                        'url' => rtrim($parts[1], '/'),
                        'api_key' => $parts[2] ?? '',
                        'database' => $parts[3] ?? '',
                    ];
                }
                return null;
            }, array_filter(array_map('trim', explode(',', getenv('REMOTE_SERVERS') ?: ''))))
        ),
        
        // Timeout for remote server requests (seconds)
        'timeout' => (int)(getenv('REMOTE_SERVER_TIMEOUT') ?: 5),
        'allow_insecure' => filter_var(getenv('REMOTE_SERVER_ALLOW_INSECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ],
];

$filepusherPath = afs_prefer_path('filepusher.yml', 'schemas');
if (is_string($filepusherPath) && $filepusherPath !== '' && is_file($filepusherPath)) {
    try {
        $config['data_transfer']['pusher'] = YamlMappingLoader::load($filepusherPath);
    } catch (Throwable $e) {
        // Ignore YAML loading errors to avoid breaking configuration
    }
}

foreach ($config['sync_mappings'] as &$mappingConfig) {
    if (!is_array($mappingConfig)) {
        continue;
    }
    foreach (['source', 'target', 'rules', 'schema'] as $mappingKey) {
        if (isset($mappingConfig[$mappingKey]) && is_string($mappingConfig[$mappingKey])) {
            $mappingConfig[$mappingKey] = afs_config_resolve_path($mappingConfig[$mappingKey]);
        }
    }

    $rulesPath = $mappingConfig['rules'] ?? null;
    if (!is_string($rulesPath) || $rulesPath === '' || !is_file($rulesPath)) {
        continue;
    }

    try {
        $rulesConfig = YamlMappingLoader::load($rulesPath);
    } catch (Throwable $e) {
        continue;
    }

    $baseDir = dirname($rulesPath);
    if (empty($mappingConfig['source']) && isset($rulesConfig['from'])) {
        $candidate = (string)$rulesConfig['from'];
        $resolved = afs_config_resolve_path($candidate);
        if (!is_file($resolved)) {
            $resolved = afs_config_resolve_path($baseDir . '/' . $candidate . '.yml');
        }
        if (!is_file($resolved)) {
            $resolved = afs_prefer_path($candidate . '.yml', 'schemas');
        }
        if (is_file($resolved)) {
            $mappingConfig['source'] = $resolved;
        }
    }

    if (empty($mappingConfig['schema']) && isset($rulesConfig['to'])) {
        $candidate = (string)$rulesConfig['to'];
        $resolved = afs_config_resolve_path($candidate);
        if (!is_file($resolved)) {
            $resolved = afs_config_resolve_path($baseDir . '/' . $candidate . '.yml');
        }
        if (!is_file($resolved)) {
            $resolved = afs_prefer_path($candidate . '.yml', 'schemas');
        }
        if (is_file($resolved)) {
            $mappingConfig['schema'] = $resolved;
        }
    }

    if (empty($mappingConfig['schema']) && !empty($mappingConfig['target'])) {
        $mappingConfig['schema'] = afs_config_resolve_path((string)$mappingConfig['target']);
    }

    if (empty($mappingConfig['target']) && !empty($mappingConfig['schema'])) {
        $mappingConfig['target'] = $mappingConfig['schema'];
    }

    if (!empty($mappingConfig['target']) && !is_file($mappingConfig['target'])) {
        $fallback = null;
        if (!empty($mappingConfig['schema']) && is_file($mappingConfig['schema'])) {
            $fallback = $mappingConfig['schema'];
        } else {
            $fallbackCandidate = afs_prefer_path('evo.yml', 'schemas');
            if (is_file($fallbackCandidate)) {
                $fallback = $fallbackCandidate;
            }
        }
        if ($fallback !== null && is_string($fallback) && is_file($fallback)) {
            $mappingConfig['target'] = $fallback;
        }
    }

    if (!empty($mappingConfig['source']) && !is_file($mappingConfig['source'])) {
        $fallback = afs_prefer_path('afs.yml', 'schemas');
        if (is_file($fallback)) {
            $mappingConfig['source'] = $fallback;
        }
    }

    if (!empty($mappingConfig['schema']) && !is_file($mappingConfig['schema'])) {
        $fallback = afs_prefer_path('evo.yml', 'schemas');
        if (is_file($fallback)) {
            $mappingConfig['schema'] = $fallback;
            if (isset($mappingConfig['target']) && (!is_file($mappingConfig['target']) || $mappingConfig['target'] === '')) {
                $mappingConfig['target'] = $fallback;
            }
        }
    }
}
unset($mappingConfig);

afs_apply_database_connections($config);

return $config;
