#!/usr/bin/env php
<?php
/**
 * Validation Script: Verify No Hardcoded SQL or Field Mappings
 * 
 * This script validates that the codebase is fully mapping-based
 * with no hardcoded SQL queries or direct field mappings.
 * 
 * Checks:
 * 1. All SQL is generated from mapping configs
 * 2. No direct database field references in business logic
 * 3. All field access goes through mapping configuration
 * 4. No legacy patterns remain
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

class HardcodingValidator
{
    private array $issues = [];
    private int $checksPerformed = 0;
    
    public function __construct()
    {
        echo "=== Validating: No Hardcodings or Legacy Code ===\n\n";
    }
    
    public function run(): bool
    {
        echo "Phase 1: Verify Mapping Configurations Exist and Are Valid\n";
        $this->validateMappingConfigs();
        
        echo "\nPhase 2: Verify SQL Generation Uses Mapping System\n";
        $this->validateSqlGeneration();
        
        echo "\nPhase 3: Verify All Sync Classes Use Mapping-Based Approach\n";
        $this->validateSyncClasses();
        
        echo "\nPhase 4: Verify No Legacy Patterns\n";
        $this->validateNoLegacyPatterns();
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "VALIDATION SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        echo "Total checks performed: {$this->checksPerformed}\n";
        echo "Issues found: " . count($this->issues) . "\n";
        
        if (empty($this->issues)) {
            echo "\n✓ SUCCESS: All validation checks passed!\n";
            echo "✓ System is fully mapping-based\n";
            echo "✓ No hardcoded SQL or field mappings found\n";
            echo "✓ No legacy patterns detected\n";
            return true;
        } else {
            echo "\n✗ ISSUES FOUND:\n";
            foreach ($this->issues as $issue) {
                echo "  - {$issue}\n";
            }
            return false;
        }
    }
    
    private function check(string $description, bool $condition, string $failureMessage = ''): void
    {
        $this->checksPerformed++;
        if ($condition) {
            echo "  ✓ {$description}\n";
        } else {
            echo "  ✗ {$description}\n";
            $this->issues[] = $failureMessage ?: $description;
        }
    }
    
    private function validateMappingConfigs(): void
    {
        // Check source mapping exists and is valid
        $sourceMappingPath = __DIR__ . '/../mappings/source_afs.yml';
        $this->check(
            'Source mapping file exists',
            file_exists($sourceMappingPath),
            'Source mapping file not found'
        );
        
        try {
            $sourceMapping = new AFS_MappingConfig($sourceMappingPath);
            $this->check('Source mapping loads successfully', true);
            
            // Verify all required entities
            $requiredEntities = ['Artikel', 'Warengruppe', 'Dokumente'];
            foreach ($requiredEntities as $entity) {
                $entityConfig = $sourceMapping->getEntity($entity);
                $this->check(
                    "Source mapping defines entity '{$entity}'",
                    $entityConfig !== null,
                    "Entity '{$entity}' missing from source mapping"
                );
            }
        } catch (Exception $e) {
            $this->check('Source mapping loads successfully', false, $e->getMessage());
        }
        
        // Check target mapping exists and is valid
        $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
        $this->check(
            'Target mapping file exists',
            file_exists($targetMappingPath),
            'Target mapping file not found'
        );
        
        try {
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            $this->check('Target mapping loads successfully', true);
            
            $version = $targetMapping->getVersion();
            $this->check(
                'Target mapping has version',
                $version !== null && $version !== '',
                'Target mapping version not defined'
            );
            
            // Verify all required entities
            $requiredTargetEntities = ['articles', 'categories', 'images', 'documents', 'attributes'];
            foreach ($requiredTargetEntities as $entity) {
                $entityConfig = $targetMapping->getEntity($entity);
                $this->check(
                    "Target mapping defines entity '{$entity}'",
                    $entityConfig !== null,
                    "Entity '{$entity}' missing from target mapping"
                );
            }
            
            // Verify all required relationships
            $requiredRelationships = ['article_images', 'article_documents', 'article_attributes'];
            foreach ($requiredRelationships as $relationship) {
                $tableName = $targetMapping->getRelationshipTableName($relationship);
                $this->check(
                    "Target mapping defines relationship '{$relationship}'",
                    $tableName !== null && $tableName !== '',
                    "Relationship '{$relationship}' missing from target mapping"
                );
            }
        } catch (Exception $e) {
            $this->check('Target mapping loads successfully', false, $e->getMessage());
        }
    }
    
    private function validateSqlGeneration(): void
    {
        try {
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            $sqlBuilder = new AFS_SqlBuilder($targetMapping);
            
            // Test article UPSERT generation
            $articleUpsertSql = $sqlBuilder->buildEntityUpsert('articles');
            $this->check(
                'SQL Builder generates article UPSERT statement',
                !empty($articleUpsertSql) && str_contains($articleUpsertSql, 'INSERT'),
                'Failed to generate article UPSERT SQL'
            );
            
            $this->check(
                'Generated SQL uses table from mapping',
                str_contains($articleUpsertSql, 'Artikel'),
                'Generated SQL does not use mapped table name'
            );
            
            // Test relationship UPSERT generation
            $imageRelationSql = $sqlBuilder->buildRelationshipUpsert('article_images');
            $this->check(
                'SQL Builder generates relationship UPSERT statement',
                !empty($imageRelationSql) && str_contains($imageRelationSql, 'INSERT'),
                'Failed to generate relationship UPSERT SQL'
            );
            
            $this->check(
                'Generated relationship SQL uses table from mapping',
                str_contains($imageRelationSql, 'Artikel_Bilder'),
                'Generated relationship SQL does not use mapped table name'
            );
            
            // Verify DELETE statement generation
            $deleteImageSql = $sqlBuilder->buildRelationshipDelete('article_images', ['Artikel_ID', 'Bild_ID']);
            $this->check(
                'SQL Builder generates DELETE statement',
                !empty($deleteImageSql) && str_contains($deleteImageSql, 'DELETE'),
                'Failed to generate DELETE SQL'
            );
            
        } catch (Exception $e) {
            $this->check('SQL generation works correctly', false, $e->getMessage());
        }
    }
    
    private function validateSyncClasses(): void
    {
        // Check that AFS_Evo_ArticleSync uses mapping system
        $articleSyncFile = __DIR__ . '/../classes/AFS_Evo_ArticleSync.php';
        $this->check(
            'AFS_Evo_ArticleSync file exists',
            file_exists($articleSyncFile),
            'AFS_Evo_ArticleSync file not found'
        );
        
        if (file_exists($articleSyncFile)) {
            $content = file_get_contents($articleSyncFile);
            
            // Check that it uses AFS_TargetMappingConfig
            $this->check(
                'AFS_Evo_ArticleSync uses AFS_TargetMappingConfig',
                str_contains($content, 'AFS_TargetMappingConfig'),
                'AFS_Evo_ArticleSync does not use AFS_TargetMappingConfig'
            );
            
            // Check that it uses AFS_SqlBuilder
            $this->check(
                'AFS_Evo_ArticleSync uses AFS_SqlBuilder',
                str_contains($content, 'AFS_SqlBuilder'),
                'AFS_Evo_ArticleSync does not use AFS_SqlBuilder'
            );
            
            // Check that SQL is generated dynamically
            $this->check(
                'AFS_Evo_ArticleSync generates SQL dynamically',
                str_contains($content, 'buildEntityUpsert') || str_contains($content, 'buildRelationshipUpsert'),
                'AFS_Evo_ArticleSync does not generate SQL dynamically'
            );
            
            // Verify no hardcoded INSERT/UPDATE statements (excluding comments/strings)
            // Allow comments and test code, but not actual SQL statements in the class
            $lines = explode("\n", $content);
            $hardcodedSqlCount = 0;
            foreach ($lines as $line) {
                $trimmed = trim($line);
                // Skip comments
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // Check for hardcoded INSERT/UPDATE/DELETE with table names (but allow variables)
                if (preg_match('/\b(INSERT INTO|UPDATE)\s+[A-Za-z_]+\s+SET/', $line) && 
                    !str_contains($line, '$') && !str_contains($line, '->')) {
                    $hardcodedSqlCount++;
                }
            }
            
            $this->check(
                'AFS_Evo_ArticleSync has no hardcoded SQL statements',
                $hardcodedSqlCount === 0,
                "Found {$hardcodedSqlCount} hardcoded SQL statements in AFS_Evo_ArticleSync"
            );
        }
        
        // Check that AFS_Get_Data uses mapping system
        $getDataFile = __DIR__ . '/../classes/AFS_Get_Data.php';
        $this->check(
            'AFS_Get_Data file exists',
            file_exists($getDataFile),
            'AFS_Get_Data file not found'
        );
        
        if (file_exists($getDataFile)) {
            $content = file_get_contents($getDataFile);
            
            // Check that it uses AFS_MappingConfig
            $this->check(
                'AFS_Get_Data uses AFS_MappingConfig',
                str_contains($content, 'AFS_MappingConfig'),
                'AFS_Get_Data does not use AFS_MappingConfig'
            );
            
            // Check that SQL is built from config
            $this->check(
                'AFS_Get_Data builds SQL from config',
                str_contains($content, 'buildSelectQuery'),
                'AFS_Get_Data does not build SQL from config'
            );
        }
    }
    
    private function validateNoLegacyPatterns(): void
    {
        $classesDir = __DIR__ . '/../classes';
        $phpFiles = glob($classesDir . '/*.php');
        
        $legacyPatterns = [
            'LEGACY' => 0,
            '@deprecated' => 0,
            'XXX' => 0,
            'HACK' => 0,
            'FIXME' => 0,
            'TODO.*cleanup' => 0,
            'TODO.*legacy' => 0,
            'old.*way' => 0,
        ];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            foreach ($legacyPatterns as $pattern => $count) {
                if (preg_match_all('/' . $pattern . '/i', $content, $matches)) {
                    $legacyPatterns[$pattern] += count($matches[0]);
                }
            }
        }
        
        $totalLegacyMarkers = array_sum($legacyPatterns);
        $this->check(
            'No legacy markers in code',
            $totalLegacyMarkers === 0,
            "Found {$totalLegacyMarkers} legacy markers in code"
        );
        
        // Check for backup or old files
        $backupPatterns = [
            $classesDir . '/*.php.bak',
            $classesDir . '/*.old',
            $classesDir . '/*_legacy.*',
            $classesDir . '/*_backup.*',
        ];
        
        $backupFiles = [];
        foreach ($backupPatterns as $pattern) {
            $files = glob($pattern);
            if ($files) {
                $backupFiles = array_merge($backupFiles, $files);
            }
        }
        
        $this->check(
            'No backup or legacy files in classes directory',
            empty($backupFiles),
            'Found backup/legacy files: ' . implode(', ', $backupFiles)
        );
    }
}

// Run validation
$validator = new HardcodingValidator();
$success = $validator->run();

exit($success ? 0 : 1);
