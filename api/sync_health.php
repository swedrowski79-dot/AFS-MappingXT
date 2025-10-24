<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

$health = [
    'sqlite' => [
        'evo' => checkSqlite($config['paths']['data_db'] ?? (dirname(__DIR__) . '/db/evo.db')),
        'status' => checkSqlite($config['paths']['status_db'] ?? (dirname(__DIR__) . '/db/status.db')),
    ],
    'mssql' => checkMssqlHealth($config['mssql'] ?? []),
];

api_ok(['health' => $health]);

function checkSqlite(string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'message' => 'Datei nicht gefunden', 'path' => $path];
    }

    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query('SELECT 1');
        return ['ok' => true, 'message' => 'OK', 'path' => $path];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'path' => $path];
    }
}

function checkMssqlHealth(array $cfg): array
{
    try {
        $host = $cfg['host'] ?? 'localhost';
        $port = (int)($cfg['port'] ?? 1433);
        $server = $host . ',' . $port;
        $mssql = new MSSQL(
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
