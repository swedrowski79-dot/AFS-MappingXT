<?php
declare(strict_types=1);

/**
 * Data Transfer API Endpoint
 * 
 * Secure endpoint for transferring delta databases, images, and documents
 * between different servers.
 * 
 * Authentication: Requires API key in 'X-API-Key' header or 'api_key' POST parameter
 * 
 * Request Parameters:
 * - transfer_type: 'database', 'images', 'documents', or 'all' (default: 'all')
 * 
 * Response:
 * - JSON with transfer results for each requested type
 */

require_once __DIR__ . '/_bootstrap.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt. Nur POST-Requests sind erlaubt.', 405);
}

global $config;

try {
    // Get API key from header or POST parameter
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['api_key'] ?? '');
    
    if (empty($apiKey)) {
        api_error('API-Key fehlt. Bitte X-API-Key Header oder api_key Parameter angeben.', 401);
    }
    
    // Get transfer type
    $transferType = $_POST['transfer_type'] ?? 'all';
    
    if (!in_array($transferType, ['database', 'images', 'documents', 'all'], true)) {
        api_error('Ungültiger Transfer-Typ. Erlaubt: database, images, documents, all', 400);
    }
    
    // Create logger
    $logger = createMappingLogger($config);
    
    // Create transfer handler
    $transfer = new API_Transfer($config, $logger);
    
    // Validate API key
    if (!$transfer->validateApiKey($apiKey)) {
        api_error('Ungültiger API-Key', 403);
    }
    
    // Perform transfer
    $startTime = microtime(true);
    
    $results = match ($transferType) {
        'database' => ['database' => $transfer->transferDatabase()],
        'images' => ['images' => $transfer->transferImages()],
        'documents' => ['documents' => $transfer->transferDocuments()],
        'all' => $transfer->transferAll(),
        default => throw new InvalidArgumentException('Ungültiger Transfer-Typ'),
    };
    
    $totalDuration = round(microtime(true) - $startTime, 3);
    
    // Build response
    $response = [
        'ok' => true,
        'transfer_type' => $transferType,
        'results' => $results,
        'total_duration' => $totalDuration,
        'timestamp' => date('Y-m-d H:i:s'),
    ];
    
    // Check if any transfers failed
    $allSuccess = true;
    foreach ($results as $result) {
        if (isset($result['success']) && !$result['success'] && !($result['skipped'] ?? false)) {
            $allSuccess = false;
            break;
        }
    }
    
    if (!$allSuccess) {
        $response['warning'] = 'Einige Transfers sind fehlgeschlagen. Prüfen Sie die results für Details.';
    }
    
    api_json($response);
    
} catch (AFS_ConfigurationException $e) {
    api_error('Konfigurationsfehler: ' . $e->getMessage(), 500);
} catch (AFS_FileException $e) {
    api_error('Dateifehler: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    api_error('Transfer fehlgeschlagen: ' . $e->getMessage(), 500);
}
