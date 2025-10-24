#!/usr/bin/env php
<?php
/**
 * Test script to validate AFS_TargetMappingConfig
 * 
 * This script tests the target mapping configuration loading and
 * SQL generation for write operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_TargetMappingConfig Test ===\n\n";

// Test 1: Load Target YAML Configuration
echo "Test 1: Loading Target YAML Configuration...\n";
try {
    $configPath = __DIR__ . '/../mappings/target_sqlite.yml';
    $config = new AFS_TargetMappingConfig($configPath);
    echo "✓ Target YAML configuration loaded successfully\n\n";
} catch (Exception $e) {
    echo "✗ Failed to load target YAML configuration: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Check Version
echo "Test 2: Checking Mapping Version...\n";
$version = $config->getVersion();
if ($version !== null) {
    echo "✓ Mapping version: {$version}\n\n";
} else {
    echo "✗ Mapping version not found\n";
    exit(1);
}

// Test 3: Check Entity Tables
echo "Test 3: Checking Entity Tables...\n";
$entities = ['articles', 'categories', 'images', 'documents', 'attributes'];
foreach ($entities as $entity) {
    $tableName = $config->getTableName($entity);
    if ($tableName !== null) {
        echo "✓ Entity '{$entity}' -> table: '{$tableName}'\n";
    } else {
        echo "✗ Entity '{$entity}' not found\n";
        exit(1);
    }
}
echo "\n";

// Test 4: Check Relationship Tables
echo "Test 4: Checking Relationship Tables...\n";
$relationships = ['article_images', 'article_documents', 'article_attributes'];
foreach ($relationships as $relationship) {
    $tableName = $config->getRelationshipTableName($relationship);
    if ($tableName !== null) {
        echo "✓ Relationship '{$relationship}' -> table: '{$tableName}'\n";
    } else {
        echo "✗ Relationship '{$relationship}' not found\n";
        exit(1);
    }
}
echo "\n";

// Test 5: Check Article Fields
echo "Test 5: Checking Article Fields...\n";
$articleFields = $config->getFields('articles');
$requiredFields = ['ID', 'AFS_ID', 'Artikelnummer', 'Bezeichnung', 'Preis', 'Online', 'update'];
foreach ($requiredFields as $field) {
    if (isset($articleFields[$field])) {
        $type = $articleFields[$field]['type'] ?? 'unknown';
        echo "✓ Field '{$field}' found, type: '{$type}'\n";
    } else {
        echo "✗ Required field '{$field}' not found in articles entity\n";
        exit(1);
    }
}
echo "\n";

// Test 6: Build UPSERT Statement for Articles
echo "Test 6: Building UPSERT Statement for Articles...\n";
try {
    $upsertInfo = $config->buildUpsertStatement('articles');
    echo "✓ UPSERT statement built successfully\n";
    echo "  Table: {$upsertInfo['table']}\n";
    echo "  Unique Key: {$upsertInfo['unique_key']}\n";
    echo "  Insert Fields: " . count($upsertInfo['insert_fields']) . " fields\n";
    echo "  Update Fields: " . count($upsertInfo['update_fields']) . " fields\n";
    
    // Verify key fields are present
    if (!in_array('Artikelnummer', $upsertInfo['insert_fields'])) {
        echo "✗ Artikelnummer not in insert fields\n";
        exit(1);
    }
    if (!in_array('Bezeichnung', $upsertInfo['insert_fields'])) {
        echo "✗ Bezeichnung not in insert fields\n";
        exit(1);
    }
    echo "✓ Key fields present in UPSERT statement\n";
} catch (Exception $e) {
    echo "✗ Failed to build UPSERT statement: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// Test 7: Build UPSERT Statement for Relationship
echo "Test 7: Building UPSERT Statement for Relationships...\n";
try {
    $relationshipInfo = $config->buildRelationshipUpsertStatement('article_images');
    echo "✓ Relationship UPSERT statement built successfully\n";
    echo "  Table: {$relationshipInfo['table']}\n";
    echo "  Unique Constraint: " . implode(', ', $relationshipInfo['unique_constraint']) . "\n";
    echo "  Insert Fields: " . count($relationshipInfo['insert_fields']) . " fields\n";
    echo "  Update Fields: " . count($relationshipInfo['update_fields']) . " fields\n";
    
    // Verify key fields are present
    if (!in_array('Artikel_ID', $relationshipInfo['insert_fields'])) {
        echo "✗ Artikel_ID not in insert fields\n";
        exit(1);
    }
    if (!in_array('Bild_ID', $relationshipInfo['insert_fields'])) {
        echo "✗ Bild_ID not in insert fields\n";
        exit(1);
    }
    echo "✓ Key fields present in relationship UPSERT statement\n";
} catch (Exception $e) {
    echo "✗ Failed to build relationship UPSERT statement: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

echo "✓ All tests passed!\n";
echo "\n=== Test Summary ===\n";
echo "✓ Target YAML configuration loading\n";
echo "✓ Mapping version extraction\n";
echo "✓ Entity table mappings\n";
echo "✓ Relationship table mappings\n";
echo "✓ Article field definitions\n";
echo "✓ UPSERT statement generation\n";
echo "✓ Relationship UPSERT statement generation\n";
echo "\nTarget mapping configuration is ready for use.\n";

exit(0);
