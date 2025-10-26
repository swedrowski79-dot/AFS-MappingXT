#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript muss über CLI ausgeführt werden.\n");
    exit(1);
}

require __DIR__ . '/../autoload.php';
$config = require __DIR__ . '/../config.php';

$dataDb = $config['paths']['data_db'] ?? (__DIR__ . '/../db/evo.db');
if (!is_string($dataDb) || $dataDb === '') {
    fwrite(STDERR, "Pfad zur Datenbank nicht definiert.\n");
    exit(1);
}

if (!is_file($dataDb)) {
    fwrite(STDERR, "SQLite-Datei nicht gefunden: {$dataDb}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dataDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Add Bildnummer column to Artikel_Bilder table
if (!columnExists($pdo, 'Artikel_Bilder', 'Bildnummer')) {
    $pdo->exec('ALTER TABLE "Artikel_Bilder" ADD COLUMN "Bildnummer" INTEGER');
    fwrite(STDOUT, "Spalte \"Bildnummer\" zu Artikel_Bilder hinzugefügt.\n");
} else {
    fwrite(STDOUT, "Spalte \"Bildnummer\" existiert bereits in Artikel_Bilder.\n");
}

fwrite(STDOUT, "Migration abgeschlossen.\n");

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("PRAGMA table_info(\"{$table}\")");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        if (strcasecmp((string)$col['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}
