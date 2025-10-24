#!/usr/bin/env php
<?php
/**
 * Test script to validate AFS_Get_Data YAML-based refactoring
 * 
 * This script tests the new YAML-based configuration loading and
 * SQL query generation to ensure backward compatibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Get_Data YAML Configuration Test ===\n\n";

// Test 1: Load YAML Configuration
echo "Test 1: Loading YAML Configuration...\n";
try {
    $configPath = __DIR__ . '/../mappings/source_afs.yml';
    $config = new AFS_MappingConfig($configPath);
    echo "✓ YAML configuration loaded successfully\n\n";
} catch (Exception $e) {
    echo "✗ Failed to load YAML configuration: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Check Entities
echo "Test 2: Checking Entities...\n";
$entities = $config->getEntities();
$expectedEntities = ['Artikel', 'Warengruppe', 'Dokumente'];
foreach ($expectedEntities as $entity) {
    if (isset($entities[$entity])) {
        echo "✓ Entity '{$entity}' found\n";
    } else {
        echo "✗ Entity '{$entity}' not found\n";
        exit(1);
    }
}
echo "\n";

// Test 3: Generate SQL Queries
echo "Test 3: Generating SQL Queries...\n";
foreach ($expectedEntities as $entity) {
    try {
        $sql = $config->buildSelectQuery($entity);
        echo "✓ SQL query for '{$entity}':\n";
        // Show first 100 chars
        $preview = substr(str_replace("\n", " ", $sql), 0, 100);
        echo "  {$preview}...\n";
    } catch (Exception $e) {
        echo "✗ Failed to generate SQL for '{$entity}': " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\n";

// Test 4: Check Field Mappings
echo "Test 4: Checking Field Mappings...\n";
$artikelFields = $config->getFields('Artikel');
$requiredArtikelFields = ['Artikel', 'Artikelnummer', 'Bezeichnung', 'Preis', 'Online'];
foreach ($requiredArtikelFields as $field) {
    if (isset($artikelFields[$field])) {
        $source = $artikelFields[$field]['source'] ?? $field;
        $type = $artikelFields[$field]['type'] ?? 'string';
        echo "✓ Field '{$field}' -> source: '{$source}', type: '{$type}'\n";
    } else {
        echo "✗ Required field '{$field}' not found in Artikel entity\n";
        exit(1);
    }
}
echo "\n";

// Test 5: Test TransformRegistry
echo "Test 5: Testing TransformRegistry...\n";
$registry = new \Mapping\TransformRegistry();

// Test basename transformation
$testPathWin = 'C:\\Windows\\Path\\To\\File.jpg';
$resultWin = $registry->apply('basename', $testPathWin);
if ($resultWin === 'File.jpg') {
    echo "✓ basename transformation works for Windows path: '{$testPathWin}' -> '{$resultWin}'\n";
} else {
    echo "✗ basename transformation failed for Windows path: expected 'File.jpg', got '{$resultWin}'\n";
    exit(1);
}

// Also test Unix-style path
$testPathUnix = '/usr/local/share/File.jpg';
$resultUnix = $registry->apply('basename', $testPathUnix);
if ($resultUnix === 'File.jpg') {
    echo "✓ basename transformation works for Unix path: '{$testPathUnix}' -> '{$resultUnix}'\n";
} else {
    echo "✗ basename transformation failed for Unix path: expected 'File.jpg', got '{$resultUnix}'\n";
    exit(1);
}
// Test trim transformation
$testString = '  spaced string  ';
$result = $registry->apply('trim', $testString);
if ($result === 'spaced string') {
    echo "✓ trim transformation works\n";
} else {
    echo "✗ trim transformation failed\n";
    exit(1);
}

// Test normalize_title transformation
$testTitle = 'C:\\Path\\To\\Document.pdf';
$result = $registry->apply('normalize_title', $testTitle);
if ($result === 'Document.pdf') {
    echo "✓ normalize_title transformation works\n";
} else {
    echo "✗ normalize_title transformation failed: expected 'Document.pdf', got '{$result}'\n";
    exit(1);
}

echo "\n";

// Test 6: Compare generated SQL with original hardcoded SQL
echo "Test 6: Comparing Generated SQL with Original Logic...\n";

$artikelSQL = $config->buildSelectQuery('Artikel');

// Check for key field mappings in Artikel query
$artikelChecks = [
    'Artikel' => '[Artikel]',
    'Preis' => '[VK3] AS [Preis]',
    'Online' => '[Internet] AS [Online]',
    'last_update' => '[Update] AS [last_update]',
    'Bild1' => '[Bild1]',
];

foreach ($artikelChecks as $name => $expected) {
    if (strpos($artikelSQL, $expected) !== false) {
        echo "✓ Field mapping for '{$name}' is correct\n";
    } else {
        echo "✗ Field mapping for '{$name}' is incorrect or missing\n";
        echo "  Expected to find: {$expected}\n";
        exit(1);
    }
}

echo "\n✓ All tests passed!\n";
echo "\n=== Test Summary ===\n";
echo "✓ YAML configuration loading\n";
echo "✓ Entity definitions\n";
echo "✓ SQL query generation\n";
echo "✓ Field mappings\n";
echo "✓ Transformation registry\n";
echo "✓ Backward compatibility\n";
echo "\nRefactoring appears to be functionally equivalent to the original implementation.\n";

exit(0);
