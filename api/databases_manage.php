<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const DATABASE_TYPES = [
    'mssql' => 'Microsoft SQL Server',
    'mysql' => 'MySQL / MariaDB',
    'sqlite' => 'SQLite',
    'filedb' => 'FileDB (Datei-Datenbank)',
    'file' => 'Dateipfad (Legacy)',
];

function dbm_slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug ?? '') ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'connection';
}

/**
 * Mask sensitive settings (e.g., password) for API responses.
 *
 * @param array<string,mixed> $connection
 * @return array<string,mixed>
 */
function dbm_mask_connection(array $connection): array
{
    $masked = $connection;
    $type = $connection['type'] ?? '';
    if ($type === 'file') {
        $type = 'filedb';
    }
    $settings = $connection['settings'] ?? [];
    if (!is_array($settings)) {
        $settings = [];
    }
    $masked['type'] = $type;
    $masked['type_label'] = DATABASE_TYPES[$type] ?? $type;

    if (in_array($type, ['mssql', 'mysql'], true)) {
        if (isset($settings['password']) && $settings['password'] !== '') {
            $settings['password'] = '__PROTECTED__';
            $masked['password_protected'] = true;
        } else {
            $settings['password'] = '';
            $masked['password_protected'] = false;
        }
    } else {
        $masked['password_protected'] = false;
    }

    $masked['settings'] = $settings;
    return $masked;
}

/**
 * Validate and normalise incoming connection payload.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed>|null $existing
 * @return array<string,mixed>
 */
function dbm_normalise_connection(array $payload, ?array $existing, array $allConnections): array
{
    $types = array_keys(DATABASE_TYPES);
    $rolesMeta = DatabaseConfig::getAvailableRoles();

    $incomingId = isset($payload['id']) ? trim((string)$payload['id']) : '';
    if ($incomingId === '' && $existing !== null) {
        $incomingId = (string)($existing['id'] ?? '');
    }
    $title = trim((string)($payload['title'] ?? ($existing['title'] ?? '')));
    $type = $payload['type'] ?? ($existing['type'] ?? '');
    if ($type === 'file') {
        $type = 'filedb';
    }
    $roles = $payload['roles'] ?? ($existing['roles'] ?? []);
    $settings = $payload['settings'] ?? ($existing['settings'] ?? []);

    if ($title === '') {
        api_error('Titel darf nicht leer sein', 422);
    }

    if (!in_array($type, $types, true)) {
        api_error('Ungültiger Typ: ' . $type, 422);
    }

    if (!is_array($roles)) {
        api_error('Ungültige Rollenliste', 422);
    }
    $roles = array_values(array_unique(array_filter(array_map('strval', $roles))));

    // Validate roles match type
    foreach ($roles as $role) {
        if (!isset($rolesMeta[$role])) {
            api_error('Unbekannte Rolle: ' . $role, 422);
        }
        $allowedTypes = $rolesMeta[$role]['types'] ?? [];
        if (!in_array($type, $allowedTypes, true)) {
            api_error('Rolle ' . $role . ' ist nicht mit Typ ' . $type . ' kompatibel', 422);
        }
    }

    if (!is_array($settings)) {
        api_error('Ungültige Einstellungen', 422);
    }

    $settings = dbm_validate_settings($type, $settings, $existing['settings'] ?? null);

    $baseId = $incomingId !== '' ? $incomingId : dbm_slugify($title);

    // Ensure unique id
    $candidate = $baseId;
    $suffix = 1;
    $ignoreId = $existing['id'] ?? null;
    while (dbm_id_exists($candidate, $allConnections, $ignoreId)) {
        $candidate = $baseId . '-' . $suffix;
        $suffix++;
    }
    $id = $candidate;

    return [
        'id' => $id,
        'title' => $title,
        'type' => $type,
        'roles' => $roles,
        'settings' => $settings,
    ];
}

/**
 * @param string $type
 * @param array<string,mixed> $settings
 * @param array<string,mixed>|null $existing
 * @return array<string,mixed>
 */
function dbm_validate_settings(string $type, array $settings, ?array $existing): array
{
    $clean = [];
    switch ($type) {
        case 'mssql':
            $clean['host'] = trim((string)($settings['host'] ?? ($existing['host'] ?? '')));
            $clean['port'] = (int)($settings['port'] ?? ($existing['port'] ?? 1433));
            $clean['database'] = trim((string)($settings['database'] ?? ($existing['database'] ?? '')));
            $clean['username'] = trim((string)($settings['username'] ?? ($existing['username'] ?? '')));
            $password = $settings['password'] ?? null;
            if ($password === '__PROTECTED__' || $password === null) {
                $clean['password'] = $existing['password'] ?? '';
            } else {
                $clean['password'] = (string)$password;
            }
            $clean['encrypt'] = isset($settings['encrypt']) ? (bool)$settings['encrypt'] : (bool)($existing['encrypt'] ?? true);
            $clean['trust_server_certificate'] = isset($settings['trust_server_certificate'])
                ? (bool)$settings['trust_server_certificate']
                : (bool)($existing['trust_server_certificate'] ?? false);

            if ($clean['host'] === '' || $clean['database'] === '' || $clean['username'] === '') {
                api_error('Für MSSQL sind Host, Datenbank und Benutzer erforderlich.', 422);
            }
            break;

        case 'mysql':
            $clean['host'] = trim((string)($settings['host'] ?? ($existing['host'] ?? '')));
            $clean['port'] = (int)($settings['port'] ?? ($existing['port'] ?? 3306));
            $clean['database'] = trim((string)($settings['database'] ?? ($existing['database'] ?? '')));
            $clean['username'] = trim((string)($settings['username'] ?? ($existing['username'] ?? '')));
            $password = $settings['password'] ?? null;
            if ($password === '__PROTECTED__' || $password === null) {
                $clean['password'] = $existing['password'] ?? '';
            } else {
                $clean['password'] = (string)$password;
            }

            if ($clean['host'] === '' || $clean['database'] === '' || $clean['username'] === '') {
                api_error('Für MySQL sind Host, Datenbank und Benutzer erforderlich.', 422);
            }
            break;

        case 'sqlite':
            $clean['path'] = trim((string)($settings['path'] ?? ($existing['path'] ?? '')));
            if ($clean['path'] === '') {
                api_error('Für SQLite ist ein Pfad erforderlich.', 422);
            }
            break;

        case 'file':
        case 'filedb':
            $clean['path'] = trim((string)($settings['path'] ?? ($existing['path'] ?? '')));
            if ($clean['path'] === '') {
                api_error('Für FileDB ist ein Basis-Pfad erforderlich.', 422);
            }
            break;

        default:
            api_error('Unbekannter Typ.', 422);
    }

    return $clean;
}

/**
 * @param string $id
 * @param array<int,array<string,mixed>> $connections
 */
function dbm_id_exists(string $id, array $connections, ?string $ignoreId): bool
{
    foreach ($connections as $connection) {
        if (($connection['id'] ?? '') === $id && $id !== $ignoreId) {
            return true;
        }
    }
    return false;
}

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
