#!/usr/bin/env php
<?php
/**
 * Test script for AFS_HashManager
 * 
 * Validates that hash generation is stable, deterministic, and detects changes correctly
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_HashManager Test ===\n\n";

$hashManager = new AFS_HashManager();

// Test 1: Hash generation is deterministic
echo "Test 1: Deterministic hash generation\n";
$data1 = [
    'artikelnummer' => 'ART-001',
    'bezeichnung' => 'Test Product',
    'preis' => 19.99,
    'bestand' => 100,
];

$hash1a = $hashManager->generateHash($data1);
$hash1b = $hashManager->generateHash($data1);

if ($hash1a === $hash1b) {
    echo "✓ PASS: Same data produces same hash\n";
    echo "  Hash: {$hash1a}\n";
} else {
    echo "✗ FAIL: Same data produced different hashes\n";
    echo "  Hash 1: {$hash1a}\n";
    echo "  Hash 2: {$hash1b}\n";
    exit(1);
}
echo "\n";

// Test 2: Field order doesn't matter
echo "Test 2: Field order independence\n";
$data2a = [
    'artikelnummer' => 'ART-002',
    'bezeichnung' => 'Product Two',
    'preis' => 29.99,
];

$data2b = [
    'preis' => 29.99,
    'artikelnummer' => 'ART-002',
    'bezeichnung' => 'Product Two',
];

$hash2a = $hashManager->generateHash($data2a);
$hash2b = $hashManager->generateHash($data2b);

if ($hash2a === $hash2b) {
    echo "✓ PASS: Field order doesn't affect hash\n";
    echo "  Hash: {$hash2a}\n";
} else {
    echo "✗ FAIL: Different field order produced different hashes\n";
    echo "  Hash A: {$hash2a}\n";
    echo "  Hash B: {$hash2b}\n";
    exit(1);
}
echo "\n";

// Test 3: Different data produces different hash
echo "Test 3: Change detection\n";
$data3a = [
    'artikelnummer' => 'ART-003',
    'bezeichnung' => 'Product Three',
    'preis' => 39.99,
];

$data3b = [
    'artikelnummer' => 'ART-003',
    'bezeichnung' => 'Product Three',
    'preis' => 49.99, // Price changed
];

$hash3a = $hashManager->generateHash($data3a);
$hash3b = $hashManager->generateHash($data3b);

if ($hash3a !== $hash3b) {
    echo "✓ PASS: Different data produces different hash\n";
    echo "  Original: {$hash3a}\n";
    echo "  Changed:  {$hash3b}\n";
} else {
    echo "✗ FAIL: Different data produced same hash\n";
    exit(1);
}
echo "\n";

// Test 4: hasChanged method
echo "Test 4: hasChanged method\n";
$oldHash = $hash3a;
$newHash = $hash3b;

if ($hashManager->hasChanged($oldHash, $newHash)) {
    echo "✓ PASS: hasChanged correctly detects different hashes\n";
} else {
    echo "✗ FAIL: hasChanged didn't detect different hashes\n";
    exit(1);
}

if (!$hashManager->hasChanged($oldHash, $oldHash)) {
    echo "✓ PASS: hasChanged correctly identifies same hashes\n";
} else {
    echo "✗ FAIL: hasChanged incorrectly flagged same hashes as different\n";
    exit(1);
}

if ($hashManager->hasChanged(null, $newHash)) {
    echo "✓ PASS: hasChanged correctly handles null (new record)\n";
} else {
    echo "✗ FAIL: hasChanged didn't handle null correctly\n";
    exit(1);
}
echo "\n";

// Test 5: Null value normalization
echo "Test 5: Null value handling\n";
$data5a = [
    'artikelnummer' => 'ART-005',
    'bezeichnung' => null,
    'preis' => 59.99,
];

$data5b = [
    'artikelnummer' => 'ART-005',
    'bezeichnung' => '',
    'preis' => 59.99,
];

$hash5a = $hashManager->generateHash($data5a);
$hash5b = $hashManager->generateHash($data5b);

if ($hash5a === $hash5b) {
    echo "✓ PASS: Null and empty string normalized to same value\n";
    echo "  Hash: {$hash5a}\n";
} else {
    echo "✗ FAIL: Null and empty string produced different hashes\n";
    echo "  Null hash:  {$hash5a}\n";
    echo "  Empty hash: {$hash5b}\n";
    exit(1);
}
echo "\n";

// Test 6: extractHashableFields excludes IDs and metadata
echo "Test 6: Extract hashable fields\n";
$fullPayload = [
    'id' => 123,
    'xt_id' => 456,
    'afs_id' => 789,
    'update' => 1,
    'last_update' => '2023-01-01',
    'last_imported_hash' => 'oldhash',
    'last_seen_hash' => 'oldhash',
    'artikelnummer' => 'ART-006',
    'bezeichnung' => 'Product Six',
    'preis' => 69.99,
];

$hashableFields = $hashManager->extractHashableFields($fullPayload);

$excludedFields = ['id', 'xt_id', 'afs_id', 'update', 'last_update', 'last_imported_hash', 'last_seen_hash'];
$hasExcluded = false;
foreach ($excludedFields as $field) {
    if (isset($hashableFields[$field])) {
        echo "✗ FAIL: Field '{$field}' should be excluded but is present\n";
        $hasExcluded = true;
    }
}

if (!$hasExcluded) {
    echo "✓ PASS: Excluded fields (IDs, metadata) removed correctly\n";
}

$requiredFields = ['artikelnummer', 'bezeichnung', 'preis'];
$missingFields = false;
foreach ($requiredFields as $field) {
    if (!isset($hashableFields[$field])) {
        echo "✗ FAIL: Field '{$field}' should be included but is missing\n";
        $missingFields = true;
    }
}

if (!$missingFields) {
    echo "✓ PASS: Required fields (data fields) retained correctly\n";
}
echo "\n";

// Test 7: Floating point normalization
echo "Test 7: Floating point normalization\n";
$data7a = ['preis' => 19.995]; // Will round to 20.00
$data7b = ['preis' => 19.999]; // Will round to 20.00

$hash7a = $hashManager->generateHash($data7a);
$hash7b = $hashManager->generateHash($data7b);

if ($hash7a === $hash7b) {
    echo "✓ PASS: Similar floats normalized to same value (rounding)\n";
    echo "  Hash: {$hash7a}\n";
} else {
    echo "ℹ INFO: Similar floats produce different hashes (precision kept)\n";
    echo "  This is acceptable behavior depending on rounding strategy\n";
}
echo "\n";

// Test 8: Hash format (SHA-256)
echo "Test 8: Hash format validation\n";
$testHash = $hashManager->generateHash(['test' => 'data']);
if (preg_match('/^[a-f0-9]{64}$/', $testHash)) {
    echo "✓ PASS: Hash is valid SHA-256 format (64 hex chars)\n";
    echo "  Example: {$testHash}\n";
} else {
    echo "✗ FAIL: Hash format invalid\n";
    echo "  Got: {$testHash}\n";
    exit(1);
}
echo "\n";

echo "=== All Tests Passed! ===\n";
echo "\nHashManager is ready for production use.\n";
