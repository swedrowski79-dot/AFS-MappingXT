#!/usr/bin/env php
<?php
/**
 * Project-Wide Performance Analysis
 * 
 * Comprehensive performance analysis tool for the AFS-MappingXT project.
 * Analyzes execution times, memory usage, database operations, and identifies bottlenecks.
 * 
 * Usage: php scripts/analyze_performance.php [--detailed] [--export=json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

class AFS_PerformanceAnalyzer
{
    private string $reportFile;
    private array $results = [];
    private array $recommendations = [];
    private float $startTime;
    private int $startMemory;
    private bool $detailedMode = false;
    private ?string $exportFormat = null;
    
    public function __construct(bool $detailedMode = false, ?string $exportFormat = null)
    {
        $this->detailedMode = $detailedMode;
        $this->exportFormat = $exportFormat;
        $this->reportFile = __DIR__ . '/../logs/performance_analysis_' . date('Y-m-d_H-i-s') . '.log';
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
        
        $this->log("=== AFS-MappingXT Project-Wide Performance Analysis ===");
        $this->log("Started at: " . date('Y-m-d H:i:s'));
        $this->log("Mode: " . ($detailedMode ? "DETAILED" : "STANDARD"));
        $this->log("");
    }
    
    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        echo $logEntry;
        file_put_contents($this->reportFile, $logEntry, FILE_APPEND);
    }
    
    private function recordMetric(string $category, string $metric, $value, array $details = []): void
    {
        if (!isset($this->results[$category])) {
            $this->results[$category] = [];
        }
        
        $this->results[$category][$metric] = [
            'value' => $value,
            'details' => $details,
            'timestamp' => microtime(true)
        ];
    }
    
    private function addRecommendation(string $category, string $priority, string $recommendation): void
    {
        $this->recommendations[] = [
            'category' => $category,
            'priority' => $priority,
            'recommendation' => $recommendation
        ];
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
    
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return sprintf("%.2f μs", $seconds * 1000000);
        } elseif ($seconds < 1) {
            return sprintf("%.2f ms", $seconds * 1000);
        } elseif ($seconds < 60) {
            return sprintf("%.2f s", $seconds);
        } else {
            $minutes = floor($seconds / 60);
            $secs = $seconds - ($minutes * 60);
            return sprintf("%d min %.2f s", $minutes, $secs);
        }
    }
    
    public function run(): void
    {
        $this->log("\n### Phase 1: Configuration & Initialization Performance ###\n");
        $this->analyzeConfigurationPerformance();
        
        $this->log("\n### Phase 2: YAML Mapping Performance ###\n");
        $this->analyzeYamlMappingPerformance();
        
        $this->log("\n### Phase 3: SQL Generation Performance ###\n");
        $this->analyzeSqlGenerationPerformance();
        
        $this->log("\n### Phase 4: Database Operations Performance ###\n");
        $this->analyzeDatabasePerformance();
        
        $this->log("\n### Phase 5: Hash Calculation Performance ###\n");
        $this->analyzeHashPerformance();
        
        $this->log("\n### Phase 6: Memory Usage Analysis ###\n");
        $this->analyzeMemoryUsage();
        
        $this->log("\n### Phase 7: File I/O Performance ###\n");
        $this->analyzeFileIOPerformance();
        
        $this->log("\n### Phase 8: Class Instantiation Overhead ###\n");
        $this->analyzeClassInstantiationOverhead();
        
        $this->generateReport();
    }
    
    private function analyzeConfigurationPerformance(): void
    {
        $this->log("Testing configuration loading...");
        
        $iterations = $this->detailedMode ? 1000 : 100;
        $start = microtime(true);
        $memBefore = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $config = require __DIR__ . '/../config.php';
        }
        
        $duration = microtime(true) - $start;
        $memAfter = memory_get_usage(true);
        $avgTime = $duration / $iterations;
        
        $this->recordMetric('configuration', 'load_time_avg', $avgTime, [
            'iterations' => $iterations,
            'total_time' => $duration,
            'memory_delta' => $memAfter - $memBefore
        ]);
        
        $this->log("✓ Config load: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
        $this->log("  Memory impact: {$this->formatBytes($memAfter - $memBefore)}");
        
        if ($avgTime > 0.001) {
            $this->addRecommendation('configuration', 'LOW', 
                'Consider caching config.php result if loaded multiple times per request');
        }
    }
    
    private function analyzeYamlMappingPerformance(): void
    {
        $this->log("Testing YAML mapping configuration loading...");
        
        // Test source mapping
        $sourceMappingFile = __DIR__ . '/../mappings/source_afs.yml';
        if (!file_exists($sourceMappingFile)) {
            $this->log("⚠ Source mapping file not found, skipping", 'WARN');
            return;
        }
        
        $iterations = $this->detailedMode ? 100 : 10;
        
        // Test AFS_MappingConfig
        $start = microtime(true);
        $memBefore = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $config = new AFS_MappingConfig($sourceMappingFile);
        }
        
        $duration = microtime(true) - $start;
        $memAfter = memory_get_usage(true);
        $avgTime = $duration / $iterations;
        
        $this->recordMetric('yaml_mapping', 'source_config_load_avg', $avgTime, [
            'iterations' => $iterations,
            'file_size' => filesize($sourceMappingFile),
            'memory_delta' => $memAfter - $memBefore
        ]);
        
        $this->log("✓ Source mapping load: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
        $this->log("  File size: {$this->formatBytes(filesize($sourceMappingFile))}");
        $this->log("  Memory per instance: {$this->formatBytes(($memAfter - $memBefore) / $iterations)}");
        
        // Test target mapping
        $targetMappingFile = __DIR__ . '/../mappings/target_sqlite.yml';
        if (file_exists($targetMappingFile)) {
            $start = microtime(true);
            $memBefore = memory_get_usage(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                $config = new AFS_TargetMappingConfig($targetMappingFile);
            }
            
            $duration = microtime(true) - $start;
            $memAfter = memory_get_usage(true);
            $avgTime = $duration / $iterations;
            
            $this->recordMetric('yaml_mapping', 'target_config_load_avg', $avgTime, [
                'iterations' => $iterations,
                'file_size' => filesize($targetMappingFile),
                'memory_delta' => $memAfter - $memBefore
            ]);
            
            $this->log("✓ Target mapping load: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
            $this->log("  File size: {$this->formatBytes(filesize($targetMappingFile))}");
            $this->log("  Memory per instance: {$this->formatBytes(($memAfter - $memBefore) / $iterations)}");
        }
        
        if ($avgTime > 0.01) {
            $this->addRecommendation('yaml_mapping', 'MEDIUM', 
                'YAML parsing is slow. Consider implementing a caching layer for mapping configurations.');
        }
    }
    
    private function analyzeSqlGenerationPerformance(): void
    {
        $this->log("Testing SQL generation performance...");
        
        $sourceMappingFile = __DIR__ . '/../mappings/source_afs.yml';
        if (!file_exists($sourceMappingFile)) {
            $this->log("⚠ Source mapping file not found, skipping", 'WARN');
            return;
        }
        
        $config = new AFS_MappingConfig($sourceMappingFile);
        $entities = ['Artikel', 'Warengruppe', 'Dokumente'];
        
        foreach ($entities as $entity) {
            $iterations = $this->detailedMode ? 1000 : 100;
            
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $sql = $config->buildSelectQuery($entity);
            }
            $duration = microtime(true) - $start;
            $avgTime = $duration / $iterations;
            
            $this->recordMetric('sql_generation', "{$entity}_select_avg", $avgTime, [
                'iterations' => $iterations,
                'sql_length' => strlen($sql)
            ]);
            
            $this->log("✓ {$entity} SELECT generation: {$this->formatDuration($avgTime)} avg");
            
            if ($avgTime > 0.001) {
                $this->addRecommendation('sql_generation', 'MEDIUM', 
                    "SQL generation for {$entity} takes {$this->formatDuration($avgTime)}. Consider caching generated SQL.");
            }
        }
    }
    
    private function analyzeDatabasePerformance(): void
    {
        $this->log("Testing database operations...");
        
        $dbPath = __DIR__ . '/../db/evo.db';
        if (!file_exists($dbPath)) {
            $this->log("⚠ Database not found, skipping actual DB tests", 'WARN');
            $this->log("  Run scripts/setup.php to initialize databases");
            return;
        }
        
        try {
            // Test connection performance
            $start = microtime(true);
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connectTime = microtime(true) - $start;
            
            $this->recordMetric('database', 'connection_time', $connectTime);
            $this->log("✓ Database connection: {$this->formatDuration($connectTime)}");
            
            // Test simple query performance
            $iterations = $this->detailedMode ? 1000 : 100;
            $start = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                $stmt = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
                $stmt->fetch();
            }
            
            $duration = microtime(true) - $start;
            $avgTime = $duration / $iterations;
            
            $this->recordMetric('database', 'simple_query_avg', $avgTime, [
                'iterations' => $iterations
            ]);
            
            $this->log("✓ Simple query: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
            
            // Test transaction overhead
            $start = microtime(true);
            $db->beginTransaction();
            $db->commit();
            $transactionTime = microtime(true) - $start;
            
            $this->recordMetric('database', 'transaction_overhead', $transactionTime);
            $this->log("✓ Transaction overhead (begin+commit): {$this->formatDuration($transactionTime)}");
            
            // Test prepared statement performance
            $start = microtime(true);
            $stmt = $db->prepare("SELECT * FROM sqlite_master WHERE name = ?");
            $prepareTime = microtime(true) - $start;
            
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $stmt->execute(['test']);
                $stmt->fetchAll();
            }
            $executeTime = (microtime(true) - $start) / $iterations;
            
            $this->recordMetric('database', 'prepared_statement_prepare', $prepareTime);
            $this->recordMetric('database', 'prepared_statement_execute_avg', $executeTime, [
                'iterations' => $iterations
            ]);
            
            $this->log("✓ Prepared statement prepare: {$this->formatDuration($prepareTime)}");
            $this->log("✓ Prepared statement execute: {$this->formatDuration($executeTime)} avg");
            
            if ($transactionTime > 0.01) {
                $this->addRecommendation('database', 'HIGH', 
                    'Transaction overhead is significant. Ensure bulk operations use transactions.');
            }
            
        } catch (Exception $e) {
            $this->log("✗ Database test failed: {$e->getMessage()}", 'ERROR');
        }
    }
    
    private function analyzeHashPerformance(): void
    {
        $this->log("Testing hash calculation performance...");
        
        // Test hash_hmac performance (used by HashManager)
        $testData = array_fill(0, 100, 'test_value_' . str_repeat('x', 100));
        $iterations = $this->detailedMode ? 10000 : 1000;
        
        // Test different hash algorithms
        $algorithms = ['sha256', 'md5', 'sha1'];
        
        foreach ($algorithms as $algo) {
            $start = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                hash($algo, json_encode($testData));
            }
            
            $duration = microtime(true) - $start;
            $avgTime = $duration / $iterations;
            
            $this->recordMetric('hash', "{$algo}_avg", $avgTime, [
                'iterations' => $iterations,
                'data_size' => strlen(json_encode($testData))
            ]);
            
            $this->log("✓ {$algo} hash: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
        }
        
        // Test AFS_HashManager if available
        $dbPath = __DIR__ . '/../db/evo.db';
        if (file_exists($dbPath)) {
            try {
                $db = new PDO('sqlite:' . $dbPath);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $hashManager = new AFS_HashManager($db);
                
                $sampleData = [
                    'Artikelnummer' => 'TEST-001',
                    'Bezeichnung' => 'Test Product',
                    'Preis' => 99.99,
                    'Beschreibung' => str_repeat('Lorem ipsum dolor sit amet. ', 20)
                ];
                
                $iterations = $this->detailedMode ? 1000 : 100;
                $start = microtime(true);
                
                for ($i = 0; $i < $iterations; $i++) {
                    $hashManager->calculateHash($sampleData);
                }
                
                $duration = microtime(true) - $start;
                $avgTime = $duration / $iterations;
                
                $this->recordMetric('hash', 'hashmanager_calculate_avg', $avgTime, [
                    'iterations' => $iterations,
                    'data_size' => strlen(json_encode($sampleData))
                ]);
                
                $this->log("✓ HashManager::calculateHash: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
                
            } catch (Exception $e) {
                $this->log("⚠ HashManager test failed: {$e->getMessage()}", 'WARN');
            }
        }
        
        // Recommendation based on results
        if (isset($this->results['hash']['sha256_avg']['value']) && 
            $this->results['hash']['sha256_avg']['value'] > 0.0001) {
            $this->addRecommendation('hash', 'MEDIUM', 
                'Hash calculation overhead is measurable. Consider batching hash calculations when possible.');
        }
    }
    
    private function analyzeMemoryUsage(): void
    {
        $this->log("Analyzing memory usage patterns...");
        
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $initialMemory = $this->startMemory;
        
        $this->recordMetric('memory', 'current_usage', $currentMemory);
        $this->recordMetric('memory', 'peak_usage', $peakMemory);
        $this->recordMetric('memory', 'delta_from_start', $currentMemory - $initialMemory);
        
        $this->log("✓ Current memory usage: {$this->formatBytes($currentMemory)}");
        $this->log("✓ Peak memory usage: {$this->formatBytes($peakMemory)}");
        $this->log("✓ Memory delta from start: {$this->formatBytes($currentMemory - $initialMemory)}");
        
        // Test memory usage of creating multiple instances
        if ($this->detailedMode) {
            $memBefore = memory_get_usage(true);
            $instances = [];
            
            for ($i = 0; $i < 100; $i++) {
                $config = require __DIR__ . '/../config.php';
                $instances[] = $config;
            }
            
            $memAfter = memory_get_usage(true);
            $memPerInstance = ($memAfter - $memBefore) / 100;
            
            $this->recordMetric('memory', 'config_instance_memory', $memPerInstance);
            $this->log("✓ Memory per config instance: {$this->formatBytes((int)$memPerInstance)}");
            
            unset($instances);
        }
        
        if ($peakMemory > 128 * 1024 * 1024) {
            $this->addRecommendation('memory', 'HIGH', 
                'Peak memory usage exceeds 128MB. Consider implementing streaming or chunked processing for large datasets.');
        }
    }
    
    private function analyzeFileIOPerformance(): void
    {
        $this->log("Testing file I/O performance...");
        
        $testDir = sys_get_temp_dir() . '/afs_perf_test_' . uniqid();
        mkdir($testDir, 0777, true);
        
        try {
            // Test file write performance
            $iterations = $this->detailedMode ? 100 : 10;
            $testData = str_repeat('x', 1024 * 10); // 10KB
            
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                file_put_contents("{$testDir}/test_{$i}.txt", $testData);
            }
            $writeTime = (microtime(true) - $start) / $iterations;
            
            $this->recordMetric('file_io', 'write_10kb_avg', $writeTime, [
                'iterations' => $iterations
            ]);
            $this->log("✓ File write (10KB): {$this->formatDuration($writeTime)} avg ({$iterations} iterations)");
            
            // Test file read performance
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                file_get_contents("{$testDir}/test_{$i}.txt");
            }
            $readTime = (microtime(true) - $start) / $iterations;
            
            $this->recordMetric('file_io', 'read_10kb_avg', $readTime, [
                'iterations' => $iterations
            ]);
            $this->log("✓ File read (10KB): {$this->formatDuration($readTime)} avg ({$iterations} iterations)");
            
            // Test file_exists performance
            $start = microtime(true);
            for ($i = 0; $i < $iterations * 10; $i++) {
                file_exists("{$testDir}/test_0.txt");
            }
            $existsTime = (microtime(true) - $start) / ($iterations * 10);
            
            $this->recordMetric('file_io', 'file_exists_avg', $existsTime, [
                'iterations' => $iterations * 10
            ]);
            $this->log("✓ file_exists check: {$this->formatDuration($existsTime)} avg");
            
            // Test JSON logging performance
            $logData = [
                'timestamp' => date('c'),
                'operation' => 'test',
                'level' => 'info',
                'message' => 'Test message',
                'context' => ['key' => 'value']
            ];
            
            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                file_put_contents(
                    "{$testDir}/test.log",
                    json_encode($logData) . "\n",
                    FILE_APPEND
                );
            }
            $jsonLogTime = (microtime(true) - $start) / $iterations;
            
            $this->recordMetric('file_io', 'json_log_append_avg', $jsonLogTime, [
                'iterations' => $iterations
            ]);
            $this->log("✓ JSON log append: {$this->formatDuration($jsonLogTime)} avg");
            
            if ($writeTime > 0.01 || $readTime > 0.01) {
                $this->addRecommendation('file_io', 'MEDIUM', 
                    'File I/O operations are slow. Ensure file operations are batched when possible.');
            }
            
        } finally {
            // Cleanup
            array_map('unlink', glob("{$testDir}/*"));
            rmdir($testDir);
        }
    }
    
    private function analyzeClassInstantiationOverhead(): void
    {
        $this->log("Testing class instantiation overhead...");
        
        $classes = [
            'AFS_MappingLogger' => function() {
                return new AFS_MappingLogger(sys_get_temp_dir(), '1.0.0');
            },
        ];
        
        foreach ($classes as $className => $factory) {
            $iterations = $this->detailedMode ? 1000 : 100;
            
            $start = microtime(true);
            $memBefore = memory_get_usage(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                $instance = $factory();
                unset($instance);
            }
            
            $duration = microtime(true) - $start;
            $memAfter = memory_get_usage(true);
            $avgTime = $duration / $iterations;
            
            $this->recordMetric('class_instantiation', $className, $avgTime, [
                'iterations' => $iterations,
                'memory_delta' => $memAfter - $memBefore
            ]);
            
            $this->log("✓ {$className}: {$this->formatDuration($avgTime)} avg ({$iterations} iterations)");
        }
    }
    
    private function generateReport(): void
    {
        $totalDuration = microtime(true) - $this->startTime;
        $totalMemory = memory_get_peak_usage(true) - $this->startMemory;
        
        $this->log("\n" . str_repeat("=", 80));
        $this->log("PERFORMANCE ANALYSIS SUMMARY");
        $this->log(str_repeat("=", 80));
        
        $this->log("\nTotal Analysis Time: {$this->formatDuration($totalDuration)}");
        $this->log("Total Memory Used: {$this->formatBytes($totalMemory)}");
        
        // Summary by category
        $this->log("\n" . str_repeat("-", 80));
        $this->log("METRICS BY CATEGORY");
        $this->log(str_repeat("-", 80));
        
        foreach ($this->results as $category => $metrics) {
            $this->log("\n{$category}:");
            foreach ($metrics as $metric => $data) {
                $value = is_float($data['value']) ? $this->formatDuration($data['value']) : $data['value'];
                $this->log("  • {$metric}: {$value}");
            }
        }
        
        // Recommendations
        if (!empty($this->recommendations)) {
            $this->log("\n" . str_repeat("-", 80));
            $this->log("OPTIMIZATION RECOMMENDATIONS");
            $this->log(str_repeat("-", 80));
            
            $priorityOrder = ['HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
            usort($this->recommendations, function($a, $b) use ($priorityOrder) {
                return $priorityOrder[$a['priority']] <=> $priorityOrder[$b['priority']];
            });
            
            foreach ($this->recommendations as $rec) {
                $this->log("\n[{$rec['priority']}] {$rec['category']}:");
                $this->log("  {$rec['recommendation']}");
            }
        } else {
            $this->log("\n✓ No optimization recommendations - performance is excellent!");
        }
        
        $this->log("\n" . str_repeat("=", 80));
        $this->log("Report saved to: {$this->reportFile}");
        
        // Export to JSON if requested
        if ($this->exportFormat === 'json') {
            $jsonFile = str_replace('.log', '.json', $this->reportFile);
            $exportData = [
                'timestamp' => date('c'),
                'analysis_duration' => $totalDuration,
                'analysis_memory' => $totalMemory,
                'mode' => $this->detailedMode ? 'detailed' : 'standard',
                'results' => $this->results,
                'recommendations' => $this->recommendations
            ];
            file_put_contents($jsonFile, json_encode($exportData, JSON_PRETTY_PRINT));
            $this->log("JSON export saved to: {$jsonFile}");
        }
        
        $this->log(str_repeat("=", 80) . "\n");
    }
}

// Parse command-line arguments
$detailedMode = in_array('--detailed', $argv ?? []);
$exportFormat = null;

foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--export=') === 0) {
        $exportFormat = substr($arg, 9);
    }
}

// Run the analysis
try {
    $analyzer = new AFS_PerformanceAnalyzer($detailedMode, $exportFormat);
    $analyzer->run();
    exit(0);
} catch (Exception $e) {
    echo "ERROR: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
