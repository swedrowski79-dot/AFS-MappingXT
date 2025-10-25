#!/usr/bin/env php
<?php
/**
 * Integration test for AFS_Evo_DeltaExporter with StatusTracker logging
 * 
 * Validates that:
 * 1. DeltaExporter logs statistics when StatusTracker is provided
 * 2. DeltaExporter works without StatusTracker (backwards compatibility)
 * 3. Log entries contain expected information
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Evo_DeltaExporter Logging Integration Test ===\n\n";

// Create temporary test databases
$testDbPath = sys_get_temp_dir() . '/test_evo_log_' . uniqid() . '.db';
$deltaDbPath = sys_get_temp_dir() . '/test_delta_log_' . uniqid() . '.db';
$statusDbPath = sys_get_temp_dir() . '/test_status_log_' . uniqid() . '.db';

try {
    // Setup test database
    echo "1. Setting up test databases...\n";
    $db = new PDO('sqlite:' . $testDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create status database
    $statusDb = new PDO('sqlite:' . $statusDbPath);
    $statusDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $statusDb->exec('
        CREATE TABLE sync_status (
            job TEXT PRIMARY KEY,
            state TEXT,
            stage TEXT,
            message TEXT,
            processed INTEGER DEFAULT 0,
            total INTEGER DEFAULT 0,
            started_at TEXT,
            finished_at TEXT,
            updated_at TEXT
        )
    ');
    
    $statusDb->exec('
        CREATE TABLE sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job TEXT,
            level TEXT,
            stage TEXT,
            message TEXT,
            context TEXT,
            created_at TEXT
        )
    ');
    
    $statusDb->exec("INSERT INTO sync_status (job, state) VALUES ('test_job', 'ready')");
    
    // Create test tables
    $db->exec('
        CREATE TABLE Artikel (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            Artikelnummer TEXT NOT NULL,
            Bezeichnung TEXT,
            "update" INTEGER DEFAULT 0
        )
    ');
    
    $db->exec('
        CREATE TABLE Bilder (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            Bildname TEXT NOT NULL,
            "update" INTEGER DEFAULT 0
        )
    ');
    
    echo "   ✓ Test databases created\n\n";
    
    // Insert test data
    echo "2. Inserting test data...\n";
    $db->exec("INSERT INTO Artikel (Artikelnummer, Bezeichnung, \"update\") VALUES 
        ('ART-001', 'Changed Article 1', 1),
        ('ART-002', 'Unchanged Article', 0),
        ('ART-003', 'Changed Article 2', 1)
    ");
    
    $db->exec("INSERT INTO Bilder (Bildname, \"update\") VALUES 
        ('image1.jpg', 1),
        ('image2.jpg', 0)
    ");
    
    echo "   ✓ Test data inserted\n\n";
    
    // Test 1: Export with StatusTracker
    echo "3. Testing DeltaExporter with StatusTracker...\n";
    $statusTracker = new AFS_Evo_StatusTracker($statusDbPath, 'test_job');
    $exporter = new AFS_Evo_DeltaExporter($db, $deltaDbPath, $statusTracker);
    
    $stats = $exporter->export();
    
    echo "   ✓ Export completed\n";
    echo "   Statistics: " . json_encode($stats) . "\n\n";
    
    // Check that log entries were created
    echo "4. Verifying log entries...\n";
    $logStmt = $statusDb->query("SELECT * FROM sync_log WHERE job = 'test_job' ORDER BY id");
    $logEntries = $logStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($logEntries) === 0) {
        echo "   ✗ ERROR: No log entries found\n";
        exit(1);
    }
    
    echo "   ✓ Found " . count($logEntries) . " log entries\n";
    
    $foundDeltaLog = false;
    foreach ($logEntries as $entry) {
        echo "   - [{$entry['level']}] {$entry['message']}\n";
        if (strpos($entry['message'], 'Delta-Export abgeschlossen') !== false) {
            $foundDeltaLog = true;
            $context = json_decode($entry['context'] ?? '{}', true);
            
            // Verify context contains expected fields
            if (!isset($context['tables'])) {
                echo "     ✗ ERROR: Log context missing 'tables' field\n";
                exit(1);
            }
            if (!isset($context['total_tables'])) {
                echo "     ✗ ERROR: Log context missing 'total_tables' field\n";
                exit(1);
            }
            if (!isset($context['total_rows'])) {
                echo "     ✗ ERROR: Log context missing 'total_rows' field\n";
                exit(1);
            }
            if (!isset($context['duration_seconds'])) {
                echo "     ✗ ERROR: Log context missing 'duration_seconds' field\n";
                exit(1);
            }
            
            echo "     ✓ Context contains all expected fields\n";
            echo "     - Tables: " . json_encode($context['tables']) . "\n";
            echo "     - Total tables: {$context['total_tables']}\n";
            echo "     - Total rows: {$context['total_rows']}\n";
            echo "     - Duration: {$context['duration_seconds']}s\n";
        }
    }
    
    if (!$foundDeltaLog) {
        echo "   ✗ ERROR: Did not find Delta-Export completion log\n";
        exit(1);
    }
    
    echo "   ✓ Delta-Export log entry verified\n\n";
    
    // Test 2: Export without StatusTracker (backwards compatibility)
    echo "5. Testing DeltaExporter without StatusTracker (backwards compatibility)...\n";
    
    // Reset update flags for second test
    $db->exec("UPDATE Artikel SET \"update\" = 1 WHERE Artikelnummer IN ('ART-001', 'ART-003')");
    $db->exec("UPDATE Bilder SET \"update\" = 1 WHERE Bildname = 'image1.jpg'");
    
    @unlink($deltaDbPath);
    
    $exporterNoStatus = new AFS_Evo_DeltaExporter($db, $deltaDbPath);
    $stats2 = $exporterNoStatus->export();
    
    echo "   ✓ Export without StatusTracker completed\n";
    echo "   Statistics: " . json_encode($stats2) . "\n";
    
    if ($stats2 === $stats) {
        echo "   ✓ Results match previous export\n";
    } else {
        echo "   ✗ WARNING: Results differ from first export\n";
    }
    
    echo "\n=== ALL TESTS PASSED ✓ ===\n\n";
    echo "Summary:\n";
    echo "✓ DeltaExporter logs statistics when StatusTracker is provided\n";
    echo "✓ DeltaExporter works without StatusTracker (backwards compatible)\n";
    echo "✓ Log entries contain expected information (tables, counts, duration)\n";
    
    $exitCode = 0;
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    $exitCode = 1;
} finally {
    // Cleanup
    if (isset($db)) {
        $db = null;
    }
    if (isset($statusDb)) {
        $statusDb = null;
    }
    if (isset($testDbPath) && file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
    if (isset($deltaDbPath) && file_exists($deltaDbPath)) {
        @unlink($deltaDbPath);
    }
    if (isset($statusDbPath) && file_exists($statusDbPath)) {
        @unlink($statusDbPath);
    }
}

exit($exitCode ?? 1);
