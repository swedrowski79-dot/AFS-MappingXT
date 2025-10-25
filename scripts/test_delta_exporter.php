#!/usr/bin/env php
<?php
/**
 * Test script for AFS_Evo_DeltaExporter
 * 
 * Validates that:
 * 1. Only records with update=1 are exported
 * 2. Update flags are reset to 0 after export
 * 3. Statistics are correctly returned
 * 4. No duplicates are created in delta database
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Evo_DeltaExporter Test ===\n\n";

// Create temporary test databases
$testDbPath = sys_get_temp_dir() . '/test_evo_' . uniqid() . '.db';
$deltaDbPath = sys_get_temp_dir() . '/test_delta_' . uniqid() . '.db';

try {
    // Setup test database
    echo "1. Setting up test database...\n";
    $db = new PDO('sqlite:' . $testDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create test tables with update column
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
    
    $db->exec('
        CREATE TABLE category (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            afsid INTEGER,
            Name TEXT,
            "update" INTEGER DEFAULT 0
        )
    ');
    
    echo "   ✓ Test tables created\n\n";
    
    // Insert test data
    echo "2. Inserting test data...\n";
    
    // Articles: 3 with update=1, 2 with update=0
    $db->exec("INSERT INTO Artikel (Artikelnummer, Bezeichnung, \"update\") VALUES 
        ('ART-001', 'Changed Article 1', 1),
        ('ART-002', 'Unchanged Article 1', 0),
        ('ART-003', 'Changed Article 2', 1),
        ('ART-004', 'Unchanged Article 2', 0),
        ('ART-005', 'Changed Article 3', 1)
    ");
    
    // Images: 2 with update=1, 1 with update=0
    $db->exec("INSERT INTO Bilder (Bildname, \"update\") VALUES 
        ('image1.jpg', 1),
        ('image2.jpg', 0),
        ('image3.jpg', 1)
    ");
    
    // Categories: 1 with update=1, 1 with update=0
    $db->exec("INSERT INTO category (afsid, Name, \"update\") VALUES 
        (100, 'Changed Category', 1),
        (200, 'Unchanged Category', 0)
    ");
    
    $artikelCount = $db->query("SELECT COUNT(*) FROM Artikel")->fetchColumn();
    $bilderCount = $db->query("SELECT COUNT(*) FROM Bilder")->fetchColumn();
    $categoryCount = $db->query("SELECT COUNT(*) FROM category")->fetchColumn();
    
    echo "   ✓ Inserted {$artikelCount} articles\n";
    echo "   ✓ Inserted {$bilderCount} images\n";
    echo "   ✓ Inserted {$categoryCount} categories\n";
    
    // Count records with update=1 before export
    $artikelUpdateBefore = $db->query("SELECT COUNT(*) FROM Artikel WHERE \"update\" = 1")->fetchColumn();
    $bilderUpdateBefore = $db->query("SELECT COUNT(*) FROM Bilder WHERE \"update\" = 1")->fetchColumn();
    $categoryUpdateBefore = $db->query("SELECT COUNT(*) FROM category WHERE \"update\" = 1")->fetchColumn();
    
    echo "   ✓ Records with update=1: Artikel={$artikelUpdateBefore}, Bilder={$bilderUpdateBefore}, category={$categoryUpdateBefore}\n\n";
    
    // Test 1: Export with DeltaExporter
    echo "3. Running DeltaExporter...\n";
    $exporter = new AFS_Evo_DeltaExporter($db, $deltaDbPath);
    $stats = $exporter->export();
    
    echo "   ✓ Export completed\n";
    echo "   Statistics returned:\n";
    foreach ($stats as $table => $count) {
        echo "     - {$table}: {$count} records\n";
    }
    echo "\n";
    
    // Test 2: Verify only update=1 records were exported
    echo "4. Verifying exported data...\n";
    
    if (!file_exists($deltaDbPath)) {
        throw new Exception("Delta database file not created!");
    }
    
    $deltaDb = new PDO('sqlite:' . $deltaDbPath);
    $deltaDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $deltaArtikelCount = $deltaDb->query("SELECT COUNT(*) FROM Artikel")->fetchColumn();
    $deltaBilderCount = $deltaDb->query("SELECT COUNT(*) FROM Bilder")->fetchColumn();
    $deltaCategoryCount = $deltaDb->query("SELECT COUNT(*) FROM category")->fetchColumn();
    
    echo "   Delta database contains:\n";
    echo "     - Artikel: {$deltaArtikelCount} records\n";
    echo "     - Bilder: {$deltaBilderCount} records\n";
    echo "     - category: {$deltaCategoryCount} records\n";
    
    // Verify counts match expected
    $success = true;
    if ($deltaArtikelCount != $artikelUpdateBefore) {
        echo "   ✗ ERROR: Expected {$artikelUpdateBefore} Artikel, got {$deltaArtikelCount}\n";
        $success = false;
    } else {
        echo "   ✓ Artikel count correct ({$deltaArtikelCount})\n";
    }
    
    if ($deltaBilderCount != $bilderUpdateBefore) {
        echo "   ✗ ERROR: Expected {$bilderUpdateBefore} Bilder, got {$deltaBilderCount}\n";
        $success = false;
    } else {
        echo "   ✓ Bilder count correct ({$deltaBilderCount})\n";
    }
    
    if ($deltaCategoryCount != $categoryUpdateBefore) {
        echo "   ✗ ERROR: Expected {$categoryUpdateBefore} category, got {$deltaCategoryCount}\n";
        $success = false;
    } else {
        echo "   ✓ category count correct ({$deltaCategoryCount})\n";
    }
    echo "\n";
    
    // Test 3: Verify update flags were reset
    echo "5. Verifying update flags reset...\n";
    
    $artikelUpdateAfter = $db->query("SELECT COUNT(*) FROM Artikel WHERE \"update\" = 1")->fetchColumn();
    $bilderUpdateAfter = $db->query("SELECT COUNT(*) FROM Bilder WHERE \"update\" = 1")->fetchColumn();
    $categoryUpdateAfter = $db->query("SELECT COUNT(*) FROM category WHERE \"update\" = 1")->fetchColumn();
    
    if ($artikelUpdateAfter != 0) {
        echo "   ✗ ERROR: Artikel update flags not reset (found {$artikelUpdateAfter})\n";
        $success = false;
    } else {
        echo "   ✓ All Artikel update flags reset\n";
    }
    
    if ($bilderUpdateAfter != 0) {
        echo "   ✗ ERROR: Bilder update flags not reset (found {$bilderUpdateAfter})\n";
        $success = false;
    } else {
        echo "   ✓ All Bilder update flags reset\n";
    }
    
    if ($categoryUpdateAfter != 0) {
        echo "   ✗ ERROR: category update flags not reset (found {$categoryUpdateAfter})\n";
        $success = false;
    } else {
        echo "   ✓ All category update flags reset\n";
    }
    echo "\n";
    
    // Test 4: Verify no duplicates in delta database
    echo "6. Checking for duplicates in delta database...\n";
    
    // Check Artikel table
    $artikelDuplicates = $deltaDb->query("
        SELECT Artikelnummer, COUNT(*) as cnt 
        FROM Artikel 
        GROUP BY Artikelnummer 
        HAVING COUNT(*) > 1
    ")->fetchAll();
    
    if (count($artikelDuplicates) > 0) {
        echo "   ✗ ERROR: Found duplicates in Artikel table:\n";
        foreach ($artikelDuplicates as $dup) {
            echo "     - {$dup['Artikelnummer']}: {$dup['cnt']} copies\n";
        }
        $success = false;
    } else {
        echo "   ✓ No duplicates in Artikel table\n";
    }
    
    // Check Bilder table
    $bilderDuplicates = $deltaDb->query("
        SELECT Bildname, COUNT(*) as cnt 
        FROM Bilder 
        GROUP BY Bildname 
        HAVING COUNT(*) > 1
    ")->fetchAll();
    
    if (count($bilderDuplicates) > 0) {
        echo "   ✗ ERROR: Found duplicates in Bilder table:\n";
        foreach ($bilderDuplicates as $dup) {
            echo "     - {$dup['Bildname']}: {$dup['cnt']} copies\n";
        }
        $success = false;
    } else {
        echo "   ✓ No duplicates in Bilder table\n";
    }
    
    // Check category table
    $categoryDuplicates = $deltaDb->query("
        SELECT afsid, COUNT(*) as cnt 
        FROM category 
        WHERE afsid IS NOT NULL
        GROUP BY afsid 
        HAVING COUNT(*) > 1
    ")->fetchAll();
    
    if (count($categoryDuplicates) > 0) {
        echo "   ✗ ERROR: Found duplicates in category table:\n";
        foreach ($categoryDuplicates as $dup) {
            echo "     - afsid {$dup['afsid']}: {$dup['cnt']} copies\n";
        }
        $success = false;
    } else {
        echo "   ✓ No duplicates in category table\n";
    }
    echo "\n";
    
    // Test 5: Verify statistics match actual exported data
    echo "7. Verifying statistics accuracy...\n";
    
    if (isset($stats['Artikel']) && $stats['Artikel'] == $deltaArtikelCount) {
        echo "   ✓ Artikel statistics accurate ({$stats['Artikel']})\n";
    } else {
        $reported = $stats['Artikel'] ?? 0;
        echo "   ✗ ERROR: Artikel statistics mismatch (reported: {$reported}, actual: {$deltaArtikelCount})\n";
        $success = false;
    }
    
    if (isset($stats['Bilder']) && $stats['Bilder'] == $deltaBilderCount) {
        echo "   ✓ Bilder statistics accurate ({$stats['Bilder']})\n";
    } else {
        $reported = $stats['Bilder'] ?? 0;
        echo "   ✗ ERROR: Bilder statistics mismatch (reported: {$reported}, actual: {$deltaBilderCount})\n";
        $success = false;
    }
    
    if (isset($stats['category']) && $stats['category'] == $deltaCategoryCount) {
        echo "   ✓ category statistics accurate ({$stats['category']})\n";
    } else {
        $reported = $stats['category'] ?? 0;
        echo "   ✗ ERROR: category statistics mismatch (reported: {$reported}, actual: {$deltaCategoryCount})\n";
        $success = false;
    }
    echo "\n";
    
    // Final result
    if ($success) {
        echo "=== ALL TESTS PASSED ✓ ===\n\n";
        echo "Summary:\n";
        echo "✓ Only records with update=1 were exported\n";
        echo "✓ Update flags were reset to 0 after export\n";
        echo "✓ Statistics are accurate\n";
        echo "✓ No duplicates in delta database\n";
        $exitCode = 0;
    } else {
        echo "=== SOME TESTS FAILED ✗ ===\n";
        $exitCode = 1;
    }
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    $exitCode = 1;
} finally {
    // Cleanup
    if (isset($db)) {
        $db = null;
    }
    if (isset($deltaDb)) {
        $deltaDb = null;
    }
    if (isset($testDbPath) && file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
    if (isset($deltaDbPath) && file_exists($deltaDbPath)) {
        @unlink($deltaDbPath);
    }
}

exit($exitCode ?? 1);
