<?php
declare(strict_types=1);

/**
 * Remote Server Management API Endpoint
 * 
 * Manages the list of remote servers (add, remove, update).
 * Updates the REMOTE_SERVERS environment variable in .env file.
 * 
 * Request Method: GET (list), POST (add/update), DELETE (remove)
 * 
 * POST Body:
 * {
 *   "action": "add|update",
 *   "server": {
 *     "name": "Server Name",
 *     "url": "https://server.example.com",
 *     "api_key": "optional_api_key"
 *   },
 *   "index": 0  // for update action
 * }
 * 
 * DELETE Body:
 * {
 *   "index": 0
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
 * Format servers array back to env string
 */
function formatRemoteServers(array $servers): string
{
    $configs = [];
    foreach ($servers as $server) {
        $config = $server['name'] . '|' . $server['url'];
        if (!empty($server['api_key'])) {
            $config .= '|' . $server['api_key'];
        }
        $configs[] = $config;
    }
    return implode(',', $configs);
}

/**
 * Read .env file content
 */
function readEnvContent(string $envPath): string
{
    if (!is_file($envPath)) {
        return '';
    }
    
    $content = file_get_contents($envPath);
    return $content !== false ? $content : '';
}

/**
 * Update REMOTE_SERVERS in .env content
 */
function updateRemoteServersInEnv(string $content, string $value): string
{
    $lines = explode("\n", $content);
    $updated = false;
    
    // Escape value if it contains special characters
    $needsQuotes = preg_match('/[\s#]/', $value);
    $formattedValue = $needsQuotes ? '"' . addslashes($value) . '"' : $value;
    
    foreach ($lines as $i => $line) {
        $trimmedLine = trim($line);
        
        // Skip comments and empty lines
        if (empty($trimmedLine) || str_starts_with($trimmedLine, '#')) {
            continue;
        }
        
        // Check if this line contains REMOTE_SERVERS
        if (preg_match('/^REMOTE_SERVERS\s*=/', $trimmedLine)) {
            $lines[$i] = "REMOTE_SERVERS={$formattedValue}";
            $updated = true;
            break;
        }
    }
    
    // If key wasn't found, append it to the end
    if (!$updated) {
        $lines[] = "REMOTE_SERVERS={$formattedValue}";
    }
    
    return implode("\n", $lines);
}

try {
    global $config;
    $root = dirname(__DIR__);
    $envPath = $root . '/.env';
    
    $method = $_SERVER['REQUEST_METHOD'];
    
    // GET - List all remote servers
    if ($method === 'GET') {
        $content = readEnvContent($envPath);
        $lines = explode("\n", $content);
        $remoteServersValue = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $matches)) {
                $remoteServersValue = trim($matches[1], '"\'');
                break;
            }
        }
        
        $servers = parseRemoteServers($remoteServersValue);
        
        api_ok([
            'servers' => $servers,
        ]);
        return;
    }
    
    // POST - Add or update a server
    if ($method === 'POST') {
        if (!is_file($envPath) || !is_writable($envPath)) {
            api_error('.env Datei nicht gefunden oder nicht beschreibbar', 403);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['action'])) {
            api_error('Ungültige Eingabedaten', 400);
        }
        
        $action = $input['action'];
        $serverData = $input['server'] ?? null;
        
        if (!in_array($action, ['add', 'update'])) {
            api_error('Ungültige Aktion', 400);
        }
        
        if (!is_array($serverData) || empty($serverData['name']) || empty($serverData['url'])) {
            api_error('Server-Name und URL sind erforderlich', 400);
        }
        
        // Read current servers
        $content = readEnvContent($envPath);
        $lines = explode("\n", $content);
        $remoteServersValue = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $matches)) {
                $remoteServersValue = trim($matches[1], '"\'');
                break;
            }
        }
        
        $servers = parseRemoteServers($remoteServersValue);
        
        // Validate server data
        $server = [
            'name' => trim($serverData['name']),
            'url' => rtrim(trim($serverData['url']), '/'),
            'api_key' => trim($serverData['api_key'] ?? ''),
        ];
        
        // Validate URL format
        if (!filter_var($server['url'], FILTER_VALIDATE_URL)) {
            api_error('Ungültige URL', 400);
        }
        
        if ($action === 'add') {
            // Check for duplicate names
            foreach ($servers as $existing) {
                if ($existing['name'] === $server['name']) {
                    api_error('Ein Server mit diesem Namen existiert bereits', 400);
                }
            }
            
            $servers[] = $server;
            $message = 'Server erfolgreich hinzugefügt';
        } else { // update
            $index = $input['index'] ?? -1;
            if ($index < 0 || $index >= count($servers)) {
                api_error('Ungültiger Server-Index', 400);
            }
            
            // Check for duplicate names (except current)
            foreach ($servers as $i => $existing) {
                if ($i !== $index && $existing['name'] === $server['name']) {
                    api_error('Ein Server mit diesem Namen existiert bereits', 400);
                }
            }
            
            $servers[$index] = $server;
            $message = 'Server erfolgreich aktualisiert';
        }
        
        // Update .env file
        $newValue = formatRemoteServers($servers);
        $newContent = updateRemoteServersInEnv($content, $newValue);
        
        // Create backup
        $backupPath = $envPath . '.backup.' . date('Y-m-d_H-i-s');
        file_put_contents($backupPath, $content);
        
        if (file_put_contents($envPath, $newContent) === false) {
            api_error('Fehler beim Schreiben der .env Datei', 500);
        }
        
        api_ok([
            'message' => $message,
            'servers' => $servers,
        ]);
        return;
    }
    
    // DELETE - Remove a server
    if ($method === 'DELETE') {
        if (!is_file($envPath) || !is_writable($envPath)) {
            api_error('.env Datei nicht gefunden oder nicht beschreibbar', 403);
        }
        
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input) || !isset($input['index'])) {
            api_error('Ungültige Eingabedaten', 400);
        }
        
        $index = (int)$input['index'];
        
        // Read current servers
        $content = readEnvContent($envPath);
        $lines = explode("\n", $content);
        $remoteServersValue = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $matches)) {
                $remoteServersValue = trim($matches[1], '"\'');
                break;
            }
        }
        
        $servers = parseRemoteServers($remoteServersValue);
        
        if ($index < 0 || $index >= count($servers)) {
            api_error('Ungültiger Server-Index', 400);
        }
        
        // Remove server
        array_splice($servers, $index, 1);
        
        // Update .env file
        $newValue = formatRemoteServers($servers);
        $newContent = updateRemoteServersInEnv($content, $newValue);
        
        // Create backup
        $backupPath = $envPath . '.backup.' . date('Y-m-d_H-i-s');
        file_put_contents($backupPath, $content);
        
        if (file_put_contents($envPath, $newContent) === false) {
            api_error('Fehler beim Schreiben der .env Datei', 500);
        }
        
        api_ok([
            'message' => 'Server erfolgreich entfernt',
            'servers' => $servers,
        ]);
        return;
    }
    
    api_error('Methode nicht erlaubt', 405);
    
} catch (\Throwable $e) {
    api_error('Fehler bei der Serververwaltung: ' . $e->getMessage());
}
