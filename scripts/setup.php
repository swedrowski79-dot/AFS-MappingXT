<?php
// scripts/setup.php

declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php wurde nicht gefunden.\n");
    exit(1);
}

$config = require $configFile;

try {
    $service = new SetupService();
    $result = $service->run($config);

    foreach ($result as $name => $info) {
        $path = $info['path'] ?? '(unbekannt)';
        $created = !empty($info['created']);
        printf(
            "%s: %s (%s)\n",
            strtoupper($name),
            $path,
            $created ? 'neu angelegt' : 'bereits vorhanden'
        );

        if (!empty($info['tables_created'])) {
            printf("  Tabellen angelegt: %s\n", implode(', ', (array)$info['tables_created']));
        }
        if (!empty($info['columns_added'])) {
            foreach ($info['columns_added'] as $table => $columns) {
                printf("  Neue Spalten in %s: %s\n", $table, implode(', ', (array)$columns));
            }
        }
    }

    echo "Setup abgeschlossen.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Setup fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}
