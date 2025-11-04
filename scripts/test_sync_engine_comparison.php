<?php
declare(strict_types=1);

/**
 * Comparison test: MappingSyncEngine vs BatchSyncEngine
 * Tests performance and correctness
 */

require_once dirname(__DIR__) . '/autoload.php';

class SyncEngineComparison
{
    private PDO $pdo;
    private array $sources;
    private $targetMapper;
    private array $manifest;
    
    public function run(): void
    {
        echo "=== Sync Engine Comparison: MappingSyncEngine vs BatchSyncEngine ===\n\n";
        
        $this->setupTestEnvironment();
        
        // Test with varying data sizes
        $dataSizes = [10, 50, 100, 500];
        
        foreach ($dataSizes as $size) {
            echo str_repeat("=", 70) . "\n";
            echo "Testing with $size rows\n";
            echo str_repeat("=", 70) . "\n\n";
            
            $this->compareEngines($size);
            echo "\n";
        }
        
        echo "✓ All comparison tests completed!\n";
    }
    
    private function setupTestEnvironment(): void
    {
        // Create in-memory database
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create schema
        $this->pdo->exec("
            CREATE TABLE category (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                afs_id TEXT UNIQUE,
                name TEXT,
                seo_slug TEXT
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE artikel (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model TEXT UNIQUE NOT NULL,
                name TEXT,
                category INTEGER DEFAULT 0,
                price REAL,
                stock INTEGER
            )
        ");
        
        // Insert test categories
        $this->pdo->exec("INSERT INTO category (afs_id, name, seo_slug) VALUES ('WG001', 'Werkzeuge', 'de/werkzeuge')");
        $this->pdo->exec("INSERT INTO category (afs_id, name, seo_slug) VALUES ('WG002', 'Schrauben', 'de/schrauben')");
        
        // Setup manifest
        $this->manifest = [
            'entities' => [
                'artikel' => [
                    'from' => 'afs.Artikel',
                    'map' => [
                        'evo.artikel.model' => 'afs.Artikel.Artikelnummer',
                        'evo.artikel.name' => 'afs.Artikel.Bezeichnung',
                        'evo.artikel.category' => 'afs.Artikel.Warengruppe',
                        'evo.artikel.price' => 'afs.Artikel.Preis',
                        'evo.artikel.stock' => 'afs.Artikel.Bestand',
                    ],
                ],
            ],
        ];
        
        // Create target mapper mock
        $this->targetMapper = new class {
            public function getUniqueKeyColumns(string $table): array {
                return match($table) {
                    'artikel' => ['model'],
                    'category' => ['afs_id'],
                    default => []
                };
            }
            
            public function upsert(PDO $pdo, string $table, array $payload): void {
                $keys = $this->getUniqueKeyColumns($table);
                if ($keys === []) {
                    return;
                }
                
                $columns = array_keys($payload);
                $placeholders = array_map(fn($col) => ":$col", $columns);
                
                $quotedCols = array_map(fn($col) => "\"$col\"", $columns);
                
                $sql = sprintf(
                    "INSERT OR REPLACE INTO \"%s\" (%s) VALUES (%s)",
                    $table,
                    implode(', ', $quotedCols),
                    implode(', ', $placeholders)
                );
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($payload);
            }
        };
    }
    
    private function generateMockData(int $count): array
    {
        $data = [];
        $categories = ['WG001', 'WG002'];
        
        for ($i = 1; $i <= $count; $i++) {
            $data[] = [
                'Artikelnummer' => sprintf('ART%05d', $i),
                'Bezeichnung' => 'Artikel ' . $i,
                'Warengruppe' => $categories[$i % 2],
                'Preis' => round(rand(100, 10000) / 100, 2),
                'Bestand' => rand(0, 1000),
            ];
        }
        
        return $data;
    }
    
    private function compareEngines(int $dataSize): void
    {
        // Generate test data
        $testData = $this->generateMockData($dataSize);
        
        // Create mock source mapper
        $mockMapper = new class($testData) {
            private array $data;
            
            public function __construct(array $data) {
                $this->data = $data;
            }
            
            public function fetch($connection, string $table): array {
                return $this->data;
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
        
        // Clear table
        $this->pdo->exec('DELETE FROM artikel');
        
        // Test MappingSyncEngine
        echo "1. MappingSyncEngine:\n";
        $oldEngine = new MappingSyncEngine($sources, $this->targetMapper, $this->manifest);
        
        $startTime = microtime(true);
        $oldStats = $oldEngine->syncEntity('artikel', $this->pdo);
        $oldDuration = microtime(true) - $startTime;
        
        $oldCount = (int)$this->pdo->query('SELECT COUNT(*) FROM artikel')->fetchColumn();
        
        printf("   Duration: %.2f ms\n", $oldDuration * 1000);
        printf("   Processed: %d\n", $oldStats['processed'] ?? 0);
        printf("   Rows in DB: %d\n", $oldCount);
        
        // Clear table again
        $this->pdo->exec('DELETE FROM artikel');
        
        // Test BatchSyncEngine
        echo "\n2. BatchSyncEngine:\n";
        $newEngine = new BatchSyncEngine($sources, $this->targetMapper, $this->manifest);
        
        $startTime = microtime(true);
        $newStats = $newEngine->syncEntity('artikel', $this->pdo);
        $newDuration = microtime(true) - $startTime;
        
        $newCount = (int)$this->pdo->query('SELECT COUNT(*) FROM artikel')->fetchColumn();
        
        printf("   Duration: %.2f ms\n", $newDuration * 1000);
        printf("   Processed: %d\n", $newStats['processed'] ?? 0);
        printf("   Inserted: %d\n", $newStats['inserted'] ?? 0);
        printf("   Rows in DB: %d\n", $newCount);
        
        if (isset($newStats['timing'])) {
            printf("   - Load & Normalize: %.2f ms\n", $newStats['timing']['load_normalize_ms']);
            printf("   - Write to DB: %.2f ms\n", $newStats['timing']['write_ms']);
        }
        
        // Compare results
        echo "\n3. Comparison:\n";
        
        if ($oldCount === $newCount && $newCount === $dataSize) {
            echo "   ✓ Result count matches: $newCount\n";
        } else {
            echo "   ✗ Result mismatch - Old: $oldCount, New: $newCount, Expected: $dataSize\n";
        }
        
        $speedup = $oldDuration > 0 ? ($oldDuration / $newDuration) : 0;
        printf("   Speedup: %.2fx faster\n", $speedup);
        
        $percentFaster = $oldDuration > 0 ? (($oldDuration - $newDuration) / $oldDuration * 100) : 0;
        printf("   Performance gain: %.1f%%\n", $percentFaster);
        
        // Verify data integrity
        $stmt = $this->pdo->query('SELECT model, name, category FROM artikel ORDER BY model LIMIT 3');
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($samples) > 0) {
            echo "\n   Sample data (first 3 rows):\n";
            foreach ($samples as $sample) {
                printf("   - [%s] %s (Cat: %d)\n", 
                    $sample['model'] ?? '', 
                    $sample['name'] ?? '', 
                    $sample['category'] ?? 0
                );
            }
        }
    }
}

// Run comparison
try {
    $comparison = new SyncEngineComparison();
    $comparison->run();
} catch (Throwable $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
