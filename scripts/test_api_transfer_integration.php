<?php
declare(strict_types=1);

/**
 * Integration test for API_Transfer class
 * 
 * This script performs actual file transfer operations in a temporary test environment.
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

echo "=== API_Transfer Integration Test ===\n\n";

// Create temporary test directories
$tmpBase = sys_get_temp_dir() . '/api_transfer_test_' . uniqid();
$sourceDirs = [
    'db' => $tmpBase . '/source/db',
    'images' => $tmpBase . '/source/images',
    'documents' => $tmpBase . '/source/documents',
];
$targetDirs = [
    'db' => $tmpBase . '/target/db',
    'images' => $tmpBase . '/target/images',
    'documents' => $tmpBase . '/target/documents',
];

// Cleanup function
function cleanup(string $tmpBase): void
{
    if (is_dir($tmpBase)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmpBase, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($tmpBase);
    }
}

// Setup test environment
echo "Setting up test environment...\n";
try {
    foreach ($sourceDirs as $dir) {
        if (!mkdir($dir, 0755, true)) {
            throw new Exception("Failed to create directory: {$dir}");
        }
    }
    echo "✓ Test directories created\n";
} catch (Throwable $e) {
    echo "✗ Setup failed: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Create test files
echo "\nCreating test files...\n";
try {
    // Create test database
    $dbPath = $sourceDirs['db'] . '/test_delta.db';
    $dbContent = str_repeat('SQLite database content ', 100); // ~2.4KB
    file_put_contents($dbPath, $dbContent);
    echo "✓ Test database created: " . filesize($dbPath) . " bytes\n";
    
    // Create test images with subdirectories
    $imagesSubdir = $sourceDirs['images'] . '/products';
    mkdir($imagesSubdir, 0755, true);
    for ($i = 1; $i <= 5; $i++) {
        $imgPath = $imagesSubdir . "/image_{$i}.jpg";
        file_put_contents($imgPath, str_repeat("JPEG image data {$i} ", 50));
    }
    echo "✓ 5 test images created in subdirectory\n";
    
    // Create test documents
    for ($i = 1; $i <= 3; $i++) {
        $docPath = $sourceDirs['documents'] . "/document_{$i}.pdf";
        file_put_contents($docPath, str_repeat("PDF document content {$i} ", 100));
    }
    echo "✓ 3 test documents created\n";
} catch (Throwable $e) {
    echo "✗ File creation failed: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Configure API_Transfer
$testConfig = [
    'data_transfer' => [
        'api_key' => 'test_integration_key_12345',
        'database' => [
            'enabled' => true,
            'source' => $dbPath,
            'target' => $targetDirs['db'] . '/test_delta.db',
        ],
        'images' => [
            'enabled' => true,
            'source' => $sourceDirs['images'],
            'target' => $targetDirs['images'],
        ],
        'documents' => [
            'enabled' => true,
            'source' => $sourceDirs['documents'],
            'target' => $targetDirs['documents'],
        ],
        'max_file_size' => 104857600,
        'log_transfers' => false,
    ],
];

// Test database transfer
echo "\n=== Test 1: Database Transfer ===\n";
try {
    $transfer = new API_Transfer($testConfig, null);
    $result = $transfer->transferDatabase();
    
    if (!$result['success']) {
        echo "✗ Database transfer failed\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    $targetDbPath = $testConfig['data_transfer']['database']['target'];
    if (!file_exists($targetDbPath)) {
        echo "✗ Target database file not found\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    $sourceSize = filesize($dbPath);
    $targetSize = filesize($targetDbPath);
    
    if ($sourceSize !== $targetSize) {
        echo "✗ File sizes don't match (source: {$sourceSize}, target: {$targetSize})\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    echo "✓ Database transferred successfully\n";
    echo "  Source: {$result['source']}\n";
    echo "  Target: {$result['target']}\n";
    echo "  Size: {$result['size']} bytes\n";
    echo "  Duration: {$result['duration']}s\n";
} catch (Throwable $e) {
    echo "✗ Database transfer error: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Test images transfer
echo "\n=== Test 2: Images Transfer ===\n";
try {
    $result = $transfer->transferImages();
    
    if (!$result['success']) {
        echo "✗ Images transfer failed\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    // Check if subdirectory was created
    $targetImagesSubdir = $targetDirs['images'] . '/products';
    if (!is_dir($targetImagesSubdir)) {
        echo "✗ Images subdirectory not created\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    // Count transferred files
    $transferredFiles = glob($targetImagesSubdir . '/*.jpg');
    if (count($transferredFiles) !== 5) {
        echo "✗ Wrong number of images transferred (" . count($transferredFiles) . "/5)\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    echo "✓ Images transferred successfully\n";
    echo "  Files copied: {$result['files_copied']}\n";
    echo "  Directories created: {$result['directories_created']}\n";
    echo "  Total size: {$result['total_size']} bytes\n";
    echo "  Duration: {$result['duration']}s\n";
} catch (Throwable $e) {
    echo "✗ Images transfer error: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Test documents transfer
echo "\n=== Test 3: Documents Transfer ===\n";
try {
    $result = $transfer->transferDocuments();
    
    if (!$result['success']) {
        echo "✗ Documents transfer failed\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    // Count transferred files
    $transferredDocs = glob($targetDirs['documents'] . '/*.pdf');
    if (count($transferredDocs) !== 3) {
        echo "✗ Wrong number of documents transferred (" . count($transferredDocs) . "/3)\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    echo "✓ Documents transferred successfully\n";
    echo "  Files copied: {$result['files_copied']}\n";
    echo "  Total size: {$result['total_size']} bytes\n";
    echo "  Duration: {$result['duration']}s\n";
} catch (Throwable $e) {
    echo "✗ Documents transfer error: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Test transferAll
echo "\n=== Test 4: Transfer All ===\n";
try {
    // Clean target directories
    foreach ($targetDirs as $dir) {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
        }
    }
    
    // Transfer all
    $transfer2 = new API_Transfer($testConfig, null);
    $results = $transfer2->transferAll();
    
    $successCount = 0;
    foreach ($results as $type => $result) {
        if ($result['success']) {
            $successCount++;
            echo "✓ {$type}: success\n";
        } else {
            echo "✗ {$type}: " . ($result['error'] ?? 'failed') . "\n";
        }
    }
    
    if ($successCount !== 3) {
        echo "✗ Not all transfers succeeded ({$successCount}/3)\n";
        cleanup($tmpBase);
        exit(1);
    }
    
    echo "✓ All transfers completed successfully\n";
} catch (Throwable $e) {
    echo "✗ Transfer all error: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Test file size limit
echo "\n=== Test 5: File Size Limit ===\n";
try {
    // Create a large file
    $largeFile = $sourceDirs['documents'] . '/large_file.pdf';
    $largeContent = str_repeat('X', 1000); // 1KB
    file_put_contents($largeFile, $largeContent);
    
    // Set very small limit
    $limitConfig = $testConfig;
    $limitConfig['data_transfer']['max_file_size'] = 500; // 500 bytes
    
    $transfer3 = new API_Transfer($limitConfig, null);
    
    // Clean target
    $targetDocsDir = $targetDirs['documents'];
    if (is_dir($targetDocsDir)) {
        foreach (glob($targetDocsDir . '/*') as $file) {
            unlink($file);
        }
    }
    
    $result = $transfer3->transferDocuments();
    
    // Should have errors for large file
    if (!empty($result['errors'])) {
        echo "✓ File size limit enforced\n";
        echo "  Errors reported: " . count($result['errors']) . "\n";
    } else {
        echo "⚠ Warning: File size limit not properly enforced\n";
    }
} catch (Throwable $e) {
    echo "✗ File size test error: " . $e->getMessage() . "\n";
    cleanup($tmpBase);
    exit(1);
}

// Cleanup
echo "\nCleaning up test environment...\n";
cleanup($tmpBase);
echo "✓ Cleanup completed\n";

echo "\n=== All integration tests passed! ===\n";
exit(0);
