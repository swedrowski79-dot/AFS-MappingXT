<?php
// config.php
declare(strict_types=1);

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
return [
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
        'log_level' => getenv('AFS_LOG_LEVEL') ?: 'warning',                    // Minimaler Log-Level: 'info', 'warning', 'error' (lean: nur warnings und errors)
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
            'source' => getenv('SOURCE_MAPPING') ?: __DIR__ . '/mappings/source_afs.yml',
            'target' => getenv('TARGET_MAPPING') ?: __DIR__ . '/mappings/target_sqlite.yml',
            'action' => 'sync_afs_to_evo',
        ],
        // Secondary sync: XT Orders → EVO Orders
        'secondary' => [
            'enabled' => str_contains(getenv('SYNC_ENABLED_ACTIONS') ?: '', 'sync_xt_orders_to_evo'),
            'source' => getenv('SOURCE_MAPPING_2') ?: __DIR__ . '/mappings/xt-order.yaml',
            'target' => getenv('TARGET_MAPPING_2') ?: __DIR__ . '/mappings/orders-evo.yaml',
            'action' => 'sync_xt_orders_to_evo',
        ],
        // Tertiary sync: EVO Articles → XT Articles
        'tertiary' => [
            'enabled' => str_contains(getenv('SYNC_ENABLED_ACTIONS') ?: '', 'sync_evo_to_xt'),
            'source' => getenv('SOURCE_MAPPING_3') ?: __DIR__ . '/mappings/evo-artikel.yaml',
            'target' => getenv('TARGET_MAPPING_3') ?: __DIR__ . '/mappings/xt-artikel.yaml',
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
        ],
        
        // Images transfer paths
        'images' => [
            'enabled' => filter_var(getenv('DATA_TRANSFER_ENABLE_IMAGES') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'source' => getenv('IMAGES_TRANSFER_SOURCE') ?: __DIR__ . '/Files/Bilder',
            'target' => getenv('IMAGES_TRANSFER_TARGET') ?: '/tmp/Bilder',
        ],
        
        // Documents transfer paths
        'documents' => [
            'enabled' => filter_var(getenv('DATA_TRANSFER_ENABLE_DOCUMENTS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'source' => getenv('DOCUMENTS_TRANSFER_SOURCE') ?: __DIR__ . '/Files/Dokumente',
            'target' => getenv('DOCUMENTS_TRANSFER_TARGET') ?: '/tmp/Dokumente',
        ],
        
        // Transfer options
        'max_file_size' => (int)(getenv('DATA_TRANSFER_MAX_FILE_SIZE') ?: 104857600), // 100MB
        'log_transfers' => filter_var(getenv('DATA_TRANSFER_LOG_TRANSFERS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    ],

    // ============================================================================
    // Remote Server Configuration
    // Configure remote/slave servers to monitor their status
    // ============================================================================
    'remote_servers' => [
        // Enable remote server monitoring
        'enabled' => filter_var(getenv('REMOTE_SERVERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        
        // List of remote servers to monitor (comma-separated in env)
        // Format: name|url|api_key (e.g., "Server1|https://server1.example.com|key123")
        'servers' => array_filter(
            array_map(function($serverConfig) {
                $parts = array_map('trim', explode('|', $serverConfig));
                if (count($parts) >= 2) {
                    return [
                        'name' => $parts[0],
                        'url' => rtrim($parts[1], '/'),
                        'api_key' => $parts[2] ?? '',
                    ];
                }
                return null;
            }, array_filter(array_map('trim', explode(',', getenv('REMOTE_SERVERS') ?: ''))))
        ),
        
        // Timeout for remote server requests (seconds)
        'timeout' => (int)(getenv('REMOTE_SERVER_TIMEOUT') ?: 5),
    ],
];

