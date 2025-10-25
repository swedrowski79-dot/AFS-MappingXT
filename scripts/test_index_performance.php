#!/usr/bin/env php
<?php
/**
 * Test Script: Index Performance Validation
 * 
 * Tests the performance impact of database indexes on common query patterns.
 */

require_once __DIR__ . '/../autoload.php';

class IndexPerformanceTest
{
    private PDO $db;
    private array $results = [];
    
    public function __construct(string $dbPath)
    {
        if (!file_exists($dbPath)) {
            throw new Exception("Database not found: {$dbPath}");
        }
        
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    
    public function run(): void
    {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  Index Performance Test - AFS-MappingXT\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $this->testUpdateFlagQueries();
        $this->testForeignKeyLookups();
        $this->testXtIdLookups();
        $this->testCategoryHierarchy();
        $this->testLogQueries();
        
        $this->printSummary();
    }
    
    private function testUpdateFlagQueries(): void
    {
        echo "Testing Update Flag Indexes...\n";
        
        // Test Artikel update flag
        $this->timeQuery(
            'Artikel UPDATE=1',
            'SELECT COUNT(*) FROM Artikel WHERE "update" = 1',
            'ix_artikel_update'
        );
        
        // Test Bilder update flag
        $this->timeQuery(
            'Bilder UPDATE=1',
            'SELECT COUNT(*) FROM Bilder WHERE "update" = 1',
            'ix_bilder_update'
        );
        
        // Test junction table update flag
        $this->timeQuery(
            'Artikel_Bilder UPDATE=1',
            'SELECT COUNT(*) FROM Artikel_Bilder WHERE "update" = 1',
            'ix_artikel_bilder_update'
        );
        
        echo "\n";
    }
    
    private function testForeignKeyLookups(): void
    {
        echo "Testing Foreign Key Indexes...\n";
        
        // Get a random artikel ID for testing
        $stmt = $this->db->query('SELECT ID FROM Artikel LIMIT 1');
        $artikelId = $stmt ? $stmt->fetchColumn() : 1;
        
        if ($artikelId) {
            $this->timeQuery(
                'Artikel Images Lookup',
                "SELECT COUNT(*) FROM Artikel_Bilder WHERE Artikel_ID = {$artikelId}",
                'SEARCH Artikel_Bilder USING INDEX ix_artikel_bilder_artikel'
            );
            
            $this->timeQuery(
                'Artikel Documents Lookup',
                "SELECT COUNT(*) FROM Artikel_Dokumente WHERE Artikel_ID = {$artikelId}",
                'SEARCH Artikel_Dokumente USING INDEX ix_artikel_dokumente_artikel'
            );
            
            $this->timeQuery(
                'Artikel Attributes Lookup',
                "SELECT COUNT(*) FROM Attrib_Artikel WHERE Artikel_ID = {$artikelId}",
                'SEARCH Attrib_Artikel USING INDEX ix_attrib_artikel_artikel'
            );
        } else {
            echo "  ⚠ No data to test foreign key lookups\n";
        }
        
        echo "\n";
    }
    
    private function testXtIdLookups(): void
    {
        echo "Testing XT_ID Indexes...\n";
        
        $this->timeQuery(
            'Artikel by XT_ID',
            'SELECT COUNT(*) FROM Artikel WHERE XT_ID IS NOT NULL',
            'ix_artikel_xt_id'
        );
        
        $this->timeQuery(
            'Category by xtid',
            'SELECT COUNT(*) FROM category WHERE xtid IS NOT NULL',
            'ix_category_xtid'
        );
        
        echo "\n";
    }
    
    private function testCategoryHierarchy(): void
    {
        echo "Testing Category Hierarchy Indexes...\n";
        
        $this->timeQuery(
            'Category by Parent',
            'SELECT COUNT(*) FROM category WHERE Parent IS NOT NULL',
            'ix_category_parent'
        );
        
        $this->timeQuery(
            'Online Categories',
            'SELECT COUNT(*) FROM category WHERE online = 1',
            'ix_category_online'
        );
        
        echo "\n";
    }
    
    private function testLogQueries(): void
    {
        echo "Testing Status DB Indexes...\n";
        
        // Get the database path from connection
        $statusDbPath = __DIR__ . '/../db/status.db';
        
        if (file_exists($statusDbPath)) {
            $statusDb = new PDO('sqlite:' . $statusDbPath);
            $statusDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Test log level index
            $start = microtime(true);
            $stmt = $statusDb->query("SELECT COUNT(*) FROM sync_log WHERE level = 'error'");
            $duration = (microtime(true) - $start) * 1000;
            
            $explain = $statusDb->query("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM sync_log WHERE level = 'error'")->fetchAll();
            $usesIndex = false;
            foreach ($explain as $row) {
                if (stripos($row['detail'] ?? '', 'ix_sync_log_level') !== false) {
                    $usesIndex = true;
                    break;
                }
            }
            
            echo "  ✓ Error Log Query: " . number_format($duration, 2) . " ms";
            echo $usesIndex ? " (uses index)\n" : " (no index!)\n";
            
            $this->results['Log Queries'] = [
                'time' => $duration,
                'index' => $usesIndex
            ];
        } else {
            echo "  ⚠ Status database not found\n";
        }
        
        echo "\n";
    }
    
    private function timeQuery(string $name, string $query, string $expectedIndex): void
    {
        // Time the query
        $start = microtime(true);
        $stmt = $this->db->query($query);
        $duration = (microtime(true) - $start) * 1000; // Convert to milliseconds
        
        // Check query plan
        $explainQuery = "EXPLAIN QUERY PLAN {$query}";
        $plan = $this->db->query($explainQuery)->fetchAll();
        
        $usesIndex = false;
        $planDetails = '';
        foreach ($plan as $row) {
            $detail = $row['detail'] ?? '';
            $planDetails .= $detail . ' ';
            // Check if the expected index is mentioned in the query plan
            // A COVERING INDEX or USING INDEX both mean the index is being used
            if (stripos($detail, $expectedIndex) !== false) {
                $usesIndex = true;
            }
        }
        
        echo "  ";
        echo $usesIndex ? "✓" : "✗";
        echo " {$name}: " . number_format($duration, 2) . " ms";
        echo $usesIndex ? " (uses index)\n" : " (NO INDEX USED!)\n";
        
        if (!$usesIndex) {
            echo "    Plan: {$planDetails}\n";
        }
        
        $this->results[$name] = [
            'time' => $duration,
            'index' => $usesIndex,
            'plan' => trim($planDetails)
        ];
    }
    
    private function printSummary(): void
    {
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  Summary\n";
        echo "═══════════════════════════════════════════════════════════════\n\n";
        
        $total = count($this->results);
        $withIndex = 0;
        $totalTime = 0;
        
        foreach ($this->results as $result) {
            if ($result['index']) {
                $withIndex++;
            }
            $totalTime += $result['time'];
        }
        
        echo "Total Queries Tested: {$total}\n";
        echo "Queries Using Indexes: {$withIndex}\n";
        echo "Index Usage Rate: " . number_format(($withIndex / $total) * 100, 1) . "%\n";
        echo "Total Query Time: " . number_format($totalTime, 2) . " ms\n";
        echo "Average Query Time: " . number_format($totalTime / $total, 2) . " ms\n";
        
        if ($withIndex === $total) {
            echo "\n✓ All queries are using indexes - EXCELLENT!\n";
        } else {
            echo "\n⚠ Some queries are not using indexes - check migration!\n";
        }
        
        echo "\n";
    }
}

// Main execution
try {
    $dbPath = __DIR__ . '/../db/evo.db';
    $test = new IndexPerformanceTest($dbPath);
    $test->run();
    exit(0);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
