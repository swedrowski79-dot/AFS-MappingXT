<?php
declare(strict_types=1);

/**
 * AFS_UpdateNotifier
 * 
 * Notifies the main server when an update has been performed.
 * This is used to inform the central server about interface updates
 * before continuing with the normal API call.
 */
class AFS_UpdateNotifier
{
    private array $config;
    private ?STATUS_MappingLogger $logger;
    
    /**
     * @param array $config Configuration array
     * @param STATUS_MappingLogger|null $logger Optional logger
     */
    public function __construct(array $config, ?STATUS_MappingLogger $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Notify main server about an update
     * 
     * @param array $updateInfo Information about the update
     * @return array{success: bool, message?: string, response?: array}
     */
    public function notifyUpdate(array $updateInfo): array
    {
        // Get main server URL from remote_servers configuration
        $remoteServers = $this->config['remote_servers'] ?? [];
        $servers = $remoteServers['servers'] ?? [];
        
        if (empty($servers)) {
            // No main server configured, log and return success
            if ($this->logger) {
                $this->logger->log('info', 'update_notification_skipped', 'Kein Hauptserver konfiguriert', [
                    'update_info' => $updateInfo,
                ]);
            }
            
            return [
                'success' => true,
                'message' => 'Keine Hauptserver-Benachrichtigung erforderlich (nicht konfiguriert)',
            ];
        }
        
        // Find the main server (first server in the list is considered the main server)
        $mainServer = $servers[0];
        $serverUrl = rtrim($mainServer['url'], '/');
        $apiKey = $mainServer['api_key'] ?? '';
        
        // Prepare notification data
        $notificationData = [
            'event' => 'interface_updated',
            'timestamp' => date('Y-m-d H:i:s'),
            'update_info' => $updateInfo,
            'server_info' => [
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
            ],
        ];
        
        // Send notification via HTTP POST
        try {
            $result = $this->sendNotification($serverUrl, $apiKey, $notificationData);
            
            if ($this->logger) {
                $this->logger->log('info', 'update_notification_sent', 'Hauptserver über Update benachrichtigt', [
                    'server_url' => $serverUrl,
                    'result' => $result,
                ]);
            }
            
            return [
                'success' => true,
                'message' => 'Hauptserver erfolgreich benachrichtigt',
                'response' => $result,
            ];
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->log('warning', 'update_notification_failed', 'Fehler beim Benachrichtigen des Hauptservers', [
                    'server_url' => $serverUrl,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Don't fail the entire process if notification fails
            return [
                'success' => false,
                'message' => 'Fehler beim Benachrichtigen des Hauptservers: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Send HTTP POST notification to main server
     * 
     * @param string $serverUrl Server URL
     * @param string $apiKey API key for authentication
     * @param array $data Data to send
     * @return array Response from server
     * @throws RuntimeException On HTTP errors
     */
    private function sendNotification(string $serverUrl, string $apiKey, array $data): array
    {
        $url = $serverUrl . '/api/update_notification.php';
        $timeout = $this->config['remote_servers']['timeout'] ?? 5;
        
        // Prepare POST data
        $postData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Prepare headers
        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
        ];
        
        // Initialize cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For development, should be true in production
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new RuntimeException("cURL error: {$error}");
        }
        
        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP error {$httpCode}: {$response}");
        }
        
        $decodedResponse = json_decode($response, true);
        
        if (!is_array($decodedResponse)) {
            throw new RuntimeException("Invalid JSON response: {$response}");
        }
        
        return $decodedResponse;
    }
}
