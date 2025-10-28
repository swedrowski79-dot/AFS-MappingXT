<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

function findConnectionById(string $id): ?array {
    $cfg = DatabaseConfig::load();
    foreach ($cfg['connections'] as $c) {
        if ((string)($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

$id = isset($_GET['conn_id']) ? (string)$_GET['conn_id'] : '';
if ($id === '') {
    api_error('conn_id erforderlich', 400);
}

$conn = findConnectionById($id);
if (!$conn) {
    api_error('Verbindung nicht gefunden', 404);
}
$type = (string)($conn['type'] ?? '');
$settings = is_array($conn['settings'] ?? null) ? $conn['settings'] : [];

try {
    $tables = [];
    switch ($type) {
        case 'sqlite':
            $path = (string)($settings['path'] ?? '');
            if ($path === '') throw new RuntimeException('Pfad fehlt');
            $full = (new ReflectionClass('DatabaseConfig'))
                ->getMethod('normalizePath')
                ->invoke(null, $path);
            $pdo = new PDO('sqlite:' . $full);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            while ($row = $stmt?->fetch(PDO::FETCH_ASSOC)) {
                $name = (string)($row['name'] ?? '');
                if ($name !== '') $tables[] = $name;
            }
            break;
        case 'mysql':
            if (!function_exists('mysqli_connect')) throw new RuntimeException('mysqli nicht verfügbar');
            $host = (string)($settings['host'] ?? '');
            $port = (int)($settings['port'] ?? 3306);
            $db   = (string)($settings['database'] ?? '');
            $user = (string)($settings['username'] ?? '');
            $pass = (string)($settings['password'] ?? '');
            if ($host === '' || $db === '' || $user === '') throw new RuntimeException('Unvollständige MySQL-Konfiguration');
            $mysqli = @mysqli_connect($host, $user, $pass, $db, $port);
            if (!$mysqli) throw new RuntimeException('Connect-Fehler: ' . mysqli_connect_error());
            $res = $mysqli->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
            if ($res) {
                while ($row = $res->fetch_row()) {
                    // First column is table name in current DB
                    $name = (string)($row[0] ?? '');
                    if ($name !== '') $tables[] = $name;
                }
                $res->close();
            }
            $mysqli->close();
            sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
            break;
        case 'mssql':
            if (!function_exists('sqlsrv_connect')) throw new RuntimeException('sqlsrv nicht verfügbar');
            $host = (string)($settings['host'] ?? '');
            $port = (int)($settings['port'] ?? 1433);
            $db   = (string)($settings['database'] ?? '');
            $user = (string)($settings['username'] ?? '');
            $pass = (string)($settings['password'] ?? '');
            $encrypt = (bool)($settings['encrypt'] ?? true);
            $trust = (bool)($settings['trust_server_certificate'] ?? false);
            if ($host === '' || $db === '' || $user === '') throw new RuntimeException('Unvollständige MSSQL-Konfiguration');
            $server = $host . ',' . $port;
            $info = [
                'Database' => $db,
                'UID' => $user,
                'PWD' => $pass,
                'Encrypt' => $encrypt,
                'TrustServerCertificate' => $trust,
                'LoginTimeout' => 4,
            'ReturnDatesAsStrings' => true,
            ];
            $h = @sqlsrv_connect($server, $info);
            if ($h === false) {
                $err = sqlsrv_errors();
                throw new RuntimeException('Connect-Fehler' . ($err && isset($err[0]['message']) ? ': ' . $err[0]['message'] : ''));
            }
            // Enforce configured database to avoid default DB from login
            $dbQuoted = '[' . str_replace(']', ']]', $db) . ']';
            @sqlsrv_query($h, "USE {$dbQuoted}");
            $qry = "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_SCHEMA, TABLE_NAME";
            $stmt = sqlsrv_query($h, $qry);
            if ($stmt) {
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $schema = (string)($row['TABLE_SCHEMA'] ?? '');
                    $name   = (string)($row['TABLE_NAME'] ?? '');
                    if ($name !== '') $tables[] = ($schema !== '' ? $schema . '.' : '') . $name;
                }
                sqlsrv_free_stmt($stmt);
            }
            sqlsrv_close($h);
            break;
        case 'file':
            $path = (string)($settings['path'] ?? '');
            if ($path === '') throw new RuntimeException('Pfad fehlt');
            $root = (new ReflectionClass('DatabaseConfig'))
                ->getMethod('normalizePath')
                ->invoke(null, $path);
            if (!is_dir($root)) throw new RuntimeException('Verzeichnis nicht gefunden: ' . $root);
            $entries = array_values(array_filter(scandir($root) ?: [], function($n) use ($root) {
                return $n !== '.' && $n !== '..' && is_dir($root . DIRECTORY_SEPARATOR . $n);
            }));
            sort($entries, SORT_NATURAL | SORT_FLAG_CASE);
            $tables = $entries;
            break;
        default:
            $tables = [];
    }

    api_ok(['tables' => $tables]);
} catch (Throwable $e) {
    api_error('Fehler: ' . $e->getMessage(), 500);
}
