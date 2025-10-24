<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min($limit, 500));

$levelParam = $_GET['level'] ?? 'error';
$levels = null;
if ($levelParam !== null) {
    $levelParam = trim((string)$levelParam);
    if ($levelParam !== '' && strtolower($levelParam) !== 'all') {
        $levels = array_map(static fn($item) => strtolower(trim($item)), explode(',', $levelParam));
    }
}

global $config;
$tracker = createStatusTracker($config, 'categories');

api_ok([
    'entries' => $tracker->getLogs($limit, $levels),
]);
