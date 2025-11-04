<?php
declare(strict_types=1);

/**
 * Performance test for BatchSyncEngine with varying data sizes
 */

require_once dirname(__DIR__) . '/autoload.php';

echo "=== BatchSyncEngine Performance Test ===\n\n";

// Test with different data sizes
$testSizes = [10, 50, 100, 500, 1000];

foreach ($testSizes as $size) {
    echo str_repeat("-", 60) . "\n";
    echo "Testing with $size rows\n";
    echo str_repeat("-", 60) . "\n";
    
    // Setup fresh database
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("
        CREATE TABLE category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            afs_id TEXT UNIQUE,
            name TEXT
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
    
    $pdo->exec("INSERT INTO category (afs_id, name) VALUES ('WG001', 'Werkzeuge')");
    $pdo->exec("INSERT INTO category (afs_id, name) VALUES ('WG002', 'Schrauben')");
    
    // Generate test data
    $testData = [];
    for ($i = 1; $i <= $size; $i++) {
        $testData[] = [
            'Artikelnummer' => sprintf('ART%06d', $i),
            'Bezeichnung' => 'Test Artikel ' . $i,
            'Warengruppe' => ($i % 2 === 0) ? 'WG001' : 'WG002',
            'Preis' => rand(100, 10000) / 100,
        ];
    }
    
    // Create mock source
    $mockMapper = new class($testData) {
        private array $data;
        public function __construct(array $data) { $this->data = $data; }
        public function fetch($conn, string $table): array { return $this->data; }
    };
    
    $sources = [
        'afs' => [
            'type' => 'mapper',
            'mapper' => $mockMapper,
            'connection' => new stdClass(),
        ],
    ];
    
    $targetMapper = new class {
        public function getUniqueKeyColumns(string $table): array {
            return $table === 'artikel' ? ['model'] : [];
        }
    };
    
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
    
    // Run sync
    $engine = new BatchSyncEngine($sources, $targetMapper, $manifest);
    
    $startTime = microtime(true);
    $stats = $engine->syncEntity('artikel', $pdo);
    $totalDuration = microtime(true) - $startTime;
    
    // Verify
    $count = (int)$pdo->query('SELECT COUNT(*) FROM artikel')->fetchColumn();
    
    // Display results
    printf("Rows: %d\n", $count);
    printf("Total Duration: %.2f ms\n", $totalDuration * 1000);
    
    if (isset($stats['timing'])) {
        printf("  - Load & Normalize: %.2f ms\n", $stats['timing']['load_normalize_ms']);
        printf("  - Write to DB: %.2f ms\n", $stats['timing']['write_ms']);
        $overhead = $stats['timing']['total_ms'] - $stats['timing']['load_normalize_ms'] - $stats['timing']['write_ms'];
        printf("  - Overhead: %.2f ms\n", $overhead);
    }
    
    printf("Throughput: %.0f rows/sec\n", $size / $totalDuration);
    printf("Per-row: %.3f ms\n", ($totalDuration * 1000) / $size);
    
    if ($count === $size) {
        echo "✓ Verification passed\n";
    } else {
        echo "✗ Verification failed (expected $size, got $count)\n";
    }
    
    echo "\n";
}

echo "✓ Performance test completed!\n";
