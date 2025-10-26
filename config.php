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
];
