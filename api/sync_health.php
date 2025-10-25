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
        
        // Check if this is the data DB and verify hash columns exist
        if (strpos($path, 'evo.db') !== false) {
            $result = checkHashColumns($pdo);
            return [
                'ok' => $result['ok'], 
                'message' => $result['ok'] ? 'OK' : $result['message'],
                'path' => $path,
                'hash_columns' => $result
            ];
        }
        
        return ['ok' => true, 'message' => 'OK', 'path' => $path];
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'path' => $path];
    }
}

function checkHashColumns(PDO $pdo): array
{
    $tables = ['Artikel', 'Bilder', 'Dokumente', 'Attribute', 'category'];
    $result = ['ok' => true, 'tables' => []];
    
    foreach ($tables as $table) {
        try {
            $pragma = $pdo->query("PRAGMA table_info({$table})");
            $columns = $pragma->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_column($columns, 'name');
            
            $hasImported = in_array('last_imported_hash', $columnNames, true);
            $hasSeen = in_array('last_seen_hash', $columnNames, true);
            
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
        } catch (\Throwable $e) {
            $result['tables'][$table] = ['ok' => false, 'error' => $e->getMessage()];
            $result['ok'] = false;
            $result['message'] = 'Fehler beim Prüfen der Hash-Spalten';
        }
    }
    
    return $result;
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
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
