<?php
declare(strict_types=1);

/**
 * DatabaseConfig
 *
 * Handles reading and writing the database connection configuration stored
 * in config/databases/databases.json. Provides helper methods to locate
 * specific roles and to perform reachability checks.
 */
class DatabaseConfig
{
    private const CONFIG_DIR = __DIR__ . '/../../config/databases';
    private const CONFIG_FILE = self::CONFIG_DIR . '/databases.json';
    private const BACKUP_LIMIT = 5;
    private const AVAILABLE_ROLES = [
        'AFS_MSSQL' => [
            'label' => 'AFS · MSSQL (Quelle)',
            'types' => ['mssql'],
        ],
        'AFS_FILES_IMAGES' => [
            'label' => 'AFS · Artikel (FileDB)',
            'types' => ['file', 'filedb'],
        ],
        'AFS_FILES_DOCUMENTS' => [
            'label' => 'AFS · Warengruppen (FileDB)',
            'types' => ['file', 'filedb'],
        ],
        'EVO_MAIN' => [
            'label' => 'EVO · Hauptdatenbank (SQLite)',
            'types' => ['sqlite'],
        ],
        'EVO_DELTA' => [
            'label' => 'EVO · Delta-Datenbank (SQLite)',
            'types' => ['sqlite'],
        ],
        'EVO_STATUS' => [
            'label' => 'EVO · Status-Datenbank (SQLite)',
            'types' => ['sqlite'],
        ],
        'ORDERS_MAIN' => [
            'label' => 'Bestellungen · Hauptdatenbank (SQLite)',
            'types' => ['sqlite'],
        ],
        'ORDERS_DELTA' => [
            'label' => 'Bestellungen · Delta-Datenbank (SQLite)',
            'types' => ['sqlite'],
        ],
        'XT_MYSQL' => [
            'label' => 'XT-Commerce · MySQL',
            'types' => ['mysql'],
        ],
    ];

    /**
     * Load the database configuration.
     *
     * @return array{connections: array<int, array<string,mixed>>}
     */
    public static function load(): array
    {
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0775, true);
        }

        if (!is_file(self::CONFIG_FILE)) {
            return ['connections' => []];
        }

        $json = file_get_contents(self::CONFIG_FILE);
        if ($json === false) {
            throw new RuntimeException('Datenbankkonfiguration konnte nicht gelesen werden.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Datenbankkonfiguration ist ungültig.');
        }

        $connections = $data['connections'] ?? [];
        if (!is_array($connections)) {
            $connections = [];
        }

        return ['connections' => array_values($connections)];
    }

    /**
     * Save database configuration (with backup rotation).
     *
     * @param array{connections: array<int, array<string,mixed>>} $config
     */
    public static function save(array $config): void
    {
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0775, true);
        }

        $connections = $config['connections'] ?? [];
        if (!is_array($connections)) {
            $connections = [];
        }

        $payload = json_encode(
            ['connections' => array_values($connections)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            throw new RuntimeException('Datenbankkonfiguration konnte nicht serialisiert werden.');
        }

        // Create backup if file exists
        if (is_file(self::CONFIG_FILE)) {
            $backup = self::CONFIG_FILE . '.backup.' . date('Y-m-d_H-i-s');
            copy(self::CONFIG_FILE, $backup);
            self::pruneBackups();
        }

        if (file_put_contents(self::CONFIG_FILE, $payload) === false) {
            throw new RuntimeException('Datenbankkonfiguration konnte nicht gespeichert werden.');
        }
    }

    /**
     * Retrieve first connection for a specific role.
     *
     * @param string $role
     * @return array<string,mixed>|null
     */
    public static function getConnectionForRole(string $role): ?array
    {
        $config = self::load();
        foreach ($config['connections'] as $connection) {
            $roles = $connection['roles'] ?? [];
            if (in_array($role, $roles, true)) {
                return $connection;
            }
        }
        return null;
    }

    /**
     * Reachability test for a connection definition.
     *
     * @param array<string,mixed> $connection
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(array $connection): array
    {
        $type = $connection['type'] ?? '';
        $settings = $connection['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        try {
            switch ($type) {
                case 'mssql':
                    return self::testMssql($settings);
                case 'mysql':
                    return self::testMysql($settings);
                case 'sqlite':
                    return self::testSqlite($settings);
                case 'file':
                case 'filedb':
                    return self::testFileDb($settings);
                default:
                    return ['ok' => false, 'message' => 'Unbekannter Typ'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok: bool, message: string}
     */
    private static function testMssql(array $settings): array
    {
        if (!function_exists('sqlsrv_connect')) {
            return ['ok' => false, 'message' => 'sqlsrv-Erweiterung nicht verfügbar'];
        }

        $host = $settings['host'] ?? null;
        $database = $settings['database'] ?? null;
        $username = $settings['username'] ?? null;
        $password = $settings['password'] ?? null;
        if (!$host || !$database || !$username) {
            return ['ok' => false, 'message' => 'Unvollständige MSSQL-Konfiguration'];
        }

        $port = (int)($settings['port'] ?? 1433);
        $server = $host . ',' . $port;

        $connectionInfo = [
            'Database'            => $database,
            'UID'                 => $username,
            'PWD'                 => $password ?? '',
            'Encrypt'             => (bool)($settings['encrypt'] ?? true),
            'TrustServerCertificate' => (bool)($settings['trust_server_certificate'] ?? false),
            'LoginTimeout'        => 3,
        ];

        $conn = @sqlsrv_connect($server, $connectionInfo);
        if ($conn === false) {
            $errors = sqlsrv_errors();
            $message = 'Verbindung fehlgeschlagen';
            if (is_array($errors) && isset($errors[0]['message'])) {
                $message .= ': ' . $errors[0]['message'];
            }
            return ['ok' => false, 'message' => $message];
        }

        sqlsrv_close($conn);
        return ['ok' => true, 'message' => 'Verbindung erfolgreich'];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok: bool, message: string}
     */
    private static function testMysql(array $settings): array
    {
        if (!function_exists('mysqli_connect')) {
            return ['ok' => false, 'message' => 'mysqli-Erweiterung nicht verfügbar'];
        }

        $host = $settings['host'] ?? null;
        $database = $settings['database'] ?? null;
        $username = $settings['username'] ?? null;
        if (!$host || !$database || !$username) {
            return ['ok' => false, 'message' => 'Unvollständige MySQL-Konfiguration'];
        }

        $port = (int)($settings['port'] ?? 3306);
        $password = $settings['password'] ?? '';

        $mysqli = @mysqli_connect($host, $username, $password, $database, $port);
        if (!$mysqli) {
            return ['ok' => false, 'message' => 'Verbindung fehlgeschlagen: ' . mysqli_connect_error()];
        }

        mysqli_close($mysqli);
        return ['ok' => true, 'message' => 'Verbindung erfolgreich'];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok: bool, message: string}
     */
    private static function testSqlite(array $settings): array
    {
        $path = $settings['path'] ?? null;
        if (!$path) {
            return ['ok' => false, 'message' => 'Pfad nicht definiert'];
        }

        $fullPath = self::normalizePath($path);
        if (!is_file($fullPath)) {
            return ['ok' => false, 'message' => 'Datei nicht gefunden: ' . $fullPath];
        }

        try {
            $pdo = new PDO('sqlite:' . $fullPath);
            $pdo->query('SELECT 1');
            $pdo = null;
            return ['ok' => true, 'message' => 'Verbindung erfolgreich'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'SQLite-Verbindung fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok: bool, message: string}
     */
    private static function testFileDb(array $settings): array
    {
        $path = $settings['path'] ?? null;
        if ($path === null || trim((string)$path) === '') {
            return ['ok' => false, 'message' => 'Pfad nicht definiert'];
        }
        $fullPath = self::normalizePath($path);
        try {
            new FileDB_Connection($fullPath);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'message' => 'FileDB erreichbar'];
    }

    private static function pruneBackups(): void
    {
        $backups = glob(self::CONFIG_FILE . '.backup.*') ?: [];
        if (count($backups) <= self::BACKUP_LIMIT) {
            return;
        }
        sort($backups);
        $remove = array_slice($backups, 0, -self::BACKUP_LIMIT);
        foreach ($remove as $file) {
            @unlink($file);
        }
    }

    private static function normalizePath(string $path): string
    {
        if (strpos($path, '/') === 0 || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }
        return realpath(self::CONFIG_DIR . '/../..' . '/' . ltrim($path, '/')) ?: self::CONFIG_DIR . '/../..' . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public static function getAvailableRoles(): array
    {
        return self::AVAILABLE_ROLES;
    }
}
