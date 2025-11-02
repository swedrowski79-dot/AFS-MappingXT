<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('Methode nicht erlaubt', 405);
}

$root = dirname(__DIR__);
$mappingDir = $root . '/mapping';

$items = [];
if (is_dir($mappingDir)) {
    $files = array_values(array_filter(scandir($mappingDir) ?: [], function ($f) use ($mappingDir) {
        if ($f === '.' || $f === '..') return false;
        $path = $mappingDir . '/' . $f;
        if (!is_file($path)) return false;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        return in_array($ext, ['yml', 'yaml'], true);
    }));

    foreach ($files as $f) {
        $label = preg_replace('/\.(yml|yaml)$/i', '', $f);
        $items[] = [
            'label' => $label,
            'path' => 'mapping/' . $f,
        ];
    }
}

api_ok(['mappings' => $items]);
