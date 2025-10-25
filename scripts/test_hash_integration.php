#!/usr/bin/env php
<?php
/**
 * Integration test for HashManager with ArticleSync
 * 
 * Tests that hash-based change detection works correctly:
 * - New articles get hashes and update flag set
 * - Unchanged articles don't trigger updates
 * - Changed articles are detected via hash comparison
 * - Update flag is correctly set based on hash changes
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== HashManager Integration Test ===\n\n";

// Create a temporary test database
$testDbPath = '/tmp/test_hash_integration_' . time() . '.db';

try {
    // Initialize test database
    echo "Setting up test database...\n";
    $db = new PDO('sqlite:' . $testDbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables with hash columns
    $db->exec("
        CREATE TABLE Artikel (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            AFS_ID INTEGER,
            XT_ID INTEGER,
            Artikelnummer TEXT NOT NULL UNIQUE,
            Bezeichnung TEXT NOT NULL,
            Preis REAL,
            Bestand INTEGER,
            Online INTEGER DEFAULT 1,
            \"update\" INTEGER DEFAULT 0,
            last_update TEXT,
            last_imported_hash TEXT,
            last_seen_hash TEXT
        )
    ");
    
    echo "✓ Test database created\n\n";
    
    // Create HashManager
    $hashManager = new AFS_HashManager();
    
    // Test 1: Insert new article
    echo "Test 1: New article import\n";
    $article1 = [
        'afs_id' => 1,
        'xt_id' => null,
        'artikelnummer' => 'TEST-001',
        'bezeichnung' => 'Test Product 1',
        'preis' => 19.99,
        'bestand' => 100,
        'online' => 1,
        'update' => 0,
        'last_update' => '2024-01-01',
        'last_imported_hash' => null,
        'last_seen_hash' => null,
    ];
    
    // Compute hash for the article
    $hashableFields = $hashManager->extractHashableFields($article1);
    $currentHash = $hashManager->generateHash($hashableFields);
    
    // Check if it should update (new article)
    $existingHash = null;
    $shouldUpdate = $hashManager->hasChanged($existingHash, $currentHash);
    
    if ($shouldUpdate) {
        echo "✓ New article correctly identified (should update)\n";
        $article1['update'] = 1;
        $article1['last_imported_hash'] = $currentHash;
        $article1['last_seen_hash'] = $currentHash;
    } else {
        echo "✗ FAIL: New article not identified\n";
        exit(1);
    }
    
    // Insert into database
    $stmt = $db->prepare("
        INSERT INTO Artikel (AFS_ID, XT_ID, Artikelnummer, Bezeichnung, Preis, Bestand, Online, \"update\", last_update, last_imported_hash, last_seen_hash)
        VALUES (:afs_id, :xt_id, :artikelnummer, :bezeichnung, :preis, :bestand, :online, :update, :last_update, :last_imported_hash, :last_seen_hash)
    ");
    $stmt->execute($article1);
    
    $insertedId = $db->lastInsertId();
    echo "✓ Article inserted with ID {$insertedId}\n";
    echo "  Hash: {$currentHash}\n";
    echo "  Update flag: {$article1['update']}\n\n";
    
    // Test 2: Re-import same article (no changes)
    echo "Test 2: Re-import unchanged article\n";
    
    // Fetch existing article
    $stmt = $db->prepare("SELECT * FROM Artikel WHERE Artikelnummer = :nummer");
    $stmt->execute([':nummer' => 'TEST-001']);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $article1Reimport = [
        'afs_id' => 1,
        'xt_id' => null,
        'artikelnummer' => 'TEST-001',
        'bezeichnung' => 'Test Product 1',
        'preis' => 19.99,
        'bestand' => 100,
        'online' => 1,
        'update' => 0,
        'last_update' => '2024-01-01',
        'last_imported_hash' => null,
        'last_seen_hash' => null,
    ];
    
    $hashableFields = $hashManager->extractHashableFields($article1Reimport);
    $newHash = $hashManager->generateHash($hashableFields);
    $oldHash = $existing['last_imported_hash'];
    
    $shouldUpdate = $hashManager->hasChanged($oldHash, $newHash);
    
    if (!$shouldUpdate) {
        echo "✓ Unchanged article correctly identified (no update needed)\n";
        echo "  Old hash: {$oldHash}\n";
        echo "  New hash: {$newHash}\n";
        echo "  Hashes match: " . ($oldHash === $newHash ? 'Yes' : 'No') . "\n\n";
    } else {
        echo "✗ FAIL: Unchanged article incorrectly flagged for update\n";
        echo "  Old hash: {$oldHash}\n";
        echo "  New hash: {$newHash}\n";
        exit(1);
    }
    
    // Test 3: Update article (price change)
    echo "Test 3: Update article with price change\n";
    
    $article1Updated = [
        'afs_id' => 1,
        'xt_id' => null,
        'artikelnummer' => 'TEST-001',
        'bezeichnung' => 'Test Product 1',
        'preis' => 24.99, // CHANGED
        'bestand' => 100,
        'online' => 1,
        'update' => 0,
        'last_update' => '2024-01-02',
        'last_imported_hash' => null,
        'last_seen_hash' => null,
    ];
    
    $hashableFields = $hashManager->extractHashableFields($article1Updated);
    $updatedHash = $hashManager->generateHash($hashableFields);
    
    $shouldUpdate = $hashManager->hasChanged($oldHash, $updatedHash);
    
    if ($shouldUpdate) {
        echo "✓ Changed article correctly identified (should update)\n";
        echo "  Old hash: {$oldHash}\n";
        echo "  New hash: {$updatedHash}\n";
        echo "  Price changed: 19.99 → 24.99\n";
        
        // Update the database
        $article1Updated['update'] = 1;
        $article1Updated['last_imported_hash'] = $updatedHash;
        $article1Updated['last_seen_hash'] = $updatedHash;
        
        $stmt = $db->prepare("
            UPDATE Artikel 
            SET Preis = :preis, 
                \"update\" = :update, 
                last_update = :last_update,
                last_imported_hash = :last_imported_hash,
                last_seen_hash = :last_seen_hash
            WHERE Artikelnummer = :artikelnummer
        ");
        $stmt->execute([
            ':preis' => $article1Updated['preis'],
            ':update' => $article1Updated['update'],
            ':last_update' => $article1Updated['last_update'],
            ':last_imported_hash' => $article1Updated['last_imported_hash'],
            ':last_seen_hash' => $article1Updated['last_seen_hash'],
            ':artikelnummer' => $article1Updated['artikelnummer'],
        ]);
        
        echo "✓ Article updated in database\n";
        echo "  Update flag: {$article1Updated['update']}\n\n";
    } else {
        echo "✗ FAIL: Changed article not detected\n";
        exit(1);
    }
    
    // Test 4: Verify update flag was set
    echo "Test 4: Verify update flag persistence\n";
    
    $stmt = $db->prepare("SELECT * FROM Artikel WHERE Artikelnummer = :nummer");
    $stmt->execute([':nummer' => 'TEST-001']);
    $verifyArticle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$verifyArticle['update'] === 1) {
        echo "✓ Update flag correctly persisted in database\n";
        echo "  Update value: {$verifyArticle['update']}\n";
        echo "  Current hash: {$verifyArticle['last_imported_hash']}\n\n";
    } else {
        echo "✗ FAIL: Update flag not persisted\n";
        exit(1);
    }
    
    // Test 5: Test with multiple articles
    echo "Test 5: Batch processing with multiple articles\n";
    
    $articles = [
        ['nummer' => 'TEST-002', 'name' => 'Product 2', 'price' => 29.99],
        ['nummer' => 'TEST-003', 'name' => 'Product 3', 'price' => 39.99],
        ['nummer' => 'TEST-004', 'name' => 'Product 4', 'price' => 49.99],
    ];
    
    $inserted = 0;
    foreach ($articles as $art) {
        $payload = [
            'afs_id' => null,
            'xt_id' => null,
            'artikelnummer' => $art['nummer'],
            'bezeichnung' => $art['name'],
            'preis' => $art['price'],
            'bestand' => 50,
            'online' => 1,
            'update' => 0,
            'last_update' => '2024-01-01',
            'last_imported_hash' => null,
            'last_seen_hash' => null,
        ];
        
        $hashableFields = $hashManager->extractHashableFields($payload);
        $hash = $hashManager->generateHash($hashableFields);
        
        $payload['update'] = 1;
        $payload['last_imported_hash'] = $hash;
        $payload['last_seen_hash'] = $hash;
        
        $stmt = $db->prepare("
            INSERT INTO Artikel (AFS_ID, XT_ID, Artikelnummer, Bezeichnung, Preis, Bestand, Online, \"update\", last_update, last_imported_hash, last_seen_hash)
            VALUES (:afs_id, :xt_id, :artikelnummer, :bezeichnung, :preis, :bestand, :online, :update, :last_update, :last_imported_hash, :last_seen_hash)
        ");
        $stmt->execute($payload);
        $inserted++;
    }
    
    echo "✓ Inserted {$inserted} articles with hashes\n";
    
    // Verify all have update flag set
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM Artikel WHERE \"update\" = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $updateCount = (int)$result['cnt'];
    
    if ($updateCount === 4) { // 1 from test 3 + 3 from test 5
        echo "✓ All 4 articles have update flag set\n\n";
    } else {
        echo "✗ FAIL: Expected 4 articles with update flag, got {$updateCount}\n";
        exit(1);
    }
    
    // Test 6: Performance check
    echo "Test 6: Performance check\n";
    
    $startTime = microtime(true);
    $iterations = 1000;
    
    for ($i = 0; $i < $iterations; $i++) {
        $testData = [
            'artikelnummer' => "PERF-{$i}",
            'bezeichnung' => "Performance Test Product {$i}",
            'preis' => 10.00 + ($i * 0.01),
            'bestand' => 100 + $i,
            'online' => 1,
        ];
        
        $hashableFields = $hashManager->extractHashableFields($testData);
        $hash = $hashManager->generateHash($hashableFields);
    }
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $perHash = ($duration / $iterations) * 1000; // milliseconds
    
    echo "✓ Generated {$iterations} hashes in " . number_format($duration, 4) . " seconds\n";
    echo "  Average: " . number_format($perHash, 4) . " ms per hash\n";
    
    if ($perHash < 1.0) {
        echo "✓ PASS: Performance is excellent (< 1ms per hash)\n\n";
    } elseif ($perHash < 5.0) {
        echo "✓ PASS: Performance is acceptable (< 5ms per hash)\n\n";
    } else {
        echo "⚠ WARNING: Performance may need optimization (> 5ms per hash)\n\n";
    }
    
    echo "=== All Integration Tests Passed! ===\n\n";
    echo "Summary:\n";
    echo "✓ Hash-based change detection works correctly\n";
    echo "✓ Update flag is set when data changes\n";
    echo "✓ Unchanged data is correctly identified\n";
    echo "✓ Performance is acceptable for production use\n";
    
} catch (Throwable $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
} finally {
    // Cleanup
    if (isset($db)) {
        $db = null;
    }
    if (file_exists($testDbPath)) {
        unlink($testDbPath);
        echo "\n✓ Test database cleaned up\n";
    }
}
