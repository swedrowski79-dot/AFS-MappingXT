<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

try {
    $dbKey = isset($_GET['db']) ? strtolower((string)$_GET['db']) : '';
    $sqlitePath = isset($_GET['sqlite_path']) ? (string)$_GET['sqlite_path'] : '';

    if ($sqlitePath !== '') {
        if (!is_file($sqlitePath)) {
            api_error('SQLite-Datei nicht gefunden', 404);
        }
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        global $config;
        $pdo = match ($dbKey) {
            'delta' => createEvoDeltaPdo($config),
            'status' => createStatusPdo($config),
            default => createEvoPdo($config),
        };
    }

    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $tables = [];
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = (string)($row['name'] ?? '');
            if ($name !== '') {
                $tables[] = $name;
            }
        }
    }

    api_ok(['tables' => $tables]);
} catch (Throwable $e) {
    api_error('Fehler: ' . $e->getMessage(), 500);
}
