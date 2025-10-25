#!/usr/bin/env php
<?php
/**
 * DEPRECATED: This migration script is no longer needed
 * 
 * Partial hash columns (price_hash, media_hash, content_hash) have been removed
 * in favor of unified hash management using only last_imported_hash and last_seen_hash.
 * 
 * Use scripts/migrate_add_hash_columns.php instead.
 * 
 * ---
 * 
 * Migration: Add partial hash columns to Artikel table
 * 
 * Adds price_hash, media_hash, and content_hash columns to the Artikel table
 * for selective change detection and efficient partial updates.
 * 
 * These columns enable:
 * - price_hash: Detect price/inventory changes (Preis, Bestand, Mindestmenge)
 * - media_hash: Detect media/image relationship changes (Bild1-10)
 * - content_hash: Detect content/description changes (Bezeichnung, Langtext, etc.)
 */

declare(strict_types=1);

echo "=== DEPRECATED MIGRATION ===\n\n";
echo "This migration script is deprecated and should not be used.\n";
echo "Partial hash columns have been removed from the schema.\n\n";
echo "Please use: php scripts/migrate_add_hash_columns.php\n\n";
exit(0);

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
    
    echo "=== Adding Partial Hash Columns Migration ===\n\n";
    echo "This migration adds scope-specific hash columns for selective updates:\n";
    echo "  - price_hash: Pricing and inventory fields\n";
    echo "  - media_hash: Image and media relationships\n";
    echo "  - content_hash: Descriptions and content fields\n\n";
    
    $table = 'Artikel';
    
    $db->beginTransaction();
    
    echo "Processing table: {$table}\n";
    
    // Check if columns already exist
    $pragma = $db->query("PRAGMA table_info({$table})");
    $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'name');
    
    $newColumns = [
        'price_hash' => 'Price and inventory scope hash',
        'media_hash' => 'Media and image scope hash',
        'content_hash' => 'Content and description scope hash',
    ];
    
    foreach ($newColumns as $columnName => $description) {
        $hasColumn = in_array($columnName, $columnNames, true);
        
        if (!$hasColumn) {
            echo "  Adding {$columnName} column... ({$description})\n";
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$columnName} TEXT");
        } else {
            echo "  {$columnName} column already exists\n";
        }
    }
    
    echo "\n";
    
    // Add indices for partial hash columns to improve query performance
    echo "Creating indices for partial hash columns...\n";
    
    $indices = [
        "CREATE INDEX IF NOT EXISTS ix_artikel_price_hash ON Artikel(price_hash)",
        "CREATE INDEX IF NOT EXISTS ix_artikel_media_hash ON Artikel(media_hash)",
        "CREATE INDEX IF NOT EXISTS ix_artikel_content_hash ON Artikel(content_hash)",
    ];
    
    foreach ($indices as $indexSql) {
        $db->exec($indexSql);
    }
    
    echo "Indices created successfully\n\n";
    
    $db->commit();
    
    echo "✓ Migration completed successfully!\n";
    echo "\nPartial hash columns added to Artikel table:\n";
    foreach ($newColumns as $columnName => $description) {
        echo "  - {$columnName}: {$description}\n";
    }
    echo "\nYou can now use partial hash scopes for selective table updates.\n";
    echo "See mappings/target_sqlite.yml (change_detection section) for scope definitions.\n";
    
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
