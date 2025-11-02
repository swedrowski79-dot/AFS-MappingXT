<?php
declare(strict_types=1);

class RemoteDatabaseConfig
{
    private const BASE_DIR = __DIR__ . '/../../config/databases/remotes';
    private const BACKUP_LIMIT = 5;

    /**
     * @param array<string,mixed> $server
     */
    public static function load(array $server): array
    {
        self::ensureDirectory();
        $path = self::buildPath($server);
        if (!is_file($path)) {
            return [
                'server' => self::buildServerMeta($server),
                'connections' => [],
            ];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Remote-Datenbankkonfiguration konnte nicht gelesen werden.');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Remote-Datenbankkonfiguration ist ungültig.');
        }

        $meta = $data['server'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $connections = $data['connections'] ?? [];
        if (!is_array($connections)) {
            $connections = [];
        }

        return [
            'server' => array_merge(self::buildServerMeta($server), $meta),
            'connections' => array_values(array_filter($connections, 'is_array')),
        ];
    }

    /**
     * @param array<string,mixed> $server
     * @param array<int,array<string,mixed>> $connections
     */
    public static function save(array $server, array $connections): void
    {
        self::ensureDirectory();
        $path = self::buildPath($server);

        $payload = json_encode([
            'server' => self::buildServerMeta($server),
            'connections' => array_values($connections),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Remote-Datenbankkonfiguration konnte nicht serialisiert werden.');
        }

        if (is_file($path)) {
            $backup = $path . '.backup.' . date('Y-m-d_H-i-s');
            @copy($path, $backup);
            self::pruneBackups($path);
        }

        if (file_put_contents($path, $payload) === false) {
            throw new RuntimeException('Remote-Datenbankkonfiguration konnte nicht gespeichert werden.');
        }
    }

    /**
     * @param array<string,mixed> $server
     * @return array<int,array<string,mixed>>
     */
    public static function loadConnections(array $server): array
    {
        $data = self::load($server);
        return $data['connections'];
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function buildServerMeta(array $server): array
    {
        $name = trim((string)($server['name'] ?? ''));
        $url = trim((string)($server['url'] ?? ''));
        $database = trim((string)($server['database'] ?? ''));

        return [
            'name' => $name,
            'url' => $url,
            'database' => $database,
            'note' => $name !== ''
                ? sprintf('Konfiguration für Remote-Server "%s" (auf Hauptserver gespeichert)', $name)
                : 'Konfiguration für Remote-Server (auf Hauptserver gespeichert)',
            'updated_at' => date('c'),
            'stored_on' => 'main',
        ];
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function buildPath(array $server): string
    {
        $slugSource = (string)($server['name'] ?? '');
        if ($slugSource === '') {
            $slugSource = (string)parse_url((string)($server['url'] ?? ''), PHP_URL_HOST);
        }
        if ($slugSource === '') {
            $slugSource = 'remote';
        }
        $slug = self::slugify($slugSource);
        $hash = substr(sha1((string)($server['url'] ?? $slugSource)), 0, 8);
        $file = sprintf('%s__%s.json', $slug, $hash);
        return self::BASE_DIR . '/' . $file;
    }

    private static function ensureDirectory(): void
    {
        if (!is_dir(self::BASE_DIR)) {
            if (!@mkdir(self::BASE_DIR, 0775, true) && !is_dir(self::BASE_DIR)) {
                throw new RuntimeException('Remote-Datenbankverzeichnis konnte nicht erstellt werden: ' . self::BASE_DIR);
            }
        }
        if (!is_writable(self::BASE_DIR)) {
            // Versuche, die Berechtigung zu erweitern, damit Webserver schreiben kann
            if (!@chmod(self::BASE_DIR, 0775) && !@chmod(self::BASE_DIR, 0777)) {
                throw new RuntimeException(
                    sprintf(
                        'Remote-Datenbankverzeichnis "%s" ist nicht beschreibbar. Bitte Berechtigungen anpassen (z. B. chmod 775 oder chown).',
                        self::BASE_DIR
                    )
                );
            }
        }
    }

    private static function pruneBackups(string $path): void
    {
        $pattern = $path . '.backup.*';
        $backups = glob($pattern) ?: [];
        if (count($backups) <= self::BACKUP_LIMIT) {
            return;
        }
        usort($backups, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $toRemove = array_slice($backups, self::BACKUP_LIMIT);
        foreach ($toRemove as $file) {
            @unlink($file);
        }
    }

    private static function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug ?? '') ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'remote';
    }
}
