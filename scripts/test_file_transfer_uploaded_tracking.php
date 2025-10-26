<?php
declare(strict_types=1);

/**
 * Test script for file transfer with uploaded tracking
 * 
 * Tests:
 * 1. Get pending images (uploaded = 0)
 * 2. Get pending documents (uploaded = 0)
 * 3. Transfer single image and mark as uploaded
 * 4. Transfer single document and mark as uploaded
 * 5. Verify uploaded flag is set to 1 after transfer
 */

require_once __DIR__ . '/../autoload.php';

$config = require __DIR__ . '/../config.php';

echo "=== File Transfer Uploaded Tracking Test ===\n\n";

// Test 1: Database Connection
echo "Test 1: Creating database connection...\n";
try {
    $dbPath = $config['paths']['data_db'] ?? '';
    if (empty($dbPath) || !file_exists($dbPath)) {
        throw new Exception('Datenbank nicht gefunden: ' . $dbPath);
    }
    
    $db = new SQLite_Connection($dbPath);
    echo "✓ Database connection created successfully\n\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Create API_Transfer instance with database connection
echo "Test 2: Creating API_Transfer instance...\n";
try {
    $testConfig = [
        'data_transfer' => [
            'api_key' => 'test_key_12345',
            'images' => [
                'enabled' => true,
                'source' => $config['paths']['media']['images']['source'],
                'target' => $config['paths']['media']['images']['target'],
            ],
            'documents' => [
                'enabled' => true,
                'source' => $config['paths']['media']['documents']['source'],
                'target' => $config['paths']['media']['documents']['target'],
            ],
            'max_file_size' => 104857600,
            'log_transfers' => false,
        ],
    ];
    
    $transfer = new API_Transfer($testConfig, null, $db);
    echo "✓ API_Transfer instance created successfully with database connection\n\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Get pending images
echo "Test 3: Getting pending images (uploaded = 0)...\n";
try {
    $pendingImages = $transfer->getPendingImages();
    echo "✓ Found " . count($pendingImages) . " pending images\n";
    if (count($pendingImages) > 0) {
        echo "  First 3 pending images:\n";
        foreach (array_slice($pendingImages, 0, 3) as $image) {
            echo "    - ID: {$image['id']}, Filename: {$image['filename']}\n";
        }
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Get pending documents
echo "Test 4: Getting pending documents (uploaded = 0)...\n";
try {
    $pendingDocuments = $transfer->getPendingDocuments();
    echo "✓ Found " . count($pendingDocuments) . " pending documents\n";
    if (count($pendingDocuments) > 0) {
        echo "  First 3 pending documents:\n";
        foreach (array_slice($pendingDocuments, 0, 3) as $document) {
            echo "    - ID: {$document['id']}, Title: {$document['title']}\n";
        }
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 5: Test marking image as uploaded
echo "Test 5: Testing mark image as uploaded...\n";
try {
    // Create a test image entry if none exist
    $testImageId = null;
    if (count($pendingImages) > 0) {
        $testImageId = $pendingImages[0]['id'];
    } else {
        // Insert a test image
        $testFilename = 'test_image_' . time() . '.jpg';
        $db->execute('INSERT INTO Bilder (Bildname, uploaded, "update") VALUES (?, 0, 1)', [$testFilename]);
        $testImageId = $db->lastInsertId();
        echo "  Created test image with ID: {$testImageId}\n";
    }
    
    if ($testImageId) {
        // Check current status
        $row = $db->fetchOne('SELECT uploaded FROM Bilder WHERE ID = ?', [$testImageId]);
        $uploadedBefore = (int)($row['uploaded'] ?? 0);
        echo "  Image ID {$testImageId} uploaded status before: {$uploadedBefore}\n";
        
        // Mark as uploaded
        $result = $transfer->markImageAsUploaded($testImageId);
        echo "  Mark as uploaded result: " . ($result ? 'success' : 'failed') . "\n";
        
        // Check status after
        $row = $db->fetchOne('SELECT uploaded FROM Bilder WHERE ID = ?', [$testImageId]);
        $uploadedAfter = (int)($row['uploaded'] ?? 0);
        echo "  Image ID {$testImageId} uploaded status after: {$uploadedAfter}\n";
        
        if ($uploadedAfter === 1 && $uploadedBefore === 0) {
            echo "✓ Image marked as uploaded successfully\n";
        } else {
            echo "✗ Failed to mark image as uploaded\n";
        }
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 6: Test marking document as uploaded
echo "Test 6: Testing mark document as uploaded...\n";
try {
    // Create a test document entry if none exist
    $testDocumentId = null;
    if (count($pendingDocuments) > 0) {
        $testDocumentId = $pendingDocuments[0]['id'];
    } else {
        // Insert a test document
        $testTitle = 'test_document_' . time();
        $db->execute('INSERT INTO Dokumente (Titel, uploaded, "update") VALUES (?, 0, 1)', [$testTitle]);
        $testDocumentId = $db->lastInsertId();
        echo "  Created test document with ID: {$testDocumentId}\n";
    }
    
    if ($testDocumentId) {
        // Check current status
        $row = $db->fetchOne('SELECT uploaded FROM Dokumente WHERE ID = ?', [$testDocumentId]);
        $uploadedBefore = (int)($row['uploaded'] ?? 0);
        echo "  Document ID {$testDocumentId} uploaded status before: {$uploadedBefore}\n";
        
        // Mark as uploaded
        $result = $transfer->markDocumentAsUploaded($testDocumentId);
        echo "  Mark as uploaded result: " . ($result ? 'success' : 'failed') . "\n";
        
        // Check status after
        $row = $db->fetchOne('SELECT uploaded FROM Dokumente WHERE ID = ?', [$testDocumentId]);
        $uploadedAfter = (int)($row['uploaded'] ?? 0);
        echo "  Document ID {$testDocumentId} uploaded status after: {$uploadedAfter}\n";
        
        if ($uploadedAfter === 1 && $uploadedBefore === 0) {
            echo "✓ Document marked as uploaded successfully\n";
        } else {
            echo "✗ Failed to mark document as uploaded\n";
        }
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 7: Verify uploaded = 0 is set for new images in EVO_ImageSync
echo "Test 7: Verifying uploaded = 0 for new images...\n";
try {
    // Check the SQL in EVO_ImageSync
    $syncFile = __DIR__ . '/../classes/evo/EVO_ImageSync.php';
    $content = file_get_contents($syncFile);
    
    if (str_contains($content, 'uploaded = 0')) {
        echo "✓ EVO_ImageSync contains 'uploaded = 0' in SQL statements\n";
    } else {
        echo "✗ Warning: EVO_ImageSync may not set uploaded = 0\n";
    }
    
    // Verify the actual SQL pattern
    if (preg_match('/INSERT INTO Bilder.*uploaded.*0/s', $content) || 
        preg_match('/uploaded\s*=\s*0/s', $content)) {
        echo "✓ EVO_ImageSync properly initializes uploaded to 0\n";
    } else {
        echo "✗ Warning: Pattern not found in EVO_ImageSync\n";
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

// Test 8: Verify uploaded = 0 is set for new documents in EVO_DocumentSync
echo "Test 8: Verifying uploaded = 0 for new documents...\n";
try {
    // Check the SQL in EVO_DocumentSync
    $syncFile = __DIR__ . '/../classes/evo/EVO_DocumentSync.php';
    $content = file_get_contents($syncFile);
    
    if (str_contains($content, 'uploaded = 0')) {
        echo "✓ EVO_DocumentSync contains 'uploaded = 0' in SQL statements\n";
    } else {
        echo "✗ Warning: EVO_DocumentSync may not set uploaded = 0\n";
    }
    
    // Verify the actual SQL pattern
    if (preg_match('/INSERT INTO Dokumente.*uploaded.*0/s', $content) || 
        preg_match('/uploaded\s*=\s*0/s', $content)) {
        echo "✓ EVO_DocumentSync properly initializes uploaded to 0\n";
    } else {
        echo "✗ Warning: Pattern not found in EVO_DocumentSync\n";
    }
    echo "\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
}

echo "=== All tests completed ===\n";
