<?php
declare(strict_types=1);

/**
 * Remote Settings API Endpoint
 * 
 * Reads or writes settings from/to a remote server.
 * 
 * Request Method: GET (read), POST (write), PUT (create .env)
 * 
 * Query Parameters (GET):
 * - server_index: Index of the remote server to read from
 * 
 * POST Body (write):
 * {
 *   "server_index": 0,
 *   "settings": { ... }
 * }
 * 
 * PUT Body (create .env):
 * {
 *   "server_index": 0,
 *   "initial_api_key": "key_from_local_server"
 * }
 */

require_once __DIR__ . '/_bootstrap.php';

/**
 * Parse REMOTE_SERVERS from env string
 */
function parseRemoteServers(string $envValue): array
{
    if (empty($envValue)) {
        return [];
    }
    
    $servers = [];
    $serverConfigs = array_filter(array_map('trim', explode(',', $envValue)));
    
    foreach ($serverConfigs as $config) {
        $parts = array_map('trim', explode('|', $config));
        if (count($parts) >= 2) {
            $servers[] = [
                'name' => $parts[0],
                'url' => rtrim($parts[1], '/'),
                'api_key' => $parts[2] ?? '',
            ];
        }
    }
    
    return $servers;
}

/**
 * Get remote servers from .env
 */
function getRemoteServers(): array
{
    $root = dirname(__DIR__);
    $envPath = $root . '/.env';
    
    if (!is_file($envPath)) {
        return [];
    }
    
    $content = file_get_contents($envPath);
    if ($content === false) {
        return [];
    }
    
    $lines = explode("\n", $content);
    $remoteServersValue = '';
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $matches)) {
            $remoteServersValue = trim($matches[1], '"\'');
            break;
        }
    }
    
    return parseRemoteServers($remoteServersValue);
}

/**
 * Make HTTP request to remote server
 */
function makeRemoteRequest(string $url, string $apiKey, string $method = 'GET', ?array $body = null, int $timeout = 10): array
{
    $ch = curl_init($url);
    
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    
    if (!empty($apiKey)) {
        $headers[] = "X-API-Key: {$apiKey}";
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    
    if ($body !== null && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || !empty($error)) {
        throw new \Exception('Verbindungsfehler: ' . ($error ?: 'Unbekannter Fehler'));
    }
    
    if ($httpCode !== 200) {
        throw new \Exception("HTTP-Fehler: {$httpCode}");
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Ungültige JSON-Antwort vom Server');
    }
    
    if (!isset($data['ok']) || !$data['ok']) {
        throw new \Exception($data['error'] ?? 'Fehler auf Remote-Server');
    }
    
    return $data;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - Read settings from remote server
    if ($method === 'GET') {
        $serverIndex = isset($_GET['server_index']) ? (int)$_GET['server_index'] : -1;
        
        if ($serverIndex < 0) {
            api_error('Server-Index erforderlich', 400);
        }
        
        $servers = getRemoteServers();
        
        if ($serverIndex >= count($servers)) {
            api_error('Ungültiger Server-Index', 400);
        }
        
        $server = $servers[$serverIndex];
        $url = $server['url'] . '/api/settings_read.php';
        
        $response = makeRemoteRequest($url, $server['api_key']);
        
        api_ok($response['data'] ?? []);
        return;
    }
    
    // POST - Write settings to remote server
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['server_index']) || !isset($input['settings'])) {
            api_error('Ungültige Eingabedaten', 400);
        }
        
        $serverIndex = (int)$input['server_index'];
        $settings = $input['settings'];
        
        $servers = getRemoteServers();
        
        if ($serverIndex < 0 || $serverIndex >= count($servers)) {
            api_error('Ungültiger Server-Index', 400);
        }
        
        $server = $servers[$serverIndex];
        $url = $server['url'] . '/api/settings_write.php';
        
        $response = makeRemoteRequest($url, $server['api_key'], 'POST', ['settings' => $settings]);
        
        api_ok($response['data'] ?? []);
        return;
    }
    
    // PUT - Create .env on remote server with initial API key
    if ($method === 'PUT') {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['server_index']) || !isset($input['initial_api_key'])) {
            api_error('Ungültige Eingabedaten', 400);
        }
        
        $serverIndex = (int)$input['server_index'];
        $initialApiKey = $input['initial_api_key'];
        
        $servers = getRemoteServers();
        
        if ($serverIndex < 0 || $serverIndex >= count($servers)) {
            api_error('Ungültiger Server-Index', 400);
        }
        
        $server = $servers[$serverIndex];
        $url = $server['url'] . '/api/initial_setup.php';
        
        // Call initial_setup.php on remote server to create .env with the API key
        // SECURITY NOTE: The remote server's initial_setup.php should implement
        // its own access controls to prevent unauthorized .env creation.
        // This is only called when explicitly requested by the user.
        $response = makeRemoteRequest($url, '', 'POST', [
            'settings' => [
                'DATA_TRANSFER_API_KEY' => $initialApiKey,
            ]
        ], 30);
        
        api_ok([
            'message' => '.env erfolgreich auf Remote-Server erstellt',
            'server' => $server['name'],
        ]);
        return;
    }
    
    api_error('Methode nicht erlaubt', 405);
    
} catch (\Throwable $e) {
    api_error('Fehler bei Remote-Zugriff: ' . $e->getMessage());
}
