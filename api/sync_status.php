<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

$service = new StatusService();
$status = $service->get($config);
api_ok(['status' => $status]);

