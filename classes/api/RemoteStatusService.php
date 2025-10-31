<?php
declare(strict_types=1);

class RemoteStatusService
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function aggregate(array $config): array
    {
        $remoteConfig = $config['remote_servers'] ?? [];
        if (!($remoteConfig['enabled'] ?? false)) {
            return ['ok' => true, 'enabled' => false, 'servers' => [], 'message' => 'Remote-Server-Monitoring ist deaktiviert'];
        }
        $servers = $remoteConfig['servers'] ?? [];
        $timeout = (int)($remoteConfig['timeout'] ?? 5);
        if (empty($servers)) {
            $envPath = dirname(__DIR__, 2) . '/.env';
            if (is_file($envPath)) {
                $content = file_get_contents($envPath) ?: '';
                $lines = explode("\n", $content);
                $remoteServersValue = '';
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^REMOTE_SERVERS\s*=\s*(.*)$/', $line, $m)) {
                        $remoteServersValue = trim($m[1], "\"' ");
                        break;
                    }
                }
                if ($remoteServersValue !== '') {
                    $servers = [];
                    foreach (array_filter(array_map('trim', explode(',', $remoteServersValue))) as $cfg) {
                        $parts = array_map('trim', explode('|', $cfg));
                        if (count($parts) >= 2) {
                            $servers[] = [
                                'name' => $parts[0],
                                'url' => rtrim($parts[1], '/'),
                                'api_key' => $parts[2] ?? '',
                                'database' => $parts[3] ?? '',
                            ];
                        }
                    }
                }
            }
        }
        if (empty($servers)) {
            return ['ok' => true, 'enabled' => true, 'servers' => [], 'message' => 'Keine Remote-Server konfiguriert'];
        }
        $results = [];
        foreach ($servers as $server) {
            $results[] = $this->fetchServerStatus($server, $timeout, (bool)($remoteConfig['allow_insecure'] ?? false));
        }
        return ['ok' => true, 'enabled' => true, 'servers' => $results];
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed>
     */
    private function fetchServerStatus(array $server, int $timeout, bool $allowInsecure): array
    {
        $name = $server['name'] ?? 'Unknown';
        $url = $server['url'] ?? '';
        $apiKey = $server['api_key'] ?? '';
        $database = $server['database'] ?? '';
        if ($url === '') {
            return ['name'=>$name,'url'=>$url,'database'=>$database,'status'=>'error','error'=>'URL nicht konfiguriert'];
        }
        $statusUrl = rtrim($url, '/') . '/api/sync_status.php';
        $ch = curl_init($statusUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => !$allowInsecure,
            CURLOPT_SSL_VERIFYHOST => $allowInsecure ? 0 : 2,
        ]);
        if ($apiKey !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-Key: {$apiKey}"]); 
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $error) {
            return ['name'=>$name,'url'=>$url,'database'=>$database,'status'=>'error','error'=>$error ?: 'Verbindungsfehler'];
        }
        if ($httpCode !== 200) {
            return ['name'=>$name,'url'=>$url,'database'=>$database,'status'=>'error','error'=>'HTTP ' . $httpCode];
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['name'=>$name,'url'=>$url,'database'=>$database,'status'=>'error','error'=>'Ungültige JSON-Antwort'];
        }
        $state = $data['data']['status']['state'] ?? 'unknown';
        return ['name'=>$name,'url'=>$url,'database'=>$database,'status'=>'ok','data'=>['state'=>$state]];
    }
}
