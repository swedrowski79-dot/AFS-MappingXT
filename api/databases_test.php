<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    api_error('Ungültige Eingabe', 400);
}

$connection = null;

if (!empty($payload['id'])) {
    try {
        $config = DatabaseConfig::load();
    } catch (Throwable $e) {
        api_error('Konfiguration konnte nicht geladen werden: ' . $e->getMessage(), 500);
    }
    foreach ($config['connections'] as $candidate) {
        if (($candidate['id'] ?? '') === $payload['id']) {
            $connection = $candidate;
            break;
        }
    }
    if ($connection === null) {
        api_error('Verbindung nicht gefunden', 404);
    }
} elseif (!empty($payload['connection']) && is_array($payload['connection'])) {
    $connection = $payload['connection'];
} else {
    api_error('Es wurde keine Verbindung angegeben.', 400);
}

$result = DatabaseConfig::testConnection($connection);

api_ok([
    'status' => $result,
]);
