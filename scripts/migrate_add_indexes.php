#!/usr/bin/env php
<?php
/**
 * Migration Script: Add Performance-Optimizing Indexes
 * 
 * This script adds new indexes to existing databases to optimize query performance:
 * - Update flag indexes for delta export operations
 * - Foreign key indexes for junction table lookups
 * - XT_ID indexes for bi-directional synchronization
 * - Additional indexes for common query patterns
 * 
 * Safe to run multiple times (uses IF NOT EXISTS).
 */

require_once __DIR__ . '/../autoload.php';

$dbPath = __DIR__ . '/../db/evo.db';
$statusDbPath = __DIR__ . '/../db/status.db';

function migrateEvoDatabase(string $dbPath): void
{
    echo "Migrating evo.db: Adding performance indexes...\n";
    
    if (!file_exists($dbPath)) {
        echo "  ⚠ Database not found: {$dbPath}\n";
        echo "  Run scripts/setup.php first to create the database.\n";
        return;
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Start transaction for atomic migration
        $db->beginTransaction();
        
        $indexes = [
            // Artikel table indexes
            'CREATE INDEX IF NOT EXISTS ix_artikel_update ON Artikel("update") WHERE "update" = 1',
            'CREATE INDEX IF NOT EXISTS ix_artikel_xt_id ON Artikel(XT_ID)',
            'CREATE INDEX IF NOT EXISTS ix_artikel_category ON Artikel(Category)',

            // Media table indexes
            'CREATE INDEX IF NOT EXISTS ix_media_upload ON media(upload) WHERE upload = 1',
            'CREATE INDEX IF NOT EXISTS ix_media_status ON media(status)',
            'CREATE INDEX IF NOT EXISTS ix_media_kind ON media(kind)',

            // Media relation indexes
            'CREATE INDEX IF NOT EXISTS ix_media_relation_entity ON media_relation(entity_type, entity_id)',
            'CREATE INDEX IF NOT EXISTS ix_media_relation_status ON media_relation(status)',

            // Attribute table indexes
            'CREATE INDEX IF NOT EXISTS ix_attribute_update ON Attribute("update") WHERE "update" = 1',
            'CREATE INDEX IF NOT EXISTS ix_attribute_xt_id ON Attribute(XT_Attrib_ID)',
            
            // Attrib_Artikel junction table indexes
            'CREATE INDEX IF NOT EXISTS ix_attrib_artikel_artikel ON Attrib_Artikel(Artikel_ID)',
            'CREATE INDEX IF NOT EXISTS ix_attrib_artikel_attribute ON Attrib_Artikel(Attribute_ID)',
            'CREATE INDEX IF NOT EXISTS ix_attrib_artikel_update ON Attrib_Artikel("update") WHERE "update" = 1',
            
            // Category table indexes
            'CREATE INDEX IF NOT EXISTS ix_category_update ON category("update") WHERE "update" = 1',
            'CREATE INDEX IF NOT EXISTS ix_category_xtid ON category(xtid)',
            'CREATE INDEX IF NOT EXISTS ix_category_online ON category(online)',
        ];
        
        $created = 0;
        $skipped = 0;
        
        foreach ($indexes as $sql) {
            try {
                $db->exec($sql);
                // Check if index was actually created by querying sqlite_master
                preg_match('/ix_[a-z_]+/', $sql, $matches);
                $indexName = $matches[0] ?? 'unknown';
                
                $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name = :name");
                $stmt->execute([':name' => $indexName]);
                if ($stmt->fetchColumn() > 0) {
                    echo "  ✓ Created index: {$indexName}\n";
                    $created++;
                } else {
                    $skipped++;
                }
            } catch (PDOException $e) {
                // If index already exists, SQLite will silently skip it
                // This should not happen with IF NOT EXISTS, but we handle it anyway
                echo "  ⚠ Warning: " . $e->getMessage() . "\n";
                $skipped++;
            }
        }
        
        $db->commit();
        
        echo "\n";
        echo "Migration completed successfully:\n";
        echo "  - Indexes created: {$created}\n";
        echo "  - Indexes skipped (already exist): {$skipped}\n";
        echo "  - Total processed: " . ($created + $skipped) . "\n";
        
        // Run ANALYZE to update query planner statistics
        echo "\nAnalyzing database for query optimization...\n";
        $db->exec('ANALYZE');
        echo "  ✓ Database analysis completed\n";
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function migrateStatusDatabase(string $dbPath): void
{
    echo "\nMigrating status.db: Adding performance indexes...\n";
    
    if (!file_exists($dbPath)) {
        echo "  ⚠ Database not found: {$dbPath}\n";
        echo "  Run scripts/setup.php first to create the database.\n";
        return;
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $db->beginTransaction();
        
        $indexes = [
            'CREATE INDEX IF NOT EXISTS ix_sync_log_level ON sync_log(level)',
            'CREATE INDEX IF NOT EXISTS ix_sync_log_stage ON sync_log(stage)',
            'CREATE INDEX IF NOT EXISTS ix_sync_log_created ON sync_log(created_at DESC)',
        ];
        
        $created = 0;
        
        foreach ($indexes as $sql) {
            try {
                $db->exec($sql);
                preg_match('/ix_[a-z_]+/', $sql, $matches);
                $indexName = $matches[0] ?? 'unknown';
                echo "  ✓ Created index: {$indexName}\n";
                $created++;
            } catch (PDOException $e) {
                echo "  ⚠ Warning: " . $e->getMessage() . "\n";
            }
        }
        
        $db->commit();
        
        echo "\nStatus DB migration completed: {$created} indexes created\n";
        
        // Run ANALYZE
        echo "Analyzing status database...\n";
        $db->exec('ANALYZE');
        echo "  ✓ Database analysis completed\n";
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Main execution
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Database Index Migration - Performance Optimization\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

migrateEvoDatabase($dbPath);
migrateStatusDatabase($statusDbPath);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  Migration completed successfully!\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\nIndex Benefits:\n";
echo "  • Faster delta exports (update flag indexes)\n";
echo "  • Improved junction table lookups (foreign key indexes)\n";
echo "  • Better bi-directional sync performance (XT_ID indexes)\n";
echo "  • Optimized log queries (status DB indexes)\n";
echo "\nNo application changes required - indexes are transparent to queries.\n";
