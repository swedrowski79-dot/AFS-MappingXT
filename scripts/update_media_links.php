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
    $pdo = createEvoPdo($config);
    $mssql = createMssql($config);
} catch (Throwable $e) {
    fwrite(STDERR, "Verbindungsfehler: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    $service = new MediaLinkService($pdo, $mssql);
    $summary = $service->sync();

    echo "Medien-Verknüpfungen aktualisiert." . PHP_EOL;
    echo "Artikelbilder:   " . ($summary['bilder']['article_links'] ?? 0) . PHP_EOL;
    echo "Kategoriebilder: " . ($summary['bilder']['category_links'] ?? 0) . PHP_EOL;
    echo "Fehlende Bilder: " . (($summary['bilder']['missing_images'] ?? 0) + ($summary['bilder']['missing_cat_images'] ?? 0)) . PHP_EOL;
    echo "Artikeldokumente: " . ($summary['dokumente']['article_links'] ?? 0) . PHP_EOL;
    echo "Fehlende Dokumente: " . ($summary['dokumente']['missing_documents'] ?? 0) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Aktualisieren der Verknüpfungen: " . $e->getMessage() . "\n");
    $mssql->close();
    exit(1);
}

$mssql->close();
$pdo = null;
