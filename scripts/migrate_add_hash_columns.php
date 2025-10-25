#!/usr/bin/env php
<?php
/**
 * Migration: Add hash columns to tables
 * 
 * Adds last_imported_hash and last_seen_hash columns to relevant tables
 * for efficient change detection using SHA-256 hashes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

$config = require __DIR__ . '/../config.php';
$dbPath = $config['paths']['data_db'] ?? __DIR__ . '/../db/evo.db';

if (!file_exists($dbPath)) {
    echo "Error: Database file does not exist at: {$dbPath}\n";
    echo "Please run scripts/setup.php first to create the database.\n";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Adding Hash Columns Migration ===\n\n";
    
    // Tables that need hash columns
    $tables = [
        'Artikel',
        'Bilder',
        'Dokumente',
        'Attribute',
        'category',
    ];
    
    $db->beginTransaction();
    
    foreach ($tables as $table) {
        echo "Processing table: {$table}\n";
        
        // Check if columns already exist
        $pragma = $db->query("PRAGMA table_info({$table})");
        $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        
        $hasImportedHash = in_array('last_imported_hash', $columnNames, true);
        $hasSeenHash = in_array('last_seen_hash', $columnNames, true);
        
        if (!$hasImportedHash) {
            echo "  Adding last_imported_hash column...\n";
            $db->exec("ALTER TABLE {$table} ADD COLUMN last_imported_hash TEXT");
        } else {
            echo "  last_imported_hash column already exists\n";
        }
        
        if (!$hasSeenHash) {
            echo "  Adding last_seen_hash column...\n";
            $db->exec("ALTER TABLE {$table} ADD COLUMN last_seen_hash TEXT");
        } else {
            echo "  last_seen_hash column already exists\n";
        }
        
        echo "\n";
    }
    
    // Add indices for hash columns to improve query performance
    echo "Creating indices for hash columns...\n";
    
    $indices = [
        "CREATE INDEX IF NOT EXISTS ix_artikel_imported_hash ON Artikel(last_imported_hash)",
        "CREATE INDEX IF NOT EXISTS ix_artikel_seen_hash ON Artikel(last_seen_hash)",
        "CREATE INDEX IF NOT EXISTS ix_bilder_imported_hash ON Bilder(last_imported_hash)",
        "CREATE INDEX IF NOT EXISTS ix_dokumente_imported_hash ON Dokumente(last_imported_hash)",
        "CREATE INDEX IF NOT EXISTS ix_attribute_imported_hash ON Attribute(last_imported_hash)",
        "CREATE INDEX IF NOT EXISTS ix_category_imported_hash ON category(last_imported_hash)",
    ];
    
    foreach ($indices as $indexSql) {
        $db->exec($indexSql);
    }
    
    echo "Indices created successfully\n\n";
    
    // Drop obsolete partial hash indices if they exist (for Artikel table)
    echo "Cleaning up obsolete partial hash indices...\n";
    $obsoleteIndices = [
        'ix_artikel_price_hash',
        'ix_artikel_media_hash',
        'ix_artikel_content_hash',
    ];
    
    foreach ($obsoleteIndices as $indexName) {
        try {
            $db->exec("DROP INDEX IF EXISTS {$indexName}");
            echo "  Dropped index: {$indexName}\n";
        } catch (PDOException $e) {
            // Index might not exist, that's okay
            echo "  Index not found (already removed): {$indexName}\n";
        }
    }
    echo "\n";
    
    $db->commit();
    
    echo "✓ Migration completed successfully!\n";
    echo "\nHash columns added to tables:\n";
    foreach ($tables as $table) {
        echo "  - {$table}\n";
    }
    echo "\nYou can now use AFS_HashManager for efficient change detection.\n";
    
} catch (PDOException $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
