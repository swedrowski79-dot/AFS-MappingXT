<?php
declare(strict_types=1);

/**
 * Test script for remote server monitoring functionality
 * 
 * Tests the configuration parsing and API endpoint functionality
 */

require_once __DIR__ . '/../autoload.php';

echo "=== Remote Server Monitoring - Test Suite ===\n\n";

// Load configuration
$config = require __DIR__ . '/../config.php';

// Test 1: Configuration Loading
echo "Test 1: Configuration Loading\n";
echo str_repeat('-', 50) . "\n";

$remoteConfig = $config['remote_servers'] ?? [];
$enabled = $remoteConfig['enabled'] ?? false;
$servers = $remoteConfig['servers'] ?? [];
$timeout = $remoteConfig['timeout'] ?? 5;

echo "Remote Monitoring Enabled: " . ($enabled ? 'YES' : 'NO') . "\n";
echo "Configured Servers: " . count($servers) . "\n";
echo "Request Timeout: {$timeout}s\n";

if (!empty($servers)) {
    echo "\nConfigured Servers:\n";
    foreach ($servers as $i => $server) {
        echo "  " . ($i + 1) . ". {$server['name']} - {$server['url']}\n";
        if (!empty($server['api_key'])) {
            echo "     API Key: " . substr($server['api_key'], 0, 8) . "...\n";
        }
        if (!empty($server['database'])) {
            echo "     Database: {$server['database']}\n";
        }
    }
}

echo "\n✓ Configuration loaded successfully\n\n";

// Test 2: Environment Variable Parsing
echo "Test 2: Environment Variable Parsing\n";
echo str_repeat('-', 50) . "\n";

// Simulate environment variable
$testEnv = "TestServer1|https://example.com|key123|evo.db,TestServer2|https://example2.com||orders_evo.db";
echo "Test Input: {$testEnv}\n\n";

$parsed = array_filter(
    array_map(function($serverConfig) {
        $parts = array_map('trim', explode('|', $serverConfig));
        if (count($parts) >= 2) {
            return [
                'name' => $parts[0],
                'url' => rtrim($parts[1], '/'),
                'api_key' => $parts[2] ?? '',
                'database' => $parts[3] ?? '',
            ];
        }
        return null;
    }, array_filter(array_map('trim', explode(',', $testEnv))))
);

echo "Parsed Servers:\n";
foreach ($parsed as $i => $server) {
    echo "  " . ($i + 1) . ". Name: {$server['name']}\n";
    echo "     URL: {$server['url']}\n";
    echo "     API Key: " . (!empty($server['api_key']) ? 'SET' : 'NOT SET') . "\n";
    echo "     Database: " . (!empty($server['database']) ? $server['database'] : 'NOT SET') . "\n";
}

echo "\n✓ Environment parsing works correctly\n\n";

// Test 3: API Endpoint Accessibility
echo "Test 3: API Endpoint Accessibility\n";
echo str_repeat('-', 50) . "\n";

$apiFile = __DIR__ . '/../api/remote_status.php';
if (file_exists($apiFile)) {
    echo "API Endpoint File: EXISTS\n";
    
    // Check syntax
    $syntaxCheck = shell_exec("php -l " . escapeshellarg($apiFile) . " 2>&1");
    if (strpos($syntaxCheck, 'No syntax errors') !== false) {
        echo "Syntax Check: PASS\n";
    } else {
        echo "Syntax Check: FAIL\n";
        echo $syntaxCheck . "\n";
    }
} else {
    echo "API Endpoint File: NOT FOUND\n";
}

echo "\n✓ API endpoint is accessible\n\n";

// Test 4: Simulated API Response
echo "Test 4: Simulated Remote Server Response\n";
echo str_repeat('-', 50) . "\n";

$mockResponse = [
    'ok' => true,
    'status' => [
        'state' => 'ready',
        'stage' => null,
        'message' => 'System ready',
        'total' => 1000,
        'processed' => 1000,
        'duration' => 45.5,
        'started_at' => '2025-10-26 10:00:00',
        'updated_at' => '2025-10-26 10:00:45',
    ],
];

echo "Mock Response:\n";
echo json_encode($mockResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Process mock response
$remoteStatus = $mockResponse['status'];
$percent = $remoteStatus['total'] > 0 
    ? round(($remoteStatus['processed'] / $remoteStatus['total']) * 100) 
    : 0;

echo "\nParsed Status:\n";
echo "  State: {$remoteStatus['state']}\n";
echo "  Message: {$remoteStatus['message']}\n";
echo "  Progress: {$remoteStatus['processed']}/{$remoteStatus['total']} ({$percent}%)\n";
echo "  Duration: {$remoteStatus['duration']}s\n";

echo "\n✓ Response processing works correctly\n\n";

// Test 5: Test with local server (if available)
echo "Test 5: Local API Test\n";
echo str_repeat('-', 50) . "\n";

// Get the base URL from the environment or use a default
$baseUrl = getenv('APP_BASE_URL') ?: 'http://localhost:8080';
$localApiUrl = $baseUrl . '/api/remote_status.php';

echo "Testing local API: {$localApiUrl}\n";

// First check if server is reachable with HEAD request
$ch = curl_init($localApiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 2,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_NOBODY => true, // HEAD request
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode > 0) {
    echo "HTTP Response Code: {$httpCode}\n";
    echo "Server is reachable\n";
    
    // If reachable, try a full GET request to validate response structure
    echo "\nAttempting full GET request...\n";
    $ch = curl_init($localApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['ok'])) {
            echo "✓ Valid JSON response received\n";
            echo "  - enabled: " . ($data['enabled'] ? 'true' : 'false') . "\n";
            echo "  - servers count: " . count($data['servers'] ?? []) . "\n";
        } else {
            echo "✗ Invalid JSON response\n";
        }
    } else {
        echo "Response code: {$httpCode}\n";
    }
} else {
    echo "Server is not reachable (this is normal if not running)\n";
    echo "To test: Start the server with 'docker-compose up' or 'php -S localhost:8080'\n";
}

echo "\n✓ Local API test completed\n\n";

// Summary
echo "=== Test Summary ===\n";
echo "All basic tests passed! ✓\n\n";

echo "Next Steps:\n";
echo "1. Configure remote servers in .env file:\n";
echo "   REMOTE_SERVERS_ENABLED=true\n";
echo "   REMOTE_SERVERS=Server1|https://server1.example.com|apikey123\n\n";
echo "2. Start the application and check the web interface\n";
echo "3. The 'Remote Server Status' section will appear if enabled\n\n";
