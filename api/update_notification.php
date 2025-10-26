<?php
declare(strict_types=1);

/**
 * Update Notification API Endpoint
 * 
 * This endpoint receives notifications from remote/slave servers when they
 * have been updated via git. This allows the main server to track which
 * servers have been updated and when.
 * 
 * Methods:
 * - POST: Receive update notification
 * 
 * POST Parameters (JSON):
 * - event: Event type (e.g., 'interface_updated')
 * - timestamp: Timestamp of the update
 * - update_info: Information about the update (commits, branch, etc.)
 * - server_info: Information about the reporting server
 * 
 * Authentication: Requires API key in 'X-API-Key' header
 */

require_once __DIR__ . '/_bootstrap.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt. Nur POST-Requests sind erlaubt.', 405);
}

global $config;

try {
    // Get API key from header
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    
    if (empty($apiKey)) {
        api_error('API-Key fehlt. Bitte X-API-Key Header angeben.', 401);
    }
    
    // Validate API key
    $configuredKey = $config['data_transfer']['api_key'] ?? '';
    
    if (empty($configuredKey)) {
        api_error('Server-Konfiguration unvollständig', 500);
    }
    
    if (!hash_equals($configuredKey, $apiKey)) {
        api_error('Ungültiger API-Key', 403);
    }
    
    // Get notification data
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!is_array($input)) {
        api_error('Ungültige Eingabedaten', 400);
    }
    
    $event = $input['event'] ?? '';
    $timestamp = $input['timestamp'] ?? '';
    $updateInfo = $input['update_info'] ?? [];
    $serverInfo = $input['server_info'] ?? [];
    
    if (empty($event)) {
        api_error('Event-Typ fehlt', 400);
    }
    
    // Log the notification
    $logger = createMappingLogger($config);
    
    if ($logger) {
        $logger->log('info', 'remote_update_notification', 'Update-Benachrichtigung von Remote-Server empfangen', [
            'event' => $event,
            'timestamp' => $timestamp,
            'update_info' => $updateInfo,
            'server_info' => $serverInfo,
            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }
    
    // Store notification in status database for web UI display
    try {
        $statusDb = $config['paths']['status_db'] ?? '';
        if (!empty($statusDb) && is_file($statusDb)) {
            $pdo = new PDO('sqlite:' . $statusDb);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create table if it doesn't exist
            $pdo->exec('CREATE TABLE IF NOT EXISTS remote_updates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                timestamp TEXT NOT NULL,
                server_hostname TEXT,
                update_info TEXT,
                remote_ip TEXT,
                received_at TEXT DEFAULT CURRENT_TIMESTAMP
            )');
            
            // Insert notification
            $stmt = $pdo->prepare('INSERT INTO remote_updates 
                (event, timestamp, server_hostname, update_info, remote_ip) 
                VALUES (?, ?, ?, ?, ?)');
            
            $stmt->execute([
                $event,
                $timestamp,
                $serverInfo['hostname'] ?? 'unknown',
                json_encode($updateInfo, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
        }
    } catch (\Throwable $e) {
        // Don't fail if we can't store in database
        error_log('Failed to store remote update notification: ' . $e->getMessage());
    }
    
    api_ok([
        'message' => 'Benachrichtigung empfangen und protokolliert',
        'event' => $event,
        'received_at' => date('Y-m-d H:i:s'),
    ]);
    
} catch (\Throwable $e) {
    api_error('Fehler beim Verarbeiten der Benachrichtigung: ' . $e->getMessage(), 500);
}
