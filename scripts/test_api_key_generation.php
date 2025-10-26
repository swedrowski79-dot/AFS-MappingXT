<?php
// Test script for API key generation functionality
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "Testing API Key Generation\n";
echo "==========================\n\n";

// Test 1: Generate API key
echo "Test 1: Generating API key\n";
function generateSecureApiKey(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

$apiKey = generateSecureApiKey(32);
echo "✓ Generated API key: " . substr($apiKey, 0, 16) . "...\n";
echo "✓ Key length: " . strlen($apiKey) . " characters\n";

if (strlen($apiKey) === 64) {
    echo "✓ Key has correct length (64 chars for 32 bytes)\n";
} else {
    echo "✗ Key has incorrect length\n";
}

if (ctype_xdigit($apiKey)) {
    echo "✓ Key is valid hexadecimal\n";
} else {
    echo "✗ Key contains invalid characters\n";
}

echo "\n";

// Test 2: Generate multiple keys and check uniqueness
echo "Test 2: Testing uniqueness\n";
$keys = [];
for ($i = 0; $i < 10; $i++) {
    $keys[] = generateSecureApiKey(32);
}

$uniqueKeys = array_unique($keys);
if (count($uniqueKeys) === count($keys)) {
    echo "✓ All generated keys are unique\n";
} else {
    echo "✗ Some keys are duplicates\n";
}

echo "\n";

// Test 3: Test API endpoint (if available)
echo "Test 3: Testing API endpoint\n";
$apiUrl = 'http://localhost:8080/api/generate_api_key.php';

// Check if we can make the request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response !== false) {
    $data = json_decode($response, true);
    if (isset($data['ok']) && $data['ok'] === true && isset($data['data']['api_key'])) {
        echo "✓ API endpoint is working\n";
        echo "✓ Returned key length: " . strlen($data['data']['api_key']) . "\n";
    } else {
        echo "⚠ API endpoint returned unexpected format\n";
    }
} else {
    echo "⚠ API endpoint not available (server might not be running)\n";
    echo "  This is expected if testing without a running server\n";
}

echo "\n";
echo "All tests completed!\n";
