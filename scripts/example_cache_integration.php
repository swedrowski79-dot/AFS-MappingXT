#!/usr/bin/env php
<?php
/**
 * Practical Integration Example for AFS_Cache
 * 
 * This script demonstrates how to integrate the new AFS_Cache
 * into existing code for improved performance.
 * 
 * Run: php scripts/example_cache_integration.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Cache Integration Example ===\n\n";

// Example 1: Caching Database Query Results
echo "1. Database Query Caching\n";
echo "   Before: Expensive query runs every time\n";
echo "   After: Query results cached for 5 minutes\n\n";

function getArticlesWithoutCache($db): array
{
    echo "   → Running expensive database query...\n";
    usleep(50000); // Simulate 50ms query time
    return [
        ['id' => 1, 'name' => 'Article 1', 'price' => 10.99],
        ['id' => 2, 'name' => 'Article 2', 'price' => 20.99],
    ];
}

function getArticlesWithCache($db): array
{
    return AFS_Cache::remember('db:articles:all', function() use ($db) {
        echo "   → Running expensive database query...\n";
        usleep(50000); // Simulate 50ms query time
        return [
            ['id' => 1, 'name' => 'Article 1', 'price' => 10.99],
            ['id' => 2, 'name' => 'Article 2', 'price' => 20.99],
        ];
    }, 300); // 5 minutes TTL
}

$start = microtime(true);
$articles1 = getArticlesWithCache(null);
$time1 = (microtime(true) - $start) * 1000;
echo "   First call (cache miss): " . number_format($time1, 2) . " ms\n";

$start = microtime(true);
$articles2 = getArticlesWithCache(null);
$time2 = (microtime(true) - $start) * 1000;
echo "   Second call (cache hit): " . number_format($time2, 2) . " ms\n";
echo "   Speedup: " . number_format($time1 / $time2, 1) . "x faster!\n\n";

// Example 2: Caching Hash Calculations
echo "2. Hash Calculation Caching\n";
echo "   Before: Hash calculated every time\n";
echo "   After: Hash cached for 1 hour\n\n";

function calculateHashWithoutCache(array $data): string
{
    echo "   → Computing SHA-256 hash...\n";
    ksort($data);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $json);
}

/**
 * Recursively sort an array by key.
 *
 * @param array &$array
 * @return void
 */
function recursiveKsort(array &$array): void
{
    ksort($array);
    foreach ($array as &$value) {
        if (is_array($value)) {
            recursiveKsort($value);
        }
    }
    unset($value);
}

function calculateHashWithCache(array $data): string
{
    // Use json_encode for deterministic cache key generation
    // Now using recursive ksort for nested arrays
    recursiveKsort($data);
    $cacheKey = 'hash:' . md5(json_encode($data, JSON_UNESCAPED_UNICODE));
    
    return AFS_Cache::remember($cacheKey, function() use ($data) {
        echo "   → Computing SHA-256 hash...\n";
        $dataCopy = $data;
        recursiveKsort($dataCopy);
        $json = json_encode($dataCopy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }, 3600); // 1 hour TTL
}

$testData = array_fill(0, 100, 'test_data_fixed_value');

$start = microtime(true);
$hash1 = calculateHashWithCache($testData);
$time1 = (microtime(true) - $start) * 1000000; // microseconds
echo "   First call (cache miss): " . number_format($time1, 0) . " μs\n";

$start = microtime(true);
$hash2 = calculateHashWithCache($testData);
$time2 = (microtime(true) - $start) * 1000000; // microseconds
echo "   Second call (cache hit): " . number_format($time2, 0) . " μs\n";
echo "   Speedup: " . number_format($time1 / $time2, 1) . "x faster!\n\n";

// Example 3: Caching Mapping Lookups
echo "3. Mapping Lookup Caching\n";
echo "   Before: Mapping loaded from DB every time\n";
echo "   After: Mapping cached for 10 minutes\n\n";

function loadCategoryMapWithoutCache(): array
{
    echo "   → Loading category mapping from database...\n";
    usleep(10000); // Simulate 10ms query time
    return [
        'ELECTRONICS' => 1,
        'CLOTHING' => 2,
        'FURNITURE' => 3,
    ];
}

function loadCategoryMapWithCache(): array
{
    return AFS_Cache::remember('map:categories', function() {
        echo "   → Loading category mapping from database...\n";
        usleep(10000); // Simulate 10ms query time
        return [
            'ELECTRONICS' => 1,
            'CLOTHING' => 2,
            'FURNITURE' => 3,
        ];
    }, 600); // 10 minutes TTL
}

$start = microtime(true);
$map1 = loadCategoryMapWithCache();
$time1 = (microtime(true) - $start) * 1000;
echo "   First call (cache miss): " . number_format($time1, 2) . " ms\n";

$start = microtime(true);
$map2 = loadCategoryMapWithCache();
$time2 = (microtime(true) - $start) * 1000;
echo "   Second call (cache hit): " . number_format($time2, 2) . " ms\n";
echo "   Speedup: " . number_format($time1 / $time2, 1) . "x faster!\n\n";

// Example 4: Cache Invalidation Patterns
echo "4. Cache Invalidation Patterns\n\n";

// Setup some cached data
AFS_Cache::set('article:1', ['id' => 1, 'name' => 'Article 1'], 300);
AFS_Cache::set('article:2', ['id' => 2, 'name' => 'Article 2'], 300);
AFS_Cache::set('article:3', ['id' => 3, 'name' => 'Article 3'], 300);
AFS_Cache::set('category:1', ['id' => 1, 'name' => 'Category 1'], 300);

echo "   Cached 4 items: article:1, article:2, article:3, category:1\n";

// Invalidate specific article
echo "   → Invalidating article:1\n";
AFS_Cache::remove('article:1');
echo "   article:1 exists: " . (AFS_Cache::has('article:1') ? 'yes' : 'no') . "\n";
echo "   article:2 exists: " . (AFS_Cache::has('article:2') ? 'yes' : 'no') . "\n";

// Invalidate all articles using pattern
echo "\n   → Invalidating all articles (pattern: article:*)\n";
$removed = AFS_Cache::removeByPattern('article:*');
echo "   Removed {$removed} entries\n";
echo "   article:2 exists: " . (AFS_Cache::has('article:2') ? 'yes' : 'no') . "\n";
echo "   article:3 exists: " . (AFS_Cache::has('article:3') ? 'yes' : 'no') . "\n";
echo "   category:1 exists: " . (AFS_Cache::has('category:1') ? 'yes' : 'no') . "\n\n";

// Example 5: Cache Statistics and Monitoring
echo "5. Cache Statistics and Monitoring\n\n";

// Reset cache for clean stats
AFS_Cache::clear();

// Perform some operations
AFS_Cache::set('test:1', 'value1', 300);
AFS_Cache::set('test:2', 'value2', 300);
AFS_Cache::set('test:3', 'value3', 300);

AFS_Cache::get('test:1'); // hit
AFS_Cache::get('test:1'); // hit
AFS_Cache::get('test:2'); // hit
AFS_Cache::get('nonexistent'); // miss
AFS_Cache::get('another:miss'); // miss

$stats = AFS_Cache::getStats();

echo "   Cache Statistics:\n";
echo "   ├─ Total Entries: {$stats['size']}\n";
echo "   ├─ Cache Hits: {$stats['hits']}\n";
echo "   ├─ Cache Misses: {$stats['misses']}\n";
echo "   ├─ Hit Rate: {$stats['hit_rate']}%\n";
echo "   ├─ Memory Usage: " . number_format($stats['memory_bytes'] / 1024, 2) . " KB\n";
echo "   └─ Sets: {$stats['sets']}\n\n";

// Example 6: Real-World Integration Pattern
echo "6. Real-World Integration Pattern\n\n";

class ArticleRepository
{
    public function findAll(): array
    {
        return AFS_Cache::remember('repo:articles:all', function() {
            echo "   → Fetching articles from database...\n";
            // Simulate database query
            usleep(30000); // 30ms
            return [
                ['id' => 1, 'name' => 'Product A', 'price' => 99.99],
                ['id' => 2, 'name' => 'Product B', 'price' => 149.99],
            ];
        }, 300); // 5 minutes
    }

    public function findById(int $id): ?array
    {
        $cacheKey = "repo:articles:{$id}";
        
        return AFS_Cache::remember($cacheKey, function() use ($id) {
            echo "   → Fetching article {$id} from database...\n";
            // Simulate database query
            usleep(15000); // 15ms
            return ['id' => $id, 'name' => "Product {$id}", 'price' => 99.99];
        }, 300);
    }

    public function update(int $id, array $data): void
    {
        echo "   → Updating article {$id} in database...\n";
        // Simulate database update
        usleep(20000); // 20ms
        
        // Invalidate caches
        AFS_Cache::remove("repo:articles:{$id}");
        AFS_Cache::remove('repo:articles:all');
        echo "   → Cache invalidated for article {$id}\n";
    }
}

$repo = new ArticleRepository();

echo "   First findAll() call:\n";
$articles = $repo->findAll();
echo "   Retrieved " . count($articles) . " articles\n\n";

echo "   Second findAll() call (cached):\n";
$articles = $repo->findAll();
echo "   Retrieved " . count($articles) . " articles (instant!)\n\n";

echo "   First findById(1) call:\n";
$article = $repo->findById(1);
echo "   Retrieved article: {$article['name']}\n\n";

echo "   Update article 1:\n";
$repo->update(1, ['name' => 'Updated Product A']);
echo "\n";

echo "   Third findAll() call (cache invalidated):\n";
$articles = $repo->findAll();
echo "   Retrieved " . count($articles) . " articles\n\n";

// Final Statistics
echo "=== Final Cache Statistics ===\n";
$finalStats = AFS_Cache::getStats();
echo "Total Hits: {$finalStats['hits']}\n";
echo "Total Misses: {$finalStats['misses']}\n";
echo "Hit Rate: {$finalStats['hit_rate']}%\n";
echo "Cache Size: {$finalStats['size']} entries\n";
echo "Memory: " . number_format($finalStats['memory_bytes'] / 1024, 2) . " KB\n";

echo "\n✅ Integration example completed!\n";
echo "\nSee docs/GENERAL_CACHE.md for more information.\n";
