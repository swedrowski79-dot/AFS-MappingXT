<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../api/_bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript darf nur im CLI-Modus ausgeführt werden.\n");
    exit(1);
}

$config = $GLOBALS['config'] ?? null;
if (!is_array($config)) {
    fwrite(STDERR, "Konfiguration konnte nicht geladen werden.\n");
    exit(1);
}

try {
    [$tracker, $engine, $sourceConnections, $pdo] = createMappingOnlyEnvironment($config, 'media');
    $entities = ['media_bilder', 'media_dokumente', 'media_relation_bilder', 'media_relation_dokumente'];

    $summary = [];
    foreach ($entities as $entity) {
        try {
            $stats = $engine->syncEntity($entity, $pdo);
            $summary[$entity] = $stats;
            printf("%s: verarbeitet=%d, Fehler=%d, Waisen=%d\n",
                $entity,
                (int)($stats['processed'] ?? 0),
                (int)($stats['errors'] ?? 0),
                (int)($stats['orphans'] ?? 0)
            );
        } catch (Throwable $e) {
            fprintf(STDERR, "%s fehlgeschlagen: %s\n", $entity, $e->getMessage());
        }
    }

    foreach ($sourceConnections as $connection) {
        if ($connection instanceof MSSQL_Connection) {
            $connection->close();
        }
    }

    $pdo = null;
    echo "Medien-Synchronisation abgeschlossen.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler während der Medien-Synchronisation: " . $e->getMessage() . "\n");
    exit(1);
}
