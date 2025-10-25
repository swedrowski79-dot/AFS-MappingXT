#!/usr/bin/env php
<?php
/**
 * Test script for AFS_ConfigCache
 * 
 * Tests the caching functionality for YAML configuration files
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_ConfigCache Test Suite ===\n\n";

$passed = 0;
$failed = 0;

function test(string $name, callable $testFunc): void
{
    global $passed, $failed;
    
    try {
        // Clear cache before each test
        AFS_ConfigCache::clear();
        
        $testFunc();
        echo "✓ {$name}\n";
        $passed++;
    } catch (Exception $e) {
        echo "✗ {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

// Test 1: Basic cache operations
test('Cache can store and retrieve data', function() {
    $testFile = __DIR__ . '/../mappings/source_afs.yml';
    
    if (!file_exists($testFile)) {
        throw new Exception('Test file not found');
    }
    
    // First call should be a miss
    $cached = AFS_ConfigCache::get($testFile);
    if ($cached !== null) {
        throw new Exception('Expected cache miss, got hit');
    }
    
    // Store some data
    $testData = ['test' => 'data', 'foo' => 'bar'];
    AFS_ConfigCache::set($testFile, $testData);
    
    // Second call should be a hit
    $cached = AFS_ConfigCache::get($testFile);
    if ($cached === null) {
        throw new Exception('Expected cache hit, got miss');
    }
    
    if ($cached !== $testData) {
        throw new Exception('Cached data does not match');
    }
});

// Test 2: Cache statistics
test('Cache statistics are tracked correctly', function() {
    $testFile = __DIR__ . '/../mappings/source_afs.yml';
    
    AFS_ConfigCache::clear();
    $stats = AFS_ConfigCache::getStats();
    
    if ($stats['hits'] !== 0 || $stats['misses'] !== 0) {
        throw new Exception('Stats should be zero after clear');
    }
    
    // First get should be a miss
    AFS_ConfigCache::get($testFile);
    $stats = AFS_ConfigCache::getStats();
    if ($stats['misses'] !== 1) {
        throw new Exception('Expected 1 miss');
    }
    
    // Store and retrieve
    AFS_ConfigCache::set($testFile, ['test' => 'data']);
    AFS_ConfigCache::get($testFile);
    
    $stats = AFS_ConfigCache::getStats();
    if ($stats['hits'] !== 1) {
        throw new Exception("Expected 1 hit, got {$stats['hits']}");
    }
    if ($stats['misses'] !== 1) {
        throw new Exception("Expected 1 miss, got {$stats['misses']}");
    }
    
    $expectedHitRate = 50.0;
    if ($stats['hit_rate'] !== $expectedHitRate) {
        throw new Exception("Expected hit rate {$expectedHitRate}%, got {$stats['hit_rate']}%");
    }
});

// Test 3: Cache invalidation on file change
test('Cache is invalidated when file is modified', function() {
    // Create a temporary test file
    $tempFile = sys_get_temp_dir() . '/test_config_' . uniqid() . '.yml';
    file_put_contents($tempFile, 'test: original');
    
    try {
        // Cache the file
        AFS_ConfigCache::set($tempFile, ['test' => 'original']);
        
        // Should get cached version
        $cached = AFS_ConfigCache::get($tempFile);
        if ($cached === null || $cached['test'] !== 'original') {
            throw new Exception('Failed to cache original data');
        }
        
        // Sleep to ensure mtime changes (some filesystems have 1-second resolution)
        sleep(1);
        
        // Modify the file
        file_put_contents($tempFile, 'test: modified');
        
        // Cache should be invalid now
        $cached = AFS_ConfigCache::get($tempFile);
        if ($cached !== null) {
            throw new Exception('Cache should be invalid after file modification');
        }
    } finally {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
});

// Test 4: Clear cache
test('Clear removes all cached entries', function() {
    $testFile1 = __DIR__ . '/../mappings/source_afs.yml';
    $testFile2 = __DIR__ . '/../mappings/target_sqlite.yml';
    
    AFS_ConfigCache::set($testFile1, ['data1' => 'test1']);
    AFS_ConfigCache::set($testFile2, ['data2' => 'test2']);
    
    $stats = AFS_ConfigCache::getStats();
    if ($stats['size'] !== 2) {
        throw new Exception('Expected cache size 2');
    }
    
    AFS_ConfigCache::clear();
    
    $stats = AFS_ConfigCache::getStats();
    if ($stats['size'] !== 0) {
        throw new Exception('Cache should be empty after clear');
    }
    
    if ($stats['hits'] !== 0 || $stats['misses'] !== 0) {
        throw new Exception('Stats should be reset after clear');
    }
});

// Test 5: Remove specific entry
test('Remove deletes specific cache entry', function() {
    $testFile1 = __DIR__ . '/../mappings/source_afs.yml';
    $testFile2 = __DIR__ . '/../mappings/target_sqlite.yml';
    
    AFS_ConfigCache::set($testFile1, ['data1' => 'test1']);
    AFS_ConfigCache::set($testFile2, ['data2' => 'test2']);
    
    AFS_ConfigCache::remove($testFile1);
    
    $stats = AFS_ConfigCache::getStats();
    if ($stats['size'] !== 1) {
        throw new Exception('Expected cache size 1 after remove');
    }
    
    if (AFS_ConfigCache::get($testFile1) !== null) {
        throw new Exception('Removed entry should not be in cache');
    }
    
    if (AFS_ConfigCache::get($testFile2) === null) {
        throw new Exception('Other entry should still be in cache');
    }
});

// Test 6: Has method
test('Has method correctly checks cache presence', function() {
    $testFile = __DIR__ . '/../mappings/source_afs.yml';
    
    if (AFS_ConfigCache::has($testFile)) {
        throw new Exception('Should return false for non-cached file');
    }
    
    AFS_ConfigCache::set($testFile, ['test' => 'data']);
    
    if (!AFS_ConfigCache::has($testFile)) {
        throw new Exception('Should return true for cached file');
    }
});

// Test 7: Integration test with AFS_MappingConfig
test('AFS_MappingConfig uses cache', function() {
    $configPath = __DIR__ . '/../mappings/source_afs.yml';
    
    AFS_ConfigCache::clear();
    
    // First instance - should miss cache
    $config1 = new AFS_MappingConfig($configPath);
    $stats1 = AFS_ConfigCache::getStats();
    
    if ($stats1['misses'] < 1) {
        throw new Exception('Expected at least 1 cache miss for first load');
    }
    
    // Second instance - should hit cache
    $config2 = new AFS_MappingConfig($configPath);
    $stats2 = AFS_ConfigCache::getStats();
    
    if ($stats2['hits'] < 1) {
        throw new Exception('Expected at least 1 cache hit for second load');
    }
    
    // Verify both configs work the same
    $entities1 = $config1->getEntities();
    $entities2 = $config2->getEntities();
    
    if ($entities1 !== $entities2) {
        throw new Exception('Cached config should produce same results');
    }
});

// Test 8: Integration test with AFS_TargetMappingConfig
test('AFS_TargetMappingConfig uses cache', function() {
    $configPath = __DIR__ . '/../mappings/target_sqlite.yml';
    
    AFS_ConfigCache::clear();
    
    // First instance - should miss cache
    $config1 = new AFS_TargetMappingConfig($configPath);
    $stats1 = AFS_ConfigCache::getStats();
    
    if ($stats1['misses'] < 1) {
        throw new Exception('Expected at least 1 cache miss for first load');
    }
    
    // Second instance - should hit cache
    $config2 = new AFS_TargetMappingConfig($configPath);
    $stats2 = AFS_ConfigCache::getStats();
    
    if ($stats2['hits'] < 1) {
        throw new Exception('Expected at least 1 cache hit for second load');
    }
    
    // Verify both configs work the same
    $version1 = $config1->getVersion();
    $version2 = $config2->getVersion();
    
    if ($version1 !== $version2) {
        throw new Exception('Cached config should produce same version');
    }
});

// Test 9: Performance test
test('Cache significantly improves performance', function() {
    $configPath = __DIR__ . '/../mappings/source_afs.yml';
    
    AFS_ConfigCache::clear();
    
    // Measure uncached load time
    $start = microtime(true);
    $config1 = new AFS_MappingConfig($configPath);
    $uncachedTime = microtime(true) - $start;
    
    // Measure cached load time (multiple iterations to get average)
    $iterations = 10;
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $config2 = new AFS_MappingConfig($configPath);
    }
    $cachedTime = (microtime(true) - $start) / $iterations;
    
    // Cached should be at least 10x faster
    $speedup = $uncachedTime / $cachedTime;
    
    if ($speedup < 10) {
        throw new Exception("Expected at least 10x speedup, got {$speedup}x");
    }
    
    echo "  → Speedup: " . round($speedup, 1) . "x faster (uncached: " . round($uncachedTime * 1000, 2) . "ms, cached: " . round($cachedTime * 1000, 2) . "ms)\n";
});

// Test 10: Nonexistent file handling
test('Cache handles nonexistent files gracefully', function() {
    $nonexistentFile = '/nonexistent/path/config.yml';
    
    $cached = AFS_ConfigCache::get($nonexistentFile);
    if ($cached !== null) {
        throw new Exception('Should return null for nonexistent file');
    }
    
    // Setting should not throw an error, just silently fail
    AFS_ConfigCache::set($nonexistentFile, ['test' => 'data']);
    
    $stats = AFS_ConfigCache::getStats();
    if ($stats['size'] !== 0) {
        throw new Exception('Should not cache nonexistent file');
    }
});

// Print results
echo "\n=== Test Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed > 0) {
    echo "\n❌ Some tests failed\n";
    exit(1);
} else {
    echo "\n✅ All tests passed!\n";
    exit(0);
}
