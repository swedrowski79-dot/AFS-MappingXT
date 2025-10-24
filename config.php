<?php
// config.php
declare(strict_types=1);

/**
 * Zentrale Konfiguration für AFS → SQLite Sync
 * Passe die Werte bei Bedarf an (oder nutze Umgebungsvariablen als Fallback).
 */
return [
    'paths' => [
        'root'       => __DIR__,
        'db_dir'     => __DIR__ . '/db',
        'data_db'    => __DIR__ . '/db/evo.db',        // evo.db
        'status_db'  => __DIR__ . '/db/status.db',     // status.db
        'delta_db'   => __DIR__ . '/db/evo_delta.db',  // enthält nur geänderte Datensätze
        'api_base'   => 'api/',                        // Pfad relativ zu index.php
        'media'      => [
            'images' => [
                'source' => '/var/www/data',   // Quellverzeichnis für Bilder
                'target' => __DIR__ . '/Files/Bilder',          // Zielverzeichnis Bilder
            ],
            'documents' => [
                'source' => '/var/www/data',// Quellverzeichnis für Dokumente
                'target' => __DIR__ . '/Files/Dokumente',       // Zielverzeichnis Dokumente
            ],
        ],
        'metadata' => [
            'articles'   => null, // Pfad zum Stammverzeichnis der Artikel-Metadaten (Ordner je Artikelnummer)
            'categories' => null, // Pfad zum Stammverzeichnis der Warengruppen-Metadaten (Ordner je Warengruppenname)
        ],
    ],

    'status' => [
        'max_errors' => 200,
    ],

    'mssql' => [
        'host'     => getenv('AFS_MSSQL_HOST') ?: '10.0.1.82',
        'port'     => (int)(getenv('AFS_MSSQL_PORT') ?: 1435),
        'database' => getenv('AFS_MSSQL_DB')   ?: 'AFS_WAWI_DB',
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
