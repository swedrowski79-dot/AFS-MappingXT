<?php
// scripts/setup.php

declare(strict_types=1);

$scriptDir = __DIR__;
$dbDir = dirname(__DIR__) . '/db';

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
    echo "Ordner 'db' wurde erstellt.\n";
}

$databases = [
    'status.db' => 'create_status.sql',
    'evo.db'    => 'create_evo.sql',
];

foreach ($databases as $dbName => $sqlFile) {
    $dbPath = $dbDir . '/' . $dbName;
    $sqlPath = $scriptDir . '/' . $sqlFile;

    if (!file_exists($sqlPath)) {
        echo "SQL-Datei nicht gefunden: $sqlPath\n";
        continue;
    }

    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sql = file_get_contents($sqlPath);
        if (!$sql || !trim($sql)) {
            echo "SQL-Datei ist leer: $sqlPath\n";
            continue;
        }
        $db->exec($sql);
        echo "Datenbank '$dbName' erfolgreich eingerichtet.\n";
    } catch (Throwable $e) {
        echo "Fehler bei '$dbName': " . $e->getMessage() . "\n";
    }
}

echo "Setup abgeschlossen.\n";

