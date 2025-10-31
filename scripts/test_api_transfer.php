<?php
declare(strict_types=1);

/**
 * Test script for API_Transfer class
 * 
 * This script tests the data transfer API functionality without making actual transfers.
 * It validates configuration loading, API key validation, and class instantiation.
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

$config = require __DIR__ . '/../config.php';

echo "=== API_Transfer Test ===\n\n";

// Test 1: Class instantiation
echo "Test 1: Class instantiation\n";
try {
    // Set minimal config for testing
    $testConfig = [
        'data_transfer' => [
            'api_key' => 'test_key_12345',
            'database' => [
                'enabled' => true,
                'source' => '/tmp/test_source.db',
                'target' => '/tmp/test_target.db',
            ],
            'images' => [
                'enabled' => true,
                'source' => '/tmp/test_images_src',
                'target' => '/tmp/test_images_tgt',
            ],
            'documents' => [
                'enabled' => true,
                'source' => '/tmp/test_docs_src',
                'target' => '/tmp/test_docs_tgt',
            ],
            'max_file_size' => 104857600,
            'log_transfers' => false,
        ],
    ];
    
    $transfer = new API_Transfer($testConfig, null);
    echo "✓ API_Transfer instance created successfully\n";
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: API Key validation
echo "\nTest 2: API Key validation\n";
try {
    $validKey = 'test_key_12345';
    $invalidKey = 'wrong_key';
    
    if ($transfer->validateApiKey($validKey)) {
        echo "✓ Valid API key accepted\n";
    } else {
        echo "✗ Valid API key rejected\n";
        exit(1);
    }
    
    if (!$transfer->validateApiKey($invalidKey)) {
        echo "✓ Invalid API key rejected\n";
    } else {
        echo "✗ Invalid API key accepted\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Configuration validation
echo "\nTest 3: Configuration validation\n";
try {
    // Test missing API key
    $invalidConfig = ['data_transfer' => ['api_key' => '']];
    try {
        new API_Transfer($invalidConfig, null);
        echo "✗ Should have thrown exception for missing API key\n";
        exit(1);
    } catch (AFS_ConfigurationException $e) {
        echo "✓ Correctly throws exception for missing API key\n";
    }
} catch (Throwable $e) {
    echo "✗ Unexpected error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Config loading from main config
echo "\nTest 4: Main configuration loading\n";
try {
    if (isset($config['data_transfer'])) {
        echo "✓ data_transfer configuration exists in config.php\n";
        
        if (isset($config['data_transfer']['api_key'])) {
            echo "✓ api_key configuration exists\n";
        } else {
            echo "✗ api_key configuration missing\n";
        }
        
        if (isset($config['data_transfer']['database'])) {
            echo "✓ database configuration exists\n";
        } else {
            echo "✗ database configuration missing\n";
        }
        
        if (isset($config['data_transfer']['images'])) {
            echo "✓ images configuration exists\n";
        } else {
            echo "✗ images configuration missing\n";
        }
        
        if (isset($config['data_transfer']['documents'])) {
            echo "✓ documents configuration exists\n";
        } else {
            echo "✗ documents configuration missing\n";
        }
    } else {
        echo "✗ data_transfer configuration missing from config.php\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo "✗ Failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== All tests passed! ===\n";
exit(0);
