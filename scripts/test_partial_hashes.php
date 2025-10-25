#!/usr/bin/env php
<?php
/**
 * DEPRECATED: This test script is no longer maintained
 * 
 * Partial hash scopes (price_hash, media_hash, content_hash) have been removed
 * in favor of unified hash management using only last_imported_hash and last_seen_hash.
 * 
 * See test_hashmanager.php for current hash functionality tests.
 * 
 * ---
 * 
 * Test script for partial hash scopes in AFS_HashManager
 * 
 * Validates that partial hash generation works correctly for:
 * - price scope (Preis, Bestand, Mindestmenge)
 * - media scope (Bild1-10)
 * - content scope (Bezeichnung, Langtext, Werbetext, Meta fields)
 */

declare(strict_types=1);

echo "=== DEPRECATED TEST ===\n\n";
echo "This test script is deprecated and no longer maintained.\n";
echo "Partial hash functionality has been removed.\n\n";
echo "Please use: php scripts/test_hashmanager.php\n\n";
exit(0);

require_once __DIR__ . '/../autoload.php';

echo "=== AFS_HashManager Partial Hash Test ===\n\n";

$hashManager = new AFS_HashManager();

// Define scope definitions as configured in target_sqlite.yml
$scopeDefinitions = [
    'price' => ['Preis', 'Bestand', 'Mindestmenge'],
    'media' => ['Bild1', 'Bild2', 'Bild3', 'Bild4', 'Bild5', 'Bild6', 'Bild7', 'Bild8', 'Bild9', 'Bild10'],
    'content' => ['Bezeichnung', 'Langtext', 'Werbetext', 'Meta_Title', 'Meta_Description', 'Bemerkung', 'Hinweis', 'Einheit'],
];

// Test 1: Generate partial hashes for complete article data
echo "Test 1: Generate partial hashes\n";
$articleData = [
    'artikelnummer' => 'ART-001',
    'preis' => 19.99,
    'bestand' => 100,
    'mindestmenge' => 5,
    'bezeichnung' => 'Test Product',
    'langtext' => 'Long description',
    'werbetext' => 'Marketing text',
    'meta_title' => 'SEO Title',
    'meta_description' => 'SEO Description',
    'bemerkung' => 'Note',
    'hinweis' => 'Hint',
    'einheit' => 'Stück',
    'bild1' => 'image1.jpg',
    'bild2' => 'image2.jpg',
    'bild3' => null,
    'gewicht' => 1.5,
    'online' => 1,
];

$partialHashes = $hashManager->generatePartialHashes($articleData, $scopeDefinitions);

if (isset($partialHashes['price']) && isset($partialHashes['media']) && isset($partialHashes['content'])) {
    echo "✓ PASS: All three partial hashes generated\n";
    echo "  Price hash:   {$partialHashes['price']}\n";
    echo "  Media hash:   {$partialHashes['media']}\n";
    echo "  Content hash: {$partialHashes['content']}\n";
} else {
    echo "✗ FAIL: Not all partial hashes were generated\n";
    print_r($partialHashes);
    exit(1);
}
echo "\n";

// Test 2: Verify price hash only changes when price fields change
echo "Test 2: Price scope isolation\n";
$article2 = $articleData;
$article2['preis'] = 29.99; // Change price

$partialHashes2 = $hashManager->generatePartialHashes($article2, $scopeDefinitions);

if ($partialHashes['price'] !== $partialHashes2['price']) {
    echo "✓ PASS: Price hash changed when price field changed\n";
} else {
    echo "✗ FAIL: Price hash did not change when price changed\n";
    exit(1);
}

if ($partialHashes['media'] === $partialHashes2['media']) {
    echo "✓ PASS: Media hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Media hash changed when it shouldn't have\n";
    exit(1);
}

if ($partialHashes['content'] === $partialHashes2['content']) {
    echo "✓ PASS: Content hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Content hash changed when it shouldn't have\n";
    exit(1);
}
echo "\n";

// Test 3: Verify media hash only changes when media fields change
echo "Test 3: Media scope isolation\n";
$article3 = $articleData;
$article3['bild1'] = 'newimage1.jpg'; // Change image

$partialHashes3 = $hashManager->generatePartialHashes($article3, $scopeDefinitions);

if ($partialHashes['media'] !== $partialHashes3['media']) {
    echo "✓ PASS: Media hash changed when image field changed\n";
} else {
    echo "✗ FAIL: Media hash did not change when image changed\n";
    exit(1);
}

if ($partialHashes['price'] === $partialHashes3['price']) {
    echo "✓ PASS: Price hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Price hash changed when it shouldn't have\n";
    exit(1);
}

if ($partialHashes['content'] === $partialHashes3['content']) {
    echo "✓ PASS: Content hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Content hash changed when it shouldn't have\n";
    exit(1);
}
echo "\n";

// Test 4: Verify content hash only changes when content fields change
echo "Test 4: Content scope isolation\n";
$article4 = $articleData;
$article4['langtext'] = 'Updated long description'; // Change content

$partialHashes4 = $hashManager->generatePartialHashes($article4, $scopeDefinitions);

if ($partialHashes['content'] !== $partialHashes4['content']) {
    echo "✓ PASS: Content hash changed when content field changed\n";
} else {
    echo "✗ FAIL: Content hash did not change when content changed\n";
    exit(1);
}

if ($partialHashes['price'] === $partialHashes4['price']) {
    echo "✓ PASS: Price hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Price hash changed when it shouldn't have\n";
    exit(1);
}

if ($partialHashes['media'] === $partialHashes4['media']) {
    echo "✓ PASS: Media hash remained unchanged (isolation works)\n";
} else {
    echo "✗ FAIL: Media hash changed when it shouldn't have\n";
    exit(1);
}
echo "\n";

// Test 5: detectScopeChanges method
echo "Test 5: Detect scope changes\n";
$oldHashes = [
    'price' => $partialHashes['price'],
    'media' => $partialHashes['media'],
    'content' => $partialHashes['content'],
];

$newHashes = [
    'price' => $partialHashes2['price'], // Changed
    'media' => $partialHashes2['media'], // Unchanged
    'content' => $partialHashes2['content'], // Unchanged
];

$scopeChanges = $hashManager->detectScopeChanges($oldHashes, $newHashes);

if ($scopeChanges['price'] === true) {
    echo "✓ PASS: Detected price scope change\n";
} else {
    echo "✗ FAIL: Did not detect price scope change\n";
    exit(1);
}

if ($scopeChanges['media'] === false) {
    echo "✓ PASS: Correctly identified media scope unchanged\n";
} else {
    echo "✗ FAIL: Incorrectly flagged media scope as changed\n";
    exit(1);
}

if ($scopeChanges['content'] === false) {
    echo "✓ PASS: Correctly identified content scope unchanged\n";
} else {
    echo "✗ FAIL: Incorrectly flagged content scope as changed\n";
    exit(1);
}
echo "\n";

// Test 6: Multiple scope changes
echo "Test 6: Multiple scope changes\n";
$article5 = $articleData;
$article5['preis'] = 39.99;
$article5['bild2'] = 'newimage2.jpg';
// content unchanged

$partialHashes5 = $hashManager->generatePartialHashes($article5, $scopeDefinitions);
$scopeChanges5 = $hashManager->detectScopeChanges($oldHashes, $partialHashes5);

if ($scopeChanges5['price'] === true && $scopeChanges5['media'] === true && $scopeChanges5['content'] === false) {
    echo "✓ PASS: Correctly detected multiple scope changes (price, media) and unchanged (content)\n";
} else {
    echo "✗ FAIL: Did not correctly detect scope changes\n";
    print_r($scopeChanges5);
    exit(1);
}
echo "\n";

// Test 7: Empty scope data
echo "Test 7: Handle missing scope fields\n";
$articlePartial = [
    'artikelnummer' => 'ART-002',
    'preis' => 9.99,
    // No media fields
    'bezeichnung' => 'Partial Product',
];

$partialHashesPartial = $hashManager->generatePartialHashes($articlePartial, $scopeDefinitions);

if (isset($partialHashesPartial['price'])) {
    echo "✓ PASS: Price hash generated with partial data\n";
} else {
    echo "✗ FAIL: Price hash not generated\n";
    exit(1);
}

if ($partialHashesPartial['media'] === null) {
    echo "✓ PASS: Media hash is null when no media fields present\n";
} else {
    echo "ℹ INFO: Media hash generated even without media fields (may be empty hash)\n";
    echo "  Media hash: {$partialHashesPartial['media']}\n";
}
echo "\n";

// Test 8: Case-insensitive field matching
echo "Test 8: Case-insensitive field matching\n";
$articleMixedCase = [
    'Preis' => 19.99, // Uppercase in definition
    'preis' => 19.99, // Lowercase in payload
    'bestand' => 100,
];

try {
    $partialHashesMixed = $hashManager->generatePartialHashes($articleMixedCase, $scopeDefinitions);
    echo "✓ PASS: Handled mixed case fields without error\n";
} catch (Throwable $e) {
    echo "✗ FAIL: Error with mixed case fields: {$e->getMessage()}\n";
    exit(1);
}
echo "\n";

echo "=== All Partial Hash Tests Passed! ===\n";
echo "\nPartial hash scopes are working correctly:\n";
echo "  ✓ price scope: Independent tracking of pricing fields\n";
echo "  ✓ media scope: Independent tracking of image relationships\n";
echo "  ✓ content scope: Independent tracking of content/description fields\n";
echo "  ✓ Scope isolation: Changes in one scope don't affect others\n";
echo "  ✓ Change detection: detectScopeChanges accurately identifies what changed\n";
echo "\nReady for selective table updates based on scope changes!\n";
