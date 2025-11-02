<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_database_utils.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $config = DatabaseConfig::load();
        $connections = [];
        foreach ($config['connections'] as $connection) {
            $masked = dbm_mask_connection($connection);
            $masked['status'] = DatabaseConfig::testConnection($connection);
            $connections[] = $masked;
        }

        api_ok([
            'connections' => $connections,
            'roles' => DatabaseConfig::getAvailableRoles(),
            'types' => DATABASE_TYPES,
        ]);
        return;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        api_error('Ungültige Eingabe', 400);
    }

    $config = DatabaseConfig::load();
    $connections = $config['connections'] ?? [];

    if ($method === 'POST') {
        $action = $payload['action'] ?? '';
        $data = $payload['connection'] ?? null;
        if (!in_array($action, ['add', 'update'], true)) {
            api_error('Ungültige Aktion', 400);
        }
        if (!is_array($data)) {
            api_error('Ungültige Verbindung', 400);
        }

        if ($action === 'add') {
            $normalised = dbm_normalise_connection($data, null, $connections);
            $connections[] = $normalised;
        } else {
            $id = $data['id'] ?? '';
            if ($id === '') {
                api_error('ID erforderlich für Update', 400);
            }
            $found = false;
            foreach ($connections as $idx => $existing) {
                if (($existing['id'] ?? '') === $id) {
                    $normalised = dbm_normalise_connection($data, $existing, $connections);
                    $connections[$idx] = $normalised;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                api_error('Verbindung nicht gefunden', 404);
            }
        }

        DatabaseConfig::save(['connections' => $connections]);

        api_ok([
            'message' => 'Verbindung gespeichert',
        ]);
        return;
    }

    if ($method === 'DELETE') {
        $id = $payload['id'] ?? '';
        if ($id === '') {
            api_error('ID erforderlich', 400);
        }
        $connections = array_values(array_filter($connections, fn($conn) => ($conn['id'] ?? '') !== $id));
        DatabaseConfig::save(['connections' => $connections]);
        api_ok(['message' => 'Verbindung gelöscht']);
        return;
    }

    api_error('Methode nicht erlaubt', 405);
} catch (Throwable $e) {
    api_error('Fehler bei der Datenbankverwaltung: ' . $e->getMessage());
}
