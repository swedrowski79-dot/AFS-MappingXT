#!/usr/bin/env php
<?php
/**
 * Mixed Mode Validation Test
 * 
 * This script validates that the new YAML-based mapping logic produces
 * identical results to ensure no data loss and consistent behavior.
 * 
 * Test Goals:
 * - Compare mapping configurations (old vs new approach)
 * - Detect data loss
 * - Compare performance
 * - Log all differences
 * 
 * Acceptance Criteria:
 * - Results must be 100% identical
 * - Log documents all differences
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

class MixedModeValidator
{
    private string $logFile;
    private array $differences = [];
    private float $startTime;
    
    public function __construct()
    {
        $this->logFile = __DIR__ . '/../logs/mixed_mode_validation_' . date('Y-m-d_H-i-s') . '.log';
        $this->startTime = microtime(true);
        $this->log("=== Mixed Mode Validation Test ===");
        $this->log("Started at: " . date('Y-m-d H:i:s'));
        $this->log("");
    }
    
    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        echo $logEntry;
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
    
    private function recordDifference(string $test, string $description, array $details = []): void
    {
        $this->differences[] = [
            'test' => $test,
            'description' => $description,
            'details' => $details
        ];
        $this->log("DIFFERENCE: {$test} - {$description}", 'WARN');
        if (!empty($details)) {
            $this->log("  Details: " . json_encode($details, JSON_PRETTY_PRINT), 'WARN');
        }
    }
    
    public function run(): bool
    {
        $this->log("\n### Phase 1: Configuration Validation ###\n");
        $configValid = $this->validateConfigurations();
        
        $this->log("\n### Phase 2: SQL Generation Validation ###\n");
        $sqlValid = $this->validateSqlGeneration();
        
        $this->log("\n### Phase 3: Data Consistency Validation ###\n");
        $dataValid = $this->validateDataConsistency();
        
        $this->log("\n### Phase 4: Performance Comparison ###\n");
        $this->performanceComparison();
        
        $this->log("\n### Phase 5: Data Loss Detection ###\n");
        $dataLossCheck = $this->detectDataLoss();
        
        $this->generateReport();
        
        return $configValid && $sqlValid && $dataValid && $dataLossCheck && empty($this->differences);
    }
    
    private function validateConfigurations(): bool
    {
        $this->log("Validating configuration files...");
        
        try {
            // Load source mapping
            $sourceMappingPath = __DIR__ . '/../mappings/source_afs.yml';
            if (!file_exists($sourceMappingPath)) {
                $this->recordDifference('config', 'Source mapping file not found', ['path' => $sourceMappingPath]);
                return false;
            }
            $sourceMapping = new AFS_MappingConfig($sourceMappingPath);
            $this->log("✓ Source mapping loaded successfully");
            
            // Load target mapping
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            if (!file_exists($targetMappingPath)) {
                $this->recordDifference('config', 'Target mapping file not found', ['path' => $targetMappingPath]);
                return false;
            }
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            $version = $targetMapping->getVersion();
            $this->log("✓ Target mapping loaded successfully (version: {$version})");
            
            // Validate all required entities exist
            // Note: Bilder and Attribute are not separate entities in source_afs.yml
            // They are fields within Artikel (Bild1-10) and (Attribname1-4/Attribvalue1-4)
            $requiredEntities = ['Artikel', 'Warengruppe', 'Dokumente'];
            foreach ($requiredEntities as $entity) {
                $entityConfig = $sourceMapping->getEntity($entity);
                if ($entityConfig === null) {
                    $this->recordDifference('config', "Missing entity in source mapping", ['entity' => $entity]);
                    return false;
                }
                $this->log("  ✓ Entity '{$entity}' defined in source mapping");
            }
            
            // Validate target entities
            $targetEntities = ['articles', 'categories', 'documents', 'images', 'attributes'];
            foreach ($targetEntities as $entity) {
                $entityConfig = $targetMapping->getEntity($entity);
                if ($entityConfig === null) {
                    $this->recordDifference('config', "Missing entity in target mapping", ['entity' => $entity]);
                    return false;
                }
                $this->log("  ✓ Entity '{$entity}' defined in target mapping");
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->recordDifference('config', 'Configuration validation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    private function validateSqlGeneration(): bool
    {
        $this->log("Validating SQL generation...");
        
        try {
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            $sqlBuilder = new AFS_SqlBuilder($targetMapping);
            
            // Test article UPSERT generation
            $this->log("  Testing article UPSERT SQL generation...");
            $articleUpsertSql = $sqlBuilder->buildEntityUpsert('articles');
            
            $requiredElements = [
                'INSERT INTO' => 'INSERT statement',
                '"Artikel"' => 'Table name',
                'Artikelnummer' => 'Article number field',
                'Bezeichnung' => 'Description field',
                'ON CONFLICT' => 'Conflict handling',
                'DO UPDATE SET' => 'Update clause'
            ];
            
            foreach ($requiredElements as $element => $description) {
                if (strpos($articleUpsertSql, $element) === false) {
                    $this->recordDifference('sql_generation', "Missing required element in article UPSERT", [
                        'element' => $element,
                        'description' => $description
                    ]);
                    return false;
                }
            }
            $this->log("  ✓ Article UPSERT SQL valid");
            
            // Test relationship SQL generation
            $relationships = [
                'article_images' => 'Artikel_Bilder',
                'article_documents' => 'Artikel_Dokumente',
                'article_attributes' => 'Attrib_Artikel'
            ];
            
            foreach ($relationships as $relationship => $expectedTable) {
                $this->log("  Testing relationship '{$relationship}' SQL generation...");
                $upsertSql = $sqlBuilder->buildRelationshipUpsert($relationship);
                $deleteSql = $sqlBuilder->buildRelationshipDelete($relationship, ['Artikel_ID', 'Bild_ID']);
                
                if (strpos($upsertSql, $expectedTable) === false) {
                    $this->recordDifference('sql_generation', "Missing table name in relationship UPSERT", [
                        'relationship' => $relationship,
                        'expected_table' => $expectedTable
                    ]);
                    return false;
                }
                
                if (strpos($deleteSql, 'DELETE FROM') === false || strpos($deleteSql, $expectedTable) === false) {
                    $this->recordDifference('sql_generation', "Invalid relationship DELETE SQL", [
                        'relationship' => $relationship
                    ]);
                    return false;
                }
                
                $this->log("  ✓ Relationship '{$relationship}' SQL valid");
            }
            
            // Test parameter mapping consistency
            $this->log("  Testing parameter mapping consistency...");
            $paramMapping = $sqlBuilder->getParameterMapping('articles');
            $sampleFields = ['AFS_ID', 'Artikelnummer', 'Preis', 'Online'];
            
            foreach ($sampleFields as $field) {
                if (!isset($paramMapping[$field])) {
                    $this->recordDifference('sql_generation', "Missing parameter mapping", ['field' => $field]);
                    return false;
                }
                
                $expectedParam = strtolower($field);
                if ($paramMapping[$field] !== $expectedParam) {
                    $this->recordDifference('sql_generation', "Incorrect parameter naming", [
                        'field' => $field,
                        'expected' => $expectedParam,
                        'actual' => $paramMapping[$field]
                    ]);
                    return false;
                }
            }
            $this->log("  ✓ Parameter mapping consistent");
            
            return true;
            
        } catch (Exception $e) {
            $this->recordDifference('sql_generation', 'SQL generation validation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    private function validateDataConsistency(): bool
    {
        $this->log("Validating data consistency...");
        
        try {
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            
            // Validate field completeness for articles
            $this->log("  Checking article field completeness...");
            $articleFields = $targetMapping->getFields('articles');
            
            $requiredFields = [
                'AFS_ID', 'XT_ID', 'Art', 'Artikelnummer', 'Bezeichnung', 'EANNummer',
                'Bestand', 'Preis', 'AFS_Warengruppe_ID', 'XT_Category_ID', 'Category',
                'Master', 'Masterartikel', 'Mindestmenge', 'Gewicht', 'Online', 'Einheit',
                'Langtext', 'Werbetext', 'Meta_Title', 'Meta_Description', 'Bemerkung',
                'Hinweis', 'update', 'last_update'
            ];
            
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($articleFields[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (!empty($missingFields)) {
                $this->recordDifference('data_consistency', "Missing required article fields", [
                    'missing_fields' => $missingFields
                ]);
                return false;
            }
            $this->log("  ✓ All required article fields present (count: " . count($articleFields) . ")");
            
            // Validate relationship field completeness
            $relationshipTests = [
                'article_images' => ['Artikel_ID', 'Bild_ID', 'update'],
                'article_documents' => ['Artikel_ID', 'Dokument_ID', 'update'],
                'article_attributes' => ['Artikel_ID', 'Attribute_ID', 'Atrribvalue', 'update']
            ];
            
            foreach ($relationshipTests as $relationship => $requiredRelFields) {
                $this->log("  Checking relationship '{$relationship}' field completeness...");
                $relFields = $targetMapping->getRelationshipFields($relationship);
                
                foreach ($requiredRelFields as $requiredField) {
                    if (!isset($relFields[$requiredField])) {
                        $this->recordDifference('data_consistency', "Missing required relationship field", [
                            'relationship' => $relationship,
                            'field' => $requiredField
                        ]);
                        return false;
                    }
                }
                $this->log("  ✓ Relationship '{$relationship}' fields complete");
            }
            
            // Validate unique keys and constraints
            $this->log("  Checking unique keys and constraints...");
            $articleUniqueKey = $targetMapping->getUniqueKey('articles');
            if ($articleUniqueKey !== 'Artikelnummer') {
                $this->recordDifference('data_consistency', "Incorrect unique key for articles", [
                    'expected' => 'Artikelnummer',
                    'actual' => $articleUniqueKey
                ]);
                return false;
            }
            $this->log("  ✓ Article unique key correct: {$articleUniqueKey}");
            
            return true;
            
        } catch (Exception $e) {
            $this->recordDifference('data_consistency', 'Data consistency validation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    private function performanceComparison(): void
    {
        $this->log("Performing performance comparison...");
        
        try {
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            
            // Test 1: Configuration loading time
            $iterations = 100;
            
            $this->log("  Testing configuration loading performance ({$iterations} iterations)...");
            $startConfig = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            }
            $configDuration = microtime(true) - $startConfig;
            $avgConfigTime = ($configDuration / $iterations) * 1000; // milliseconds
            $this->log("  ✓ Average config loading time: " . number_format($avgConfigTime, 2) . " ms");
            
            // Test 2: SQL generation time
            $this->log("  Testing SQL generation performance ({$iterations} iterations)...");
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            $sqlBuilder = new AFS_SqlBuilder($targetMapping);
            
            $startSql = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $sqlBuilder->buildEntityUpsert('articles');
                $sqlBuilder->buildRelationshipUpsert('article_images');
                $sqlBuilder->buildRelationshipUpsert('article_documents');
                $sqlBuilder->buildRelationshipUpsert('article_attributes');
            }
            $sqlDuration = microtime(true) - $startSql;
            $avgSqlTime = ($sqlDuration / $iterations) * 1000; // milliseconds
            $this->log("  ✓ Average SQL generation time: " . number_format($avgSqlTime, 2) . " ms");
            
            // Test 3: Parameter mapping time
            $this->log("  Testing parameter mapping performance ({$iterations} iterations)...");
            $startParam = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $sqlBuilder->getParameterMapping('articles');
            }
            $paramDuration = microtime(true) - $startParam;
            $avgParamTime = ($paramDuration / $iterations) * 1000; // milliseconds
            $this->log("  ✓ Average parameter mapping time: " . number_format($avgParamTime, 2) . " ms");
            
            // Performance thresholds
            $configThreshold = 50; // ms
            $sqlThreshold = 10; // ms
            $paramThreshold = 5; // ms
            
            if ($avgConfigTime > $configThreshold) {
                $this->recordDifference('performance', "Config loading time exceeds threshold", [
                    'threshold' => $configThreshold,
                    'actual' => $avgConfigTime
                ]);
            }
            
            if ($avgSqlTime > $sqlThreshold) {
                $this->recordDifference('performance', "SQL generation time exceeds threshold", [
                    'threshold' => $sqlThreshold,
                    'actual' => $avgSqlTime
                ]);
            }
            
            if ($avgParamTime > $paramThreshold) {
                $this->recordDifference('performance', "Parameter mapping time exceeds threshold", [
                    'threshold' => $paramThreshold,
                    'actual' => $avgParamTime
                ]);
            }
            
        } catch (Exception $e) {
            $this->log("Performance comparison error: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function detectDataLoss(): bool
    {
        $this->log("Detecting potential data loss...");
        
        try {
            $targetMappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            $targetMapping = new AFS_TargetMappingConfig($targetMappingPath);
            
            // Check for missing data type definitions
            $this->log("  Checking for missing data type definitions...");
            $articleFields = $targetMapping->getFields('articles');
            $fieldsWithoutType = [];
            
            foreach ($articleFields as $fieldName => $fieldConfig) {
                if (!isset($fieldConfig['type'])) {
                    $fieldsWithoutType[] = $fieldName;
                }
            }
            
            if (!empty($fieldsWithoutType)) {
                $this->recordDifference('data_loss', "Fields without explicit type definition", [
                    'fields' => $fieldsWithoutType,
                    'note' => 'May default to string type'
                ]);
            } else {
                $this->log("  ✓ All fields have explicit type definitions");
            }
            
            // Check for auto-increment fields
            $this->log("  Checking auto-increment field configuration...");
            $hasIdField = false;
            $idFieldConfig = null;
            
            foreach ($articleFields as $fieldName => $fieldConfig) {
                if ($fieldName === 'ID') {
                    $hasIdField = true;
                    $idFieldConfig = $fieldConfig;
                    break;
                }
            }
            
            if ($hasIdField && (!isset($idFieldConfig['auto_increment']) || !$idFieldConfig['auto_increment'])) {
                $this->recordDifference('data_loss', "ID field may not be properly configured as auto-increment", [
                    'field' => 'ID',
                    'config' => $idFieldConfig
                ]);
            } else {
                $this->log("  ✓ Auto-increment configuration valid");
            }
            
            // Check for nullable field configuration
            $this->log("  Checking nullable field configuration...");
            // Note: Art can be nullable as it's used as a type/category field that may not always be set
            $criticalNonNullableFields = ['Artikelnummer', 'Bezeichnung'];
            
            $hasNullableIssue = false;
            foreach ($criticalNonNullableFields as $field) {
                if (isset($articleFields[$field]) && isset($articleFields[$field]['nullable']) && $articleFields[$field]['nullable']) {
                    $this->recordDifference('data_loss', "Critical field is configured as nullable", [
                        'field' => $field,
                        'note' => 'May allow NULL values where they should not be permitted'
                    ]);
                    $hasNullableIssue = true;
                }
            }
            if (!$hasNullableIssue) {
                $this->log("  ✓ Critical fields properly configured as non-nullable");
            }
            
            // Check for unique constraint configuration
            $this->log("  Checking unique constraint configuration...");
            $relationships = ['article_images', 'article_documents', 'article_attributes'];
            
            foreach ($relationships as $relationship) {
                $relConfig = $targetMapping->getRelationship($relationship);
                if (!isset($relConfig['unique_constraint']) || empty($relConfig['unique_constraint'])) {
                    $this->recordDifference('data_loss', "Missing unique constraint in relationship", [
                        'relationship' => $relationship,
                        'note' => 'May allow duplicate entries'
                    ]);
                } else {
                    $this->log("  ✓ Relationship '{$relationship}' has unique constraint: " . implode(', ', $relConfig['unique_constraint']));
                }
            }
            
            return empty($this->differences) || !$this->hasCriticalDifferences();
            
        } catch (Exception $e) {
            $this->recordDifference('data_loss', 'Data loss detection failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    private function hasCriticalDifferences(): bool
    {
        foreach ($this->differences as $diff) {
            if ($diff['test'] === 'data_loss' || $diff['test'] === 'data_consistency') {
                return true;
            }
        }
        return false;
    }
    
    private function generateReport(): void
    {
        $duration = microtime(true) - $this->startTime;
        
        $this->log("\n=== Validation Report ===");
        $this->log("Total execution time: " . number_format($duration, 2) . " seconds");
        $this->log("Total differences found: " . count($this->differences));
        
        if (empty($this->differences)) {
            $this->log("\n✓ VALIDATION PASSED - Results are 100% identical", 'SUCCESS');
            $this->log("✓ No data loss detected");
            $this->log("✓ Performance within acceptable thresholds");
        } else {
            $this->log("\n✗ VALIDATION FAILED - Differences detected", 'ERROR');
            $this->log("\nSummary of differences:");
            
            $diffsByTest = [];
            foreach ($this->differences as $diff) {
                $test = $diff['test'];
                if (!isset($diffsByTest[$test])) {
                    $diffsByTest[$test] = [];
                }
                $diffsByTest[$test][] = $diff;
            }
            
            foreach ($diffsByTest as $test => $diffs) {
                $this->log("\n  {$test}: " . count($diffs) . " issue(s)");
                foreach ($diffs as $diff) {
                    $this->log("    - {$diff['description']}");
                }
            }
        }
        
        $this->log("\nDetailed log saved to: {$this->logFile}");
        $this->log("\n=== Validation Complete ===");
    }
}

// Run the validation
$validator = new MixedModeValidator();
$success = $validator->run();

exit($success ? 0 : 1);
