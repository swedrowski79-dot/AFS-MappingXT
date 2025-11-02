<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_database_utils.php';
require_once __DIR__ . '/../classes/config/RemoteDatabaseConfig.php';

$config = $config ?? ($GLOBALS['config'] ?? require __DIR__ . '/../config.php');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rawInput = file_get_contents('php://input');
$decodedInput = [];
if ($rawInput !== false && $rawInput !== '') {
    $decodedInput = json_decode($rawInput, true);
    if (!is_array($decodedInput)) {
        $decodedInput = [];
    }
}

$serverIndex = $decodedInput['server_index'] ?? $_GET['server_index'] ?? null;
if ($serverIndex !== null && !is_numeric((string)$serverIndex)) {
    api_error('Ungültiger Server-Index.', 400);
}
$serverIndex = $serverIndex !== null ? (int)$serverIndex : null;

$servers = $config['remote_servers']['servers'] ?? [];

$envPath = dirname(__DIR__) . '/.env';
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

if ($serverIndex === null) {
    api_ok(['servers' => $servers]);
    return;
}

if (!isset($servers[$serverIndex])) {
    api_error('Remote-Server nicht gefunden.', 404);
}

$remote = $servers[$serverIndex];

try {
    switch ($method) {
        case 'GET':
            $data = RemoteDatabaseConfig::load($remote);
            $connections = [];
            foreach ($data['connections'] as $connection) {
                $masked = dbm_mask_connection($connection);
                $masked['status'] = DatabaseConfig::testConnection($connection);
                $masked['remote_server'] = [
                    'name' => $remote['name'] ?? '',
                    'url' => $remote['url'] ?? '',
                ];
                $masked['scope'] = 'remote';
                $connections[] = $masked;
            }

            api_ok([
                'connections' => $connections,
                'roles' => DatabaseConfig::getAvailableRoles(),
                'types' => DATABASE_TYPES,
                'metadata' => $data['server'],
            ]);
            return;

        case 'POST':
            $action = $decodedInput['action'] ?? '';
            $data = $decodedInput['connection'] ?? null;
            if (!in_array($action, ['add', 'update'], true)) {
                api_error('Ungültige Aktion.', 400);
            }
            if (!is_array($data)) {
                api_error('Ungültige Verbindung.', 400);
            }

            $configData = RemoteDatabaseConfig::load($remote);
            $connections = $configData['connections'];

            $remoteInfo = [
                'name' => $remote['name'] ?? '',
                'url' => $remote['url'] ?? '',
                'note' => ($remote['name'] ?? '') !== ''
                    ? sprintf('Verbindung gehört zum Remote-Server "%s"', $remote['name'])
                    : 'Verbindung für Remote-Server',
            ];

            if ($action === 'add') {
                $normalised = dbm_normalise_connection($data, null, $connections);
                $normalised['remote_server'] = $remoteInfo;
                $normalised['scope'] = 'remote';
                $connections[] = $normalised;
            } else {
                $id = $data['id'] ?? '';
                if ($id === '') {
                    api_error('ID erforderlich für Update.', 400);
                }
                $found = false;
                foreach ($connections as $idx => $existing) {
                    if (($existing['id'] ?? '') === $id) {
                        $normalised = dbm_normalise_connection($data, $existing, $connections);
                        $normalised['remote_server'] = $remoteInfo;
                        $normalised['scope'] = 'remote';
                        $connections[$idx] = $normalised;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    api_error('Verbindung nicht gefunden.', 404);
                }
            }

            RemoteDatabaseConfig::save($remote, $connections);

            api_ok(['message' => 'Verbindung für Remote-Server gespeichert.']);
            return;

        case 'DELETE':
            $id = $decodedInput['id'] ?? '';
            if ($id === '') {
                api_error('ID erforderlich.', 400);
            }

            $configData = RemoteDatabaseConfig::load($remote);
            $connections = array_values(array_filter(
                $configData['connections'],
                static fn($conn) => ($conn['id'] ?? '') !== $id
            ));

            RemoteDatabaseConfig::save($remote, $connections);

            api_ok(['message' => 'Verbindung gelöscht.']);
            return;

        default:
            api_error('Methode nicht erlaubt.', 405);
    }
} catch (Throwable $e) {
    api_error('Fehler bei der Remote-Datenbankverwaltung: ' . $e->getMessage());
}
