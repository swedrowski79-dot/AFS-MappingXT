#!/usr/bin/env php
<?php
/**
 * Test Suite for AFS_Cache - General-Purpose Caching Layer
 * 
 * Tests all functionality of the AFS_Cache class including:
 * - Basic get/set operations
 * - TTL-based expiration
 * - Pattern-based invalidation
 * - Cache statistics
 * - remember() helper
 * - Memory management
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

// ANSI color codes for terminal output
define('GREEN', "\033[0;32m");
define('RED', "\033[0;31m");
define('YELLOW', "\033[0;33m");
define('RESET', "\033[0m");

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    
    // Clear cache before each test
    AFS_Cache::clear();
    
    try {
        $fn();
        echo GREEN . "✓" . RESET . " {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo RED . "✗" . RESET . " {$name}\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  at {$e->getFile()}:{$e->getLine()}\n";
        $failed++;
    }
}

function assertEquals($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: "Expected " . var_export($expected, true) . " but got " . var_export($actual, true);
        throw new \Exception($msg);
    }
}

function assertNull($actual, string $message = ''): void
{
    if ($actual !== null) {
        $msg = $message ?: "Expected null but got " . var_export($actual, true);
        throw new \Exception($msg);
    }
}

function assertTrue($actual, string $message = ''): void
{
    if ($actual !== true) {
        $msg = $message ?: "Expected true but got " . var_export($actual, true);
        throw new \Exception($msg);
    }
}

function assertFalse($actual, string $message = ''): void
{
    if ($actual !== false) {
        $msg = $message ?: "Expected false but got " . var_export($actual, true);
        throw new \Exception($msg);
    }
}

function assertGreaterThan($threshold, $actual, string $message = ''): void
{
    if ($actual <= $threshold) {
        $msg = $message ?: "Expected value > {$threshold} but got {$actual}";
        throw new \Exception($msg);
    }
}

echo "=== AFS_Cache Test Suite ===\n\n";

// Test 1: Basic set/get operations
test('Cache can store and retrieve data', function() {
    AFS_Cache::set('test_key', 'test_value');
    $value = AFS_Cache::get('test_key');
    assertEquals('test_value', $value);
});

// Test 2: Different data types
test('Cache handles different data types', function() {
    AFS_Cache::set('string', 'hello');
    AFS_Cache::set('int', 42);
    AFS_Cache::set('float', 3.14);
    AFS_Cache::set('bool', true);
    AFS_Cache::set('array', ['a' => 1, 'b' => 2]);
    AFS_Cache::set('null', null);
    
    assertEquals('hello', AFS_Cache::get('string'));
    assertEquals(42, AFS_Cache::get('int'));
    assertEquals(3.14, AFS_Cache::get('float'));
    assertEquals(true, AFS_Cache::get('bool'));
    assertEquals(['a' => 1, 'b' => 2], AFS_Cache::get('array'));
    assertEquals(null, AFS_Cache::get('null'));
});

// Test 3: Non-existent key returns null
test('Non-existent key returns null', function() {
    $value = AFS_Cache::get('nonexistent');
    assertNull($value);
});

// Test 4: has() method
test('has() correctly checks cache presence', function() {
    AFS_Cache::set('exists', 'value');
    assertTrue(AFS_Cache::has('exists'));
    assertFalse(AFS_Cache::has('not_exists'));
});

// Test 5: remove() method
test('remove() deletes specific cache entry', function() {
    AFS_Cache::set('key1', 'value1');
    AFS_Cache::set('key2', 'value2');
    
    assertTrue(AFS_Cache::remove('key1'));
    assertFalse(AFS_Cache::has('key1'));
    assertTrue(AFS_Cache::has('key2'));
    
    assertFalse(AFS_Cache::remove('key1')); // Already removed
});

// Test 6: clear() method
test('clear() removes all cache entries', function() {
    AFS_Cache::set('key1', 'value1');
    AFS_Cache::set('key2', 'value2');
    AFS_Cache::set('key3', 'value3');
    
    AFS_Cache::clear();
    
    assertFalse(AFS_Cache::has('key1'));
    assertFalse(AFS_Cache::has('key2'));
    assertFalse(AFS_Cache::has('key3'));
});

// Test 7: TTL expiration
test('TTL-based expiration works correctly', function() {
    // Set with 1 second TTL
    AFS_Cache::set('expires', 'value', 1);
    assertTrue(AFS_Cache::has('expires'));
    
    // Wait for expiration
    sleep(2);
    
    assertFalse(AFS_Cache::has('expires'));
    assertNull(AFS_Cache::get('expires'));
});

// Test 8: No expiration (TTL = 0)
test('TTL of 0 means no expiration', function() {
    AFS_Cache::set('permanent', 'value', 0);
    sleep(1);
    assertTrue(AFS_Cache::has('permanent'));
});

// Test 9: Pattern-based removal
test('removeByPattern() removes matching keys', function() {
    AFS_Cache::set('articles:1', 'data1');
    AFS_Cache::set('articles:2', 'data2');
    AFS_Cache::set('articles:3', 'data3');
    AFS_Cache::set('categories:1', 'cat1');
    
    $removed = AFS_Cache::removeByPattern('articles:*');
    assertEquals(3, $removed);
    
    assertFalse(AFS_Cache::has('articles:1'));
    assertFalse(AFS_Cache::has('articles:2'));
    assertFalse(AFS_Cache::has('articles:3'));
    assertTrue(AFS_Cache::has('categories:1'));
});

// Test 10: Cache statistics
test('Cache statistics are tracked correctly', function() {
    AFS_Cache::set('key1', 'value1');
    AFS_Cache::set('key2', 'value2');
    AFS_Cache::get('key1'); // hit
    AFS_Cache::get('key1'); // hit
    AFS_Cache::get('nonexistent'); // miss
    
    $stats = AFS_Cache::getStats();
    
    assertEquals(2, $stats['hits']);
    assertEquals(1, $stats['misses']);
    assertEquals(2, $stats['sets']);
    assertEquals(2, $stats['size']);
    assertEquals(66.67, $stats['hit_rate']); // 2 hits / 3 total = 66.67%
});

// Test 11: remember() helper
test('remember() caches callback result', function() {
    $callCount = 0;
    
    $callback = function() use (&$callCount) {
        $callCount++;
        return 'computed_value';
    };
    
    // First call - should execute callback
    $value1 = AFS_Cache::remember('computed', $callback);
    assertEquals('computed_value', $value1);
    assertEquals(1, $callCount);
    
    // Second call - should use cache
    $value2 = AFS_Cache::remember('computed', $callback);
    assertEquals('computed_value', $value2);
    assertEquals(1, $callCount); // Callback not called again
});

// Test 12: remember() with TTL
test('remember() respects TTL', function() {
    $callCount = 0;
    
    $callback = function() use (&$callCount) {
        $callCount++;
        return 'value_' . $callCount;
    };
    
    // Set with 1 second TTL
    $value1 = AFS_Cache::remember('timed', $callback, 1);
    assertEquals('value_1', $value1);
    
    // Wait for expiration
    sleep(2);
    
    // Should execute callback again
    $value2 = AFS_Cache::remember('timed', $callback, 1);
    assertEquals('value_2', $value2);
    assertEquals(2, $callCount);
});

// Test 13: cleanupExpired() method
test('cleanupExpired() removes expired entries', function() {
    AFS_Cache::set('expires1', 'value1', 1);
    AFS_Cache::set('expires2', 'value2', 1);
    AFS_Cache::set('permanent', 'value3', 0);
    
    sleep(2);
    
    $removed = AFS_Cache::cleanupExpired();
    assertEquals(2, $removed);
    
    assertFalse(AFS_Cache::has('expires1'));
    assertFalse(AFS_Cache::has('expires2'));
    assertTrue(AFS_Cache::has('permanent'));
});

// Test 14: keys() method
test('keys() returns all cache keys', function() {
    AFS_Cache::set('key1', 'value1');
    AFS_Cache::set('key2', 'value2');
    AFS_Cache::set('key3', 'value3');
    
    $keys = AFS_Cache::keys();
    assertEquals(3, count($keys));
    assertTrue(in_array('key1', $keys));
    assertTrue(in_array('key2', $keys));
    assertTrue(in_array('key3', $keys));
});

// Test 15: size() method
test('size() returns cache entry count', function() {
    assertEquals(0, AFS_Cache::size());
    
    AFS_Cache::set('key1', 'value1');
    assertEquals(1, AFS_Cache::size());
    
    AFS_Cache::set('key2', 'value2');
    assertEquals(2, AFS_Cache::size());
    
    AFS_Cache::remove('key1');
    assertEquals(1, AFS_Cache::size());
    
    AFS_Cache::clear();
    assertEquals(0, AFS_Cache::size());
});

// Test 16: Memory usage estimation
test('getStats() includes memory usage estimation', function() {
    AFS_Cache::set('small', 'x');
    AFS_Cache::set('large', str_repeat('x', 10000));
    
    $stats = AFS_Cache::getStats();
    assertGreaterThan(0, $stats['memory_bytes']);
    assertGreaterThan(10000, $stats['memory_bytes']); // Should be > 10KB
});

// Test 17: Overwriting existing keys
test('Setting existing key overwrites previous value', function() {
    AFS_Cache::set('key', 'value1');
    assertEquals('value1', AFS_Cache::get('key'));
    
    AFS_Cache::set('key', 'value2');
    assertEquals('value2', AFS_Cache::get('key'));
});

// Test 18: Complex data structures
test('Cache handles complex nested data structures', function() {
    $complex = [
        'articles' => [
            ['id' => 1, 'name' => 'Article 1', 'price' => 10.99],
            ['id' => 2, 'name' => 'Article 2', 'price' => 20.99],
        ],
        'meta' => [
            'count' => 2,
            'timestamp' => time(),
        ],
    ];
    
    AFS_Cache::set('complex', $complex);
    $retrieved = AFS_Cache::get('complex');
    assertEquals($complex, $retrieved);
});

// Test 19: Empty string key (edge case)
test('Cache handles empty string as key', function() {
    AFS_Cache::set('', 'empty_key_value');
    assertEquals('empty_key_value', AFS_Cache::get(''));
});

// Test 20: Pattern removal with no matches
test('removeByPattern() returns 0 when no matches', function() {
    AFS_Cache::set('articles:1', 'data1');
    $removed = AFS_Cache::removeByPattern('categories:*');
    assertEquals(0, $removed);
});

// Test 21: Multiple operations scenario
test('Real-world scenario: database query caching', function() {
    // Simulate expensive database query
    $queryArticles = function() {
        static $callCount = 0;
        $callCount++;
        
        // Simulate query execution time
        usleep(100); // 0.1ms
        
        return [
            ['id' => 1, 'name' => 'Article 1'],
            ['id' => 2, 'name' => 'Article 2'],
        ];
    };
    
    // First request - cache miss
    $start = microtime(true);
    $articles1 = AFS_Cache::remember('db:articles:all', $queryArticles, 300);
    $time1 = (microtime(true) - $start) * 1000;
    
    // Second request - cache hit
    $start = microtime(true);
    $articles2 = AFS_Cache::remember('db:articles:all', $queryArticles, 300);
    $time2 = (microtime(true) - $start) * 1000;
    
    assertEquals($articles1, $articles2);
    
    // Cache hit should be significantly faster
    assertTrue($time2 < $time1, "Cache hit should be faster than miss");
    
    $stats = AFS_Cache::getStats();
    assertEquals(1, $stats['hits']);
    assertEquals(1, $stats['misses']);
});

echo "\n=== Test Results ===\n";
echo "Passed: " . GREEN . $passed . RESET . "\n";
echo "Failed: " . ($failed > 0 ? RED : GREEN) . $failed . RESET . "\n";

if ($failed === 0) {
    echo "\n" . GREEN . "✅ All tests passed!" . RESET . "\n";
    exit(0);
} else {
    echo "\n" . RED . "❌ Some tests failed!" . RESET . "\n";
    exit(1);
}
