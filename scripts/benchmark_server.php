#!/usr/bin/env php
<?php
/**
 * Performance Benchmark Script for Apache mpm_event + PHP-FPM
 * 
 * This script performs HTTP benchmarks to measure:
 * - Response time
 * - Throughput (requests per second)
 * - Concurrency handling
 * - Resource usage
 * 
 * Usage:
 *   php scripts/benchmark_server.php [options]
 * 
 * Options:
 *   --url=URL          Base URL to test (default: http://localhost:8080)
 *   --requests=N       Total number of requests (default: 1000)
 *   --concurrency=N    Concurrent requests (default: 10)
 *   --endpoints=LIST   Comma-separated endpoints to test (default: /,/api/health.php)
 */

declare(strict_types=1);

// Parse command line options
$options = getopt('', [
    'url::',
    'requests::',
    'concurrency::',
    'endpoints::',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Performance Benchmark Script for Apache mpm_event + PHP-FPM

Usage:
  php scripts/benchmark_server.php [options]

Options:
  --url=URL          Base URL to test (default: http://localhost:8080)
  --requests=N       Total number of requests (default: 1000)
  --concurrency=N    Concurrent requests (default: 10)
  --endpoints=LIST   Comma-separated endpoints to test (default: /,/api/health.php)
  --help            Show this help message

Examples:
  php scripts/benchmark_server.php
  php scripts/benchmark_server.php --url=http://localhost:8080 --requests=5000 --concurrency=50
  php scripts/benchmark_server.php --endpoints=/,/api/health.php,/api/sync_status.php

HELP;
    exit(0);
}

$baseUrl = $options['url'] ?? 'http://localhost:8080';
$totalRequests = (int)($options['requests'] ?? 1000);
$concurrency = (int)($options['concurrency'] ?? 10);
$endpoints = isset($options['endpoints']) 
    ? explode(',', $options['endpoints']) 
    : ['/', '/api/health.php'];

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  AFS-MappingXT Performance Benchmark                               ║\n";
echo "║  Apache mpm_event + PHP-FPM Configuration                          ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Configuration:\n";
echo "  Base URL:     {$baseUrl}\n";
echo "  Requests:     {$totalRequests}\n";
echo "  Concurrency:  {$concurrency}\n";
echo "  Endpoints:    " . implode(', ', $endpoints) . "\n";
echo "\n";

// Check if Apache Bench (ab) is available
$abPath = trim((string)shell_exec('which ab 2>/dev/null'));
if (!$abPath) {
    echo "⚠️  Warning: Apache Bench (ab) not found.\n";
    echo "   Install it with: apt-get install apache2-utils\n";
    echo "   Falling back to PHP-based benchmark...\n\n";
    
    runPhpBenchmark($baseUrl, $endpoints, $totalRequests, $concurrency);
} else {
    echo "Using Apache Bench (ab) for benchmarking...\n\n";
    runApacheBench($abPath, $baseUrl, $endpoints, $totalRequests, $concurrency);
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════╗\n";
echo "║  Benchmark Complete                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

/**
 * Run benchmark using Apache Bench
 */
function runApacheBench(string $abPath, string $baseUrl, array $endpoints, int $requests, int $concurrency): void
{
    $results = [];
    
    foreach ($endpoints as $endpoint) {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        
        echo "Testing endpoint: {$endpoint}\n";
        echo str_repeat('─', 70) . "\n";
        
        $cmd = sprintf(
            '%s -n %d -c %d -q %s 2>&1',
            escapeshellarg($abPath),
            $requests,
            $concurrency,
            escapeshellarg($url)
        );
        
        $output = shell_exec($cmd);
        
        if ($output) {
            echo $output;
            $results[$endpoint] = parseApacheBenchOutput($output);
        }
        
        echo "\n";
    }
    
    // Summary
    if (!empty($results)) {
        echo "\nSummary:\n";
        echo str_repeat('═', 70) . "\n";
        printf("%-30s %15s %15s %15s\n", "Endpoint", "Req/sec", "Time/req (ms)", "Transfer (KB/s)");
        echo str_repeat('─', 70) . "\n";
        
        foreach ($results as $endpoint => $data) {
            printf(
                "%-30s %15.2f %15.2f %15.2f\n",
                $endpoint,
                $data['requests_per_sec'] ?? 0,
                $data['time_per_request'] ?? 0,
                $data['transfer_rate'] ?? 0
            );
        }
    }
}

/**
 * Parse Apache Bench output
 */
function parseApacheBenchOutput(string $output): array
{
    $result = [];
    
    if (preg_match('/Requests per second:\s+([\d.]+)/', $output, $matches)) {
        $result['requests_per_sec'] = (float)$matches[1];
    }
    
    if (preg_match('/Time per request:\s+([\d.]+).*\(mean\)/', $output, $matches)) {
        $result['time_per_request'] = (float)$matches[1];
    }
    
    if (preg_match('/Transfer rate:\s+([\d.]+)/', $output, $matches)) {
        $result['transfer_rate'] = (float)$matches[1];
    }
    
    return $result;
}

/**
 * Run benchmark using PHP cURL (fallback)
 */
function runPhpBenchmark(string $baseUrl, array $endpoints, int $requests, int $concurrency): void
{
    foreach ($endpoints as $endpoint) {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        
        echo "Testing endpoint: {$endpoint}\n";
        echo str_repeat('─', 70) . "\n";
        
        $times = [];
        $errors = 0;
        $totalBytes = 0;
        
        $startTime = microtime(true);
        
        // Simple sequential requests (no true concurrency without extensions)
        for ($i = 0; $i < $requests; $i++) {
            $reqStart = microtime(true);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($response === false || $httpCode !== 200) {
                $errors++;
            } else {
                $totalBytes += strlen($response);
            }
            
            curl_close($ch);
            
            $reqEnd = microtime(true);
            $times[] = ($reqEnd - $reqStart) * 1000; // Convert to milliseconds
            
            // Progress indicator
            if (($i + 1) % 100 === 0) {
                echo sprintf("  Progress: %d/%d requests completed\r", $i + 1, $requests);
            }
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        echo "\n";
        
        // Calculate statistics
        $successfulRequests = $requests - $errors;
        $requestsPerSec = $successfulRequests / $totalTime;
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        sort($times);
        $p50 = $times[(int)(count($times) * 0.50)];
        $p95 = $times[(int)(count($times) * 0.95)];
        $p99 = $times[(int)(count($times) * 0.99)];
        
        // Output results
        echo "\n";
        echo "Results:\n";
        echo "  Total requests:        {$requests}\n";
        echo "  Successful requests:   {$successfulRequests}\n";
        echo "  Failed requests:       {$errors}\n";
        echo "  Total time:            " . sprintf("%.2f", $totalTime) . " seconds\n";
        echo "  Requests per second:   " . sprintf("%.2f", $requestsPerSec) . " req/s\n";
        echo "  Average time:          " . sprintf("%.2f", $avgTime) . " ms\n";
        echo "  Min time:              " . sprintf("%.2f", $minTime) . " ms\n";
        echo "  Max time:              " . sprintf("%.2f", $maxTime) . " ms\n";
        echo "  50th percentile:       " . sprintf("%.2f", $p50) . " ms\n";
        echo "  95th percentile:       " . sprintf("%.2f", $p95) . " ms\n";
        echo "  99th percentile:       " . sprintf("%.2f", $p99) . " ms\n";
        echo "  Total transferred:     " . sprintf("%.2f", $totalBytes / 1024) . " KB\n";
        echo "\n";
    }
}
