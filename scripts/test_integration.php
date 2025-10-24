#!/usr/bin/env php
<?php
/**
 * Integration test for AFS_Get_Data with mock database
 * 
 * This test validates that AFS_Get_Data works correctly with the new
 * YAML-based configuration by simulating database calls and checking
 * that the output format matches expectations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_Get_Data Integration Test ===\n\n";

/**
 * Mock MSSQL class that returns test data
 */
class MockMSSQL
{
    public function fetchAll(string $sql, array $params = []): array
    {
        // Detect which query is being run based on table name
        if (strpos($sql, 'FROM [Artikel]') !== false) {
            return [
                [
                    'Artikel' => '12345',
                    'Art' => '1',
                    'Artikelnummer' => 'ART-001',
                    'Bezeichnung' => 'Test Artikel',
                    'EANNummer' => '1234567890123',
                    'Bestand' => '100',
                    'Bild1' => 'C:\\Images\\Product\\test1.jpg',
                    'Bild2' => 'C:\\Images\\Product\\test2.jpg',
                    'Bild3' => '',
                    'Bild4' => '',
                    'Bild5' => '',
                    'Bild6' => '',
                    'Bild7' => '',
                    'Bild8' => '',
                    'Bild9' => '',
                    'Bild10' => '',
                    'Preis' => '99,99',
                    'Warengruppe' => '5',
                    'Umsatzsteuer' => '19',
                    'Mindestmenge' => '1',
                    'Attribname1' => 'Farbe',
                    'Attribname2' => 'Größe',
                    'Attribname3' => '',
                    'Attribname4' => '',
                    'Attribvalue1' => 'Rot',
                    'Attribvalue2' => 'XL',
                    'Attribvalue3' => '',
                    'Attribvalue4' => '',
                    'Master' => '',
                    'Bruttogewicht' => '1,5',
                    'Online' => '1',
                    'Einheit' => 'Stück',
                    'Langtext' => 'Test Langtext',
                    'Werbetext1' => 'Test Werbetext',
                    'Bemerkung' => '<p>Test Bemerkung</p>',
                    'Hinweis' => '<strong>Test Hinweis</strong>',
                    'last_update' => '2024-01-15 10:30:00',
                ],
            ];
        } elseif (strpos($sql, 'FROM [Warengruppe]') !== false) {
            return [
                [
                    'Warengruppe' => '5',
                    'Art' => '1',
                    'Parent' => '0',
                    'Ebene' => '1',
                    'Bezeichnung' => 'Test Kategorie',
                    'Online' => '1',
                    'Bild' => 'D:\\Files\\Categories\\cat.jpg',
                    'Bild_gross' => 'D:\\Files\\Categories\\cat_large.jpg',
                    'Beschreibung' => 'Test Beschreibung',
                ],
            ];
        } elseif (strpos($sql, 'FROM [Dokument]') !== false) {
            return [
                [
                    'Zaehler' => '100',
                    'Artikel' => '12345',
                    'Dateiname' => 'E:\\Documents\\manual.pdf',
                    'Titel' => 'E:\\Documents\\Manuals\\User Manual.pdf',
                    'Art' => '2',
                ],
            ];
        }
        
        return [];
    }
}

// Test 1: Initialize AFS_Get_Data with mock database
echo "Test 1: Initializing AFS_Get_Data...\n";
try {
    $mockDb = new MockMSSQL();
    $afsData = new AFS_Get_Data($mockDb);
    echo "✓ AFS_Get_Data initialized successfully\n\n";
} catch (Exception $e) {
    echo "✗ Failed to initialize: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Get Artikel and validate structure
echo "Test 2: Testing getArtikel()...\n";
try {
    $artikel = $afsData->getArtikel();
    
    if (count($artikel) !== 1) {
        echo "✗ Expected 1 article, got " . count($artikel) . "\n";
        exit(1);
    }
    
    $art = $artikel[0];
    
    // Check field types
    $typeChecks = [
        'Artikel' => 'integer',
        'Art' => 'integer',
        'Artikelnummer' => 'string',
        'Preis' => 'double', // PHP's float is reported as double
        'Online' => 'boolean',
        'Bestand' => 'integer',
        'Warengruppe' => 'integer',
    ];
    
    foreach ($typeChecks as $field => $expectedType) {
        $actualType = gettype($art[$field]);
        if ($actualType !== $expectedType) {
            echo "✗ Field '{$field}' has wrong type: expected '{$expectedType}', got '{$actualType}'\n";
            exit(1);
        }
    }
    echo "✓ All field types are correct\n";
    
    // Check transformations
    if ($art['Bild1'] !== 'test1.jpg') {
        echo "✗ Bild1 basename transformation failed: expected 'test1.jpg', got '{$art['Bild1']}'\n";
        exit(1);
    }
    echo "✓ Basename transformation works for Bild1\n";
    
    if ($art['Bemerkung'] !== 'Test Bemerkung') {
        echo "✗ Bemerkung HTML removal failed: expected 'Test Bemerkung', got '{$art['Bemerkung']}'\n";
        exit(1);
    }
    echo "✓ HTML removal works for Bemerkung\n";
    
    // Check boolean conversion
    if ($art['Online'] !== true) {
        echo "✗ Online boolean conversion failed\n";
        exit(1);
    }
    echo "✓ Boolean conversion works\n";
    
    // Check price conversion (99,99 -> 99.99)
    if (abs($art['Preis'] - 99.99) > 0.01) {
        echo "✗ Price conversion failed: expected 99.99, got {$art['Preis']}\n";
        exit(1);
    }
    echo "✓ Price conversion works\n";
    
    echo "\n";
} catch (Exception $e) {
    echo "✗ getArtikel() failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Get Warengruppen and validate structure
echo "Test 3: Testing getWarengruppen()...\n";
try {
    $warengruppen = $afsData->getWarengruppen();
    
    if (count($warengruppen) !== 1) {
        echo "✗ Expected 1 category, got " . count($warengruppen) . "\n";
        exit(1);
    }
    
    $wg = $warengruppen[0];
    
    // Check field types
    if (!is_int($wg['Warengruppe'])) {
        echo "✗ Warengruppe should be integer\n";
        exit(1);
    }
    echo "✓ Field types are correct\n";
    
    // Check basename transformation
    if ($wg['Bild'] !== 'cat.jpg') {
        echo "✗ Bild basename transformation failed: expected 'cat.jpg', got '{$wg['Bild']}'\n";
        exit(1);
    }
    echo "✓ Basename transformation works for Bild\n";
    
    // Check AFS_ID backward compatibility field
    if (!isset($wg['AFS_ID']) || $wg['AFS_ID'] !== 5) {
        echo "✗ AFS_ID backward compatibility field missing or incorrect\n";
        exit(1);
    }
    echo "✓ AFS_ID backward compatibility field present\n";
    
    echo "\n";
} catch (Exception $e) {
    echo "✗ getWarengruppen() failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Get Dokumente and validate structure
echo "Test 4: Testing getDokumente()...\n";
try {
    $dokumente = $afsData->getDokumente();
    
    if (count($dokumente) !== 1) {
        echo "✗ Expected 1 document, got " . count($dokumente) . "\n";
        exit(1);
    }
    
    $dok = $dokumente[0];
    
    // Check field types
    if (!is_int($dok['Zaehler']) || !is_int($dok['Artikel'])) {
        echo "✗ Zaehler and Artikel should be integers\n";
        exit(1);
    }
    echo "✓ Field types are correct\n";
    
    // Check basename transformation on Dateiname
    if ($dok['Dateiname'] !== 'manual.pdf') {
        echo "✗ Dateiname basename transformation failed: expected 'manual.pdf', got '{$dok['Dateiname']}'\n";
        exit(1);
    }
    echo "✓ Basename transformation works for Dateiname\n";
    
    // Check normalize_title transformation on Titel
    if ($dok['Titel'] !== 'User Manual.pdf') {
        echo "✗ Titel normalization failed: expected 'User Manual.pdf', got '{$dok['Titel']}'\n";
        exit(1);
    }
    echo "✓ Title normalization works\n";
    
    echo "\n";
} catch (Exception $e) {
    echo "✗ getDokumente() failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "✓ All integration tests passed!\n\n";
echo "=== Integration Test Summary ===\n";
echo "✓ AFS_Get_Data initialization\n";
echo "✓ getArtikel() returns correct structure and types\n";
echo "✓ getWarengruppen() returns correct structure and types\n";
echo "✓ getDokumente() returns correct structure and types\n";
echo "✓ Field transformations work correctly\n";
echo "✓ Type conversions work correctly\n";
echo "✓ Backward compatibility maintained\n";
echo "\nThe refactored implementation maintains full functional compatibility.\n";

exit(0);
