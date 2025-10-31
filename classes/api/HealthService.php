<?php
declare(strict_types=1);

class HealthService
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function get(array $config): array
    {
        return [
            'sqlite' => [
                'evo' => $this->checkSqlite((string)($config['paths']['data_db'] ?? (dirname(__DIR__, 2) . '/db/evo.db'))),
                'status' => $this->checkSqlite((string)($config['paths']['status_db'] ?? (dirname(__DIR__, 2) . '/db/status.db'))),
            ],
            'mssql' => $this->checkMssqlHealth($config['mssql'] ?? []),
        ];
    }

    private function checkSqlite(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'Datei nicht gefunden', 'path' => $path];
        }
        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');
            if (str_contains($path, 'evo.db')) {
                $result = $this->checkHashColumns($pdo);
                return [
                    'ok' => $result['ok'],
                    'message' => $result['ok'] ? 'OK' : ($result['message'] ?? 'Fehler'),
                    'path' => $path,
                    'hash_columns' => $result,
                ];
            }
            return ['ok' => true, 'message' => 'OK', 'path' => $path];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'path' => $path];
        }
    }

    private function checkHashColumns(PDO $pdo): array
    {
        $tables = ['Artikel', 'Bilder', 'Dokumente', 'Attribute', 'category'];
        $result = ['ok' => true, 'tables' => []];
        foreach ($tables as $table) {
            try {
                $pragma = $pdo->query("PRAGMA table_info({$table})");
                $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($columns, 'name');
                $hasImported = in_array('last_imported_hash', $names, true);
                $hasSeen = in_array('last_seen_hash', $names, true);
                $tableOk = $hasImported && $hasSeen;
                $result['tables'][$table] = [
                    'ok' => $tableOk,
                    'last_imported_hash' => $hasImported,
                    'last_seen_hash' => $hasSeen,
                ];
                if (!$tableOk) {
                    $result['ok'] = false;
                    $result['message'] = 'Hash-Spalten fehlen in einigen Tabellen';
                }
            } catch (Throwable $e) {
                $result['tables'][$table] = ['ok' => false, 'error' => $e->getMessage()];
                $result['ok'] = false;
                $result['message'] = 'Fehler beim Prüfen der Hash-Spalten';
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $cfg
     */
    private function checkMssqlHealth(array $cfg): array
    {
        try {
            $host = $cfg['host'] ?? 'localhost';
            $port = (int)($cfg['port'] ?? 1433);
            $server = $host . ',' . $port;
            $mssql = new MSSQL_Connection(
                $server,
                (string)($cfg['username'] ?? ''),
                (string)($cfg['password'] ?? ''),
                (string)($cfg['database'] ?? ''),
                [
                    'encrypt' => $cfg['encrypt'] ?? true,
                    'trust_server_certificate' => $cfg['trust_server_certificate'] ?? false,
                    'appname' => $cfg['appname'] ?? 'AFS-Sync',
                ]
            );
            $mssql->scalar('SELECT 1');
            $mssql->close();
            return ['ok' => true, 'message' => 'OK', 'server' => $server];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
