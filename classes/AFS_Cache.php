<?php
/**
 * AFS_Cache - General-purpose in-memory cache for expensive calculations and requests
 * 
 * This class provides a flexible caching layer for:
 * - Database query results
 * - Expensive calculations (e.g., hash computations)
 * - API request responses
 * - Any data that is expensive to compute/fetch
 * 
 * Features:
 * - TTL-based expiration (time-to-live)
 * - Key-based invalidation
 * - Cache statistics and monitoring
 * - Memory-efficient storage
 * - Simple API for integration
 * 
 * Performance benefits:
 * - Reduces redundant database queries
 * - Avoids recalculating expensive operations
 * - Improves overall application responsiveness
 * 
 * Usage:
 * ```php
 * // Store data with 1-hour TTL
 * AFS_Cache::set('articles:all', $articles, 3600);
 * 
 * // Retrieve cached data
 * $articles = AFS_Cache::get('articles:all');
 * 
 * // Check if key exists
 * if (AFS_Cache::has('articles:all')) {
 *     // Use cached data
 * }
 * 
 * // Invalidate specific key
 * AFS_Cache::remove('articles:all');
 * 
 * // Clear all cache entries
 * AFS_Cache::clear();
 * 
 * // Get cache statistics
 * $stats = AFS_Cache::getStats();
 * ```
 */
class AFS_Cache
{
    /**
     * Cache storage: key => ['data' => mixed, 'expires_at' => int|null]
     * @var array<string, array{data: mixed, expires_at: int|null}>
     */
    private static array $cache = [];

    /**
     * Cache statistics
     * @var array{hits: int, misses: int, sets: int, evictions: int}
     */
    private static array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'evictions' => 0,
    ];

    /**
     * Default TTL in seconds (1 hour)
     */
    private const DEFAULT_TTL = 3600;

    /**
     * Maximum cache size (number of entries) before auto-eviction
     * Set to 0 to disable size limit
     */
    private const MAX_CACHE_SIZE = 1000;

    /**
     * Get cached data by key
     * 
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public static function get(string $key): mixed
    {
        if (!isset(self::$cache[$key])) {
            self::$stats['misses']++;
            return null;
        }

        $entry = self::$cache[$key];

        // Check if entry has expired
        if ($entry['expires_at'] !== null && time() >= $entry['expires_at']) {
            self::$stats['misses']++;
            self::$stats['evictions']++;
            unset(self::$cache[$key]);
            return null;
        }

        self::$stats['hits']++;
        return $entry['data'];
    }

    /**
     * Store data in cache with optional TTL
     * 
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int|null $ttl Time-to-live in seconds (null = no expiration)
     * @return void
     */
    public static function set(string $key, mixed $data, ?int $ttl = null): void
    {
        // Apply default TTL if not specified
        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }

        // Calculate expiration time
        $expiresAt = $ttl > 0 ? time() + $ttl : null;

        // Check if we need to evict old entries
        if (self::MAX_CACHE_SIZE > 0 && count(self::$cache) >= self::MAX_CACHE_SIZE) {
            self::evictOldest();
        }

        self::$cache[$key] = [
            'data' => $data,
            'expires_at' => $expiresAt,
        ];

        self::$stats['sets']++;
    }

    /**
     * Check if a key exists in cache and is not expired
     * 
     * @param string $key Cache key
     * @return bool True if key exists and is valid
     */
    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * Remove a specific key from cache
     * 
     * @param string $key Cache key
     * @return bool True if key was removed, false if it didn't exist
     */
    public static function remove(string $key): bool
    {
        if (isset(self::$cache[$key])) {
            unset(self::$cache[$key]);
            self::$stats['evictions']++;
            return true;
        }
        return false;
    }

    /**
     * Remove all keys matching a pattern (supports * wildcard)
     * 
     * @param string $pattern Pattern to match (e.g., "articles:*")
     * @return int Number of keys removed
     */
    public static function removeByPattern(string $pattern): int
    {
        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $removed = 0;

        foreach (array_keys(self::$cache) as $key) {
            if (preg_match($regex, $key)) {
                unset(self::$cache[$key]);
                $removed++;
            }
        }

        self::$stats['evictions'] += $removed;
        return $removed;
    }

    /**
     * Clear the entire cache
     * 
     * @return void
     */
    public static function clear(): void
    {
        $count = count(self::$cache);
        self::$cache = [];
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'evictions' => 0,
        ];
        // Don't count clear as evictions, it's a deliberate action
    }

    /**
     * Get cache statistics
     * 
     * @return array{hits: int, misses: int, sets: int, evictions: int, size: int, hit_rate: float, memory_bytes: int}
     */
    public static function getStats(): array
    {
        $total = self::$stats['hits'] + self::$stats['misses'];
        $hitRate = $total > 0 ? (self::$stats['hits'] / $total) * 100 : 0.0;

        // Estimate memory usage (rough approximation)
        $memoryBytes = 0;
        foreach (self::$cache as $entry) {
            $memoryBytes += strlen(json_encode($entry));
        }

        return [
            'hits' => self::$stats['hits'],
            'misses' => self::$stats['misses'],
            'sets' => self::$stats['sets'],
            'evictions' => self::$stats['evictions'],
            'size' => count(self::$cache),
            'hit_rate' => round($hitRate, 2),
            'memory_bytes' => $memoryBytes,
        ];
    }

    /**
     * Get or set cached data using a callback
     * 
     * This is a convenience method that checks the cache first,
     * and if the data is not cached, executes the callback,
     * caches the result, and returns it.
     * 
     * @param string $key Cache key
     * @param callable $callback Callback to execute if data is not cached
     * @param int|null $ttl Time-to-live in seconds (null = default)
     * @return mixed Cached or newly computed data
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cached = self::get($key);
        
        if ($cached !== null) {
            return $cached;
        }

        $data = $callback();
        self::set($key, $data, $ttl);
        
        return $data;
    }

    /**
     * Clean up expired entries
     * 
     * This method removes all expired entries from the cache.
     * It's called automatically when needed, but can also be
     * called manually for maintenance.
     * 
     * @return int Number of entries removed
     */
    public static function cleanupExpired(): int
    {
        $removed = 0;
        $now = time();

        foreach (self::$cache as $key => $entry) {
            if ($entry['expires_at'] !== null && $now >= $entry['expires_at']) {
                unset(self::$cache[$key]);
                $removed++;
            }
        }

        self::$stats['evictions'] += $removed;
        return $removed;
    }

    /**
     * Evict oldest entries when cache is full
     * 
     * @return void
     */
    private static function evictOldest(): void
    {
        // First, try to clean up expired entries
        $removed = self::cleanupExpired();

        // If still over limit, remove oldest 10% of entries
        if (count(self::$cache) >= self::MAX_CACHE_SIZE) {
            $toRemove = max(1, (int)(self::MAX_CACHE_SIZE * 0.1));
            $keys = array_keys(self::$cache);
            
            for ($i = 0; $i < $toRemove && count($keys) > 0; $i++) {
                unset(self::$cache[array_shift($keys)]);
                self::$stats['evictions']++;
            }
        }
    }

    /**
     * Get all cache keys
     * 
     * @return array<int, string> List of all cache keys
     */
    public static function keys(): array
    {
        return array_keys(self::$cache);
    }

    /**
     * Get cache size (number of entries)
     * 
     * @return int Number of entries in cache
     */
    public static function size(): int
    {
        return count(self::$cache);
    }
}
