<?php
declare(strict_types=1);

/**
 * Test BatchSyncEngine - RAM normalization with TEMP staging tables
 */

require_once dirname(__DIR__) . '/autoload.php';

echo "=== Testing BatchSyncEngine with TEMP Staging Tables ===\n\n";

try {
    // Create test database
    $testDb = ':memory:';
    $pdo = new PDO('sqlite:' . $testDb);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create test schema
    $pdo->exec("
        CREATE TABLE category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            afs_id TEXT UNIQUE,
            name TEXT,
            seo_slug TEXT UNIQUE
        )
    ");
    
    $pdo->exec("
        CREATE TABLE artikel (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            model TEXT UNIQUE NOT NULL,
            name TEXT,
            category INTEGER DEFAULT 0,
            price REAL
        )
    ");
    
    $pdo->exec("
        CREATE TABLE attribute (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            value TEXT
        )
    ");
    
    echo "✓ Test database schema created\n\n";
    
    // Insert test categories
    $pdo->exec("INSERT INTO category (afs_id, name, seo_slug) VALUES ('WG001', 'Werkzeuge', 'de/werkzeuge')");
    $pdo->exec("INSERT INTO category (afs_id, name, seo_slug) VALUES ('WG002', 'Schrauben', 'de/schrauben')");
    
    echo "✓ Test categories inserted\n\n";
    
    // Prepare test manifest
    $manifest = [
        'entities' => [
            'artikel' => [
                'from' => 'afs.Artikel',
                'map' => [
                    'evo.artikel.model' => 'afs.Artikel.Artikelnummer',
                    'evo.artikel.name' => 'afs.Artikel.Bezeichnung',
                    'evo.artikel.category' => 'afs.Artikel.Warengruppe',
                    'evo.artikel.price' => 'afs.Artikel.Preis',
                ],
            ],
        ],
    ];
    
    // Create mock source mapper
    $mockMapper = new class {
        public function fetch($connection, string $table): array {
            if ($table === 'Artikel') {
                return [
                    [
                        'Artikelnummer' => 'ART001',
                        'Bezeichnung' => 'Hammer',
                        'Warengruppe' => 'WG001',
                        'Preis' => '29.99',
                    ],
                    [
                        'Artikelnummer' => 'ART002',
                        'Bezeichnung' => 'Schraubendreher',
                        'Warengruppe' => 'WG001',
                        'Preis' => '15.50',
                    ],
                    [
                        'Artikelnummer' => 'ART003',
                        'Bezeichnung' => 'Holzschraube 4x40',
                        'Warengruppe' => 'WG002',
                        'Preis' => '0.05',
                    ],
                ];
            }
            return [];
        }
    };
    
    $mockConnection = new stdClass();
    
    $sources = [
        'afs' => [
            'type' => 'mapper',
            'mapper' => $mockMapper,
            'connection' => $mockConnection,
        ],
    ];
    
    // Create target mapper
    $targetMapper = new class {
        public function getUniqueKeyColumns(string $table): array {
            switch ($table) {
                case 'artikel':
                    return ['model'];
                case 'category':
                    return ['afs_id'];
                case 'attribute':
                    return ['name'];
                default:
                    return [];
            }
        }
        
        public function upsert(PDO $pdo, string $table, array $payload): void {
            // Not used in batch mode
        }
    };
    
    // Create BatchSyncEngine
    echo "Creating BatchSyncEngine...\n";
    $engine = new BatchSyncEngine($sources, $targetMapper, $manifest);
    
    echo "✓ BatchSyncEngine created\n\n";
    
    // Test syncEntity
    echo "Syncing 'artikel' entity...\n";
    $startTime = microtime(true);
    $stats = $engine->syncEntity('artikel', $pdo);
    $duration = microtime(true) - $startTime;
    
    echo "\n=== Sync Statistics ===\n";
    echo "Mode: " . ($stats['mode'] ?? 'unknown') . "\n";
    echo "Processed: " . ($stats['processed'] ?? 0) . "\n";
    echo "Inserted: " . ($stats['inserted'] ?? 0) . "\n";
    echo "Updated: " . ($stats['updated'] ?? 0) . "\n";
    echo "Unchanged: " . ($stats['unchanged'] ?? 0) . "\n";
    echo "Errors: " . ($stats['errors'] ?? 0) . "\n";
    
    if (isset($stats['timing'])) {
        echo "\n=== Performance ===\n";
        echo "Load & Normalize: " . $stats['timing']['load_normalize_ms'] . " ms\n";
        echo "Write to DB: " . $stats['timing']['write_ms'] . " ms\n";
        echo "Total: " . $stats['timing']['total_ms'] . " ms\n";
    } else {
        echo "\nTotal Duration: " . round($duration * 1000, 2) . " ms\n";
    }
    
    // Verify results
    echo "\n=== Verification ===\n";
    $stmt = $pdo->query('SELECT COUNT(*) FROM artikel');
    $count = $stmt ? (int)$stmt->fetchColumn() : 0;
    echo "Total artikel in database: $count\n";
    
    if ($count === 3) {
        echo "✓ Expected 3 artikel, got $count\n";
    } else {
        echo "✗ Expected 3 artikel, got $count\n";
    }
    
    // Show sample data
    echo "\n=== Sample Data ===\n";
    $stmt = $pdo->query('SELECT * FROM artikel ORDER BY model');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo sprintf(
                "  [%s] %s | Category: %d | Price: %s\n",
                $row['model'] ?? '',
                $row['name'] ?? '',
                $row['category'] ?? 0,
                $row['price'] ?? ''
            );
        }
    }
    
    // Test update scenario (run sync again)
    echo "\n=== Testing UPDATE scenario ===\n";
    echo "Running sync again with same data...\n";
    $stats2 = $engine->syncEntity('artikel', $pdo);
    
    echo "Processed: " . ($stats2['processed'] ?? 0) . "\n";
    echo "Inserted: " . ($stats2['inserted'] ?? 0) . "\n";
    echo "Updated: " . ($stats2['updated'] ?? 0) . "\n";
    
    $stmt = $pdo->query('SELECT COUNT(*) FROM artikel');
    $count2 = $stmt ? (int)$stmt->fetchColumn() : 0;
    
    if ($count2 === 3) {
        echo "✓ Count unchanged after second sync: $count2\n";
    } else {
        echo "✗ Count should be 3 but is: $count2\n";
    }
    
    echo "\n✓ All tests completed successfully!\n";
    
} catch (Throwable $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
