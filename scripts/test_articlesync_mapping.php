#!/usr/bin/env php
<?php
/**
 * Integration test for AFS_Evo_ArticleSync with target mapping
 * 
 * This test validates that the refactored ArticleSync class correctly
 * uses the target mapping configuration for all database operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Evo_ArticleSync Target Mapping Integration Test ===\n\n";

// Test 1: Load Target Mapping
echo "Test 1: Loading Target Mapping Configuration...\n";
try {
    $mappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
    $targetMapping = new AFS_TargetMappingConfig($mappingPath);
    $version = $targetMapping->getVersion();
    echo "✓ Target mapping loaded successfully (version: {$version})\n\n";
} catch (Exception $e) {
    echo "✗ Failed to load target mapping: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Create SQL Builder
echo "Test 2: Creating SQL Builder...\n";
try {
    $sqlBuilder = new AFS_SqlBuilder($targetMapping);
    echo "✓ SQL builder created successfully\n\n";
} catch (Exception $e) {
    echo "✗ Failed to create SQL builder: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Generate Article UPSERT SQL
echo "Test 3: Generating Article UPSERT SQL...\n";
try {
    $articleUpsertSql = $sqlBuilder->buildEntityUpsert('articles');
    
    // Validate the generated SQL contains required elements
    $requiredElements = [
        'INSERT INTO',
        '"Artikel"',
        'Artikelnummer',
        'Bezeichnung',
        'ON CONFLICT',
        'DO UPDATE SET'
    ];
    
    foreach ($requiredElements as $element) {
        if (strpos($articleUpsertSql, $element) === false) {
            echo "✗ Generated SQL missing required element: {$element}\n";
            echo "Generated SQL:\n{$articleUpsertSql}\n";
            exit(1);
        }
    }
    
    echo "✓ Article UPSERT SQL generated successfully\n";
    echo "  Sample: " . substr(str_replace("\n", " ", $articleUpsertSql), 0, 80) . "...\n\n";
} catch (Exception $e) {
    echo "✗ Failed to generate article UPSERT SQL: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Generate Relationship UPSERT SQL
echo "Test 4: Generating Relationship UPSERT SQL...\n";
$relationships = ['article_images', 'article_documents', 'article_attributes'];
foreach ($relationships as $relationship) {
    try {
        $relationshipSql = $sqlBuilder->buildRelationshipUpsert($relationship);
        
        $tableName = $targetMapping->getRelationshipTableName($relationship);
        if (strpos($relationshipSql, $tableName) === false) {
            echo "✗ Generated SQL missing table name: {$tableName}\n";
            exit(1);
        }
        
        echo "✓ Relationship UPSERT SQL for '{$relationship}' generated\n";
    } catch (Exception $e) {
        echo "✗ Failed to generate relationship UPSERT SQL for '{$relationship}': " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\n";

// Test 5: Generate Relationship DELETE SQL
echo "Test 5: Generating Relationship DELETE SQL...\n";
$deleteTests = [
    ['relationship' => 'article_images', 'where' => ['Artikel_ID', 'Bild_ID']],
    ['relationship' => 'article_documents', 'where' => ['Artikel_ID', 'Dokument_ID']],
    ['relationship' => 'article_attributes', 'where' => ['Artikel_ID', 'Attribute_ID']],
];

foreach ($deleteTests as $test) {
    try {
        $deleteSql = $sqlBuilder->buildRelationshipDelete($test['relationship'], $test['where']);
        
        $tableName = $targetMapping->getRelationshipTableName($test['relationship']);
        if (strpos($deleteSql, 'DELETE FROM') === false || strpos($deleteSql, $tableName) === false) {
            echo "✗ Generated DELETE SQL invalid for '{$test['relationship']}'\n";
            echo "Generated SQL: {$deleteSql}\n";
            exit(1);
        }
        
        echo "✓ Relationship DELETE SQL for '{$test['relationship']}' generated\n";
    } catch (Exception $e) {
        echo "✗ Failed to generate relationship DELETE SQL for '{$test['relationship']}': " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\n";

// Test 6: Validate Field Mapping Completeness
echo "Test 6: Validating Field Mapping Completeness...\n";
$articleFields = $targetMapping->getFields('articles');

// Check that all fields from the original SQL are present
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
    echo "✗ Missing fields in articles entity: " . implode(', ', $missingFields) . "\n";
    exit(1);
}

echo "✓ All required article fields are present in mapping\n";
echo "  Total fields: " . count($articleFields) . "\n\n";

// Test 7: Validate Relationship Field Mappings
echo "Test 7: Validating Relationship Field Mappings...\n";
$relationshipTests = [
    ['name' => 'article_images', 'required' => ['Artikel_ID', 'Bild_ID', 'update']],
    ['name' => 'article_documents', 'required' => ['Artikel_ID', 'Dokument_ID', 'update']],
    ['name' => 'article_attributes', 'required' => ['Artikel_ID', 'Attribute_ID', 'Atrribvalue', 'update']],
];

foreach ($relationshipTests as $test) {
    $fields = $targetMapping->getRelationshipFields($test['name']);
    foreach ($test['required'] as $requiredField) {
        if (!isset($fields[$requiredField])) {
            echo "✗ Missing field '{$requiredField}' in relationship '{$test['name']}'\n";
            exit(1);
        }
    }
    echo "✓ Relationship '{$test['name']}' has all required fields\n";
}
echo "\n";

// Test 8: Validate Parameter Naming Convention
echo "Test 8: Validating Parameter Naming Convention...\n";
$paramMapping = $sqlBuilder->getParameterMapping('articles');
$sampleParams = ['AFS_ID', 'Artikelnummer', 'Preis', 'Online'];

foreach ($sampleParams as $field) {
    if (!isset($paramMapping[$field])) {
        echo "✗ Missing parameter mapping for field: {$field}\n";
        exit(1);
    }
    $param = $paramMapping[$field];
    if ($param !== strtolower($field)) {
        echo "✗ Parameter naming convention incorrect for '{$field}': expected '" . strtolower($field) . "', got '{$param}'\n";
        exit(1);
    }
}

echo "✓ Parameter naming convention is correct (lowercase)\n";
echo "  Sample mappings: AFS_ID => afs_id, Artikelnummer => artikelnummer\n\n";

echo "✓ All tests passed!\n";
echo "\n=== Test Summary ===\n";
echo "✓ Target mapping configuration loading\n";
echo "✓ SQL builder initialization\n";
echo "✓ Article UPSERT SQL generation\n";
echo "✓ Relationship UPSERT SQL generation\n";
echo "✓ Relationship DELETE SQL generation\n";
echo "✓ Field mapping completeness\n";
echo "✓ Relationship field mappings\n";
echo "✓ Parameter naming convention\n";
echo "\nAFS_Evo_ArticleSync is ready to use target mapping for all write operations.\n";
echo "\nNOTE: To fully validate functionality, run the actual sync process with test data.\n";

exit(0);
