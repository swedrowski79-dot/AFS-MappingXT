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
                'database' => $parts[3] ?? '',
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
 * Collapse whitespace to keep log entries compact.
 */
function collapseWhitespace(string $value): string
{
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    return $normalized === null ? '' : $normalized;
}

/**
 * Truncate long strings that would otherwise spam the log.
 */
function truncateForLog(string $value, int $maxLength = 400): string
{
    if (strlen($value) <= $maxLength) {
        return $value;
    }

    return substr($value, 0, $maxLength) . '...';
}

/**
 * Persist debugging information for remote requests.
 *
 * @param array<mixed> $context
 */
function logRemoteRequestDebug(array $context): void
{
    try {
        global $config;
        $root = dirname(__DIR__);
        $logDir = $config['paths']['log_dir'] ?? ($root . '/logs');

        if (!is_dir($logDir) || !is_writable($logDir)) {
            return;
        }

        $logPath = rtrim($logDir, "/\\") . '/remote_requests.log';
        $payload = array_merge(['timestamp' => date('c')], $context);
        $line = json_encode($payload);

        if (is_string($line)) {
            error_log($line . PHP_EOL, 3, $logPath);
        }
    } catch (\Throwable $e) {
        // Logging failures should not affect API behaviour.
    }
}

/**
 * Make HTTP request to remote server
 */
function makeRemoteRequest(string $url, string $apiKey, string $method = 'GET', ?array $body = null, int $timeout = 10): array
{
    global $config;
    $allowInsecure = (bool)($config['remote_servers']['allow_insecure'] ?? false);

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
        CURLOPT_SSL_VERIFYPEER => !$allowInsecure,
        CURLOPT_SSL_VERIFYHOST => $allowInsecure ? 0 : 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    
    if ($body !== null && in_array($method, ['POST', 'PUT'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    
    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $responseSnippet = '';

    if (is_string($response) && $response !== '') {
        $responseSnippet = truncateForLog(collapseWhitespace($response));
    }

    $logContext = [
        'method' => $method,
        'url' => $url,
        'http_code' => $httpCode ?: null,
        'body_keys' => is_array($body) ? array_keys($body) : null,
        'response_preview' => $responseSnippet,
        'curl_info' => $info,
    ];

    if ($response === false || !empty($error)) {
        logRemoteRequestDebug(array_merge($logContext, [
            'stage' => 'curl_error',
            'curl_error' => $error ?: 'unknown',
        ]));

        curl_close($ch);
        throw new \Exception('Verbindungsfehler beim Aufruf von ' . $method . ' ' . $url . ': ' . ($error ?: 'Unbekannter Fehler'));
    }
    
    if ($httpCode !== 200) {
        logRemoteRequestDebug(array_merge($logContext, [
            'stage' => 'http_error',
        ]));

        curl_close($ch);

        $message = "HTTP-Fehler: {$httpCode} bei {$method} {$url}";
        if ($responseSnippet !== '') {
            $message .= ' - Antwortauszug: ' . $responseSnippet;
        }
        throw new \Exception($message);
    }

    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logRemoteRequestDebug(array_merge($logContext, [
            'stage' => 'invalid_json',
        ]));
        throw new \Exception('Ungültige JSON-Antwort vom Server');
    }
    
    if (!isset($data['ok']) || !$data['ok']) {
        logRemoteRequestDebug(array_merge($logContext, [
            'stage' => 'remote_error_response',
            'remote_error' => $data['error'] ?? 'unknown',
        ]));
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
