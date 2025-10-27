<?php
declare(strict_types=1);

/**
 * Remote Server Status API Endpoint
 * 
 * Fetches the sync status from configured remote/slave servers.
 * 
 * This endpoint queries remote servers' sync_status.php endpoint and
 * returns the aggregated status information for display in the web UI.
 * 
 * Request Method: GET
 * 
 * Response:
 * - JSON with status information for each configured remote server
 */

require_once __DIR__ . '/_bootstrap.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt. Nur GET-Requests sind erlaubt.', 405);
}

global $config;

try {
    $remoteConfig = $config['remote_servers'] ?? [];
    
    // Check if remote server monitoring is enabled
    if (!($remoteConfig['enabled'] ?? false)) {
        api_json([
            'ok' => true,
            'enabled' => false,
            'message' => 'Remote-Server-Monitoring ist deaktiviert',
            'servers' => [],
        ]);
        return;
    }
    
    $servers = $remoteConfig['servers'] ?? [];
    $timeout = $remoteConfig['timeout'] ?? 5;
    
    if (empty($servers)) {
        api_json([
            'ok' => true,
            'enabled' => true,
            'message' => 'Keine Remote-Server konfiguriert',
            'servers' => [],
        ]);
        return;
    }
    
    $results = [];
    
    foreach ($servers as $server) {
        $name = $server['name'] ?? 'Unknown';
        $url = $server['url'] ?? '';
        $apiKey = $server['api_key'] ?? '';
        $database = $server['database'] ?? '';
        
        if (empty($url)) {
            $results[] = [
                'name' => $name,
                'url' => $url,
                'database' => $database,
                'status' => 'error',
                'error' => 'URL nicht konfiguriert',
            ];
            continue;
        }
        
        // Fetch status from remote server
        $statusUrl = rtrim($url, '/') . '/api/sync_status.php';
        
        try {
            $ch = curl_init($statusUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            
            // Add API key header if configured
            if (!empty($apiKey)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "X-API-Key: {$apiKey}",
                ]);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($response === false || !empty($error)) {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'database' => $database,
                    'status' => 'error',
                    'error' => $error ?: 'Verbindungsfehler',
                ];
                continue;
            }
            
            if ($httpCode !== 200) {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'database' => $database,
                    'status' => 'error',
                    'error' => "HTTP {$httpCode}",
                ];
                continue;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'database' => $database,
                    'status' => 'error',
                    'error' => 'Ungültige JSON-Antwort',
                ];
                continue;
            }
            
            if (!isset($data['ok']) || !$data['ok']) {
                $results[] = [
                    'name' => $name,
                    'url' => $url,
                    'database' => $database,
                    'status' => 'error',
                    'error' => $data['error'] ?? 'Unbekannter Fehler',
                ];
                continue;
            }
            
            // Extract status information
            $remoteStatus = $data['status'] ?? [];
            
            $results[] = [
                'name' => $name,
                'url' => $url,
                'database' => $database,
                'status' => 'ok',
                'data' => [
                    'state' => $remoteStatus['state'] ?? 'unknown',
                    'stage' => $remoteStatus['stage'] ?? null,
                    'message' => $remoteStatus['message'] ?? '',
                    'total' => $remoteStatus['total'] ?? 0,
                    'processed' => $remoteStatus['processed'] ?? 0,
                    'duration' => $remoteStatus['duration'] ?? null,
                    'started_at' => $remoteStatus['started_at'] ?? null,
                    'updated_at' => $remoteStatus['updated_at'] ?? null,
                ],
            ];
            
        } catch (Throwable $e) {
            $results[] = [
                'name' => $name,
                'url' => $url,
                'database' => $database,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
    
    api_json([
        'ok' => true,
        'enabled' => true,
        'servers' => $results,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    
} catch (Throwable $e) {
    api_error('Fehler beim Abrufen des Remote-Status: ' . $e->getMessage(), 500);
}
