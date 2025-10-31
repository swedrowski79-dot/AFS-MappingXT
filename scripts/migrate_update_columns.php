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

$targets = [
    'Artikel_Bilder' => 'ALTER TABLE "Artikel_Bilder" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
    'Artikel_Dokumente' => 'ALTER TABLE "Artikel_Dokumente" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
    'Attrib_Artikel' => 'ALTER TABLE "Attrib_Artikel" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
    'category' => 'ALTER TABLE "category" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
];

$added = 0;
foreach ($targets as $table => $sql) {
    if (!columnExists($pdo, $table, 'update')) {
        $pdo->exec($sql);
        $added++;
        fwrite(STDOUT, "Spalte \"update\" zu {$table} hinzugefügt.\n");
    }
}

if ($added === 0) {
    fwrite(STDOUT, "Alle Tabellen verfügen bereits über ein \"update\"-Feld.\n");
}

foreach (array_keys($targets) as $table) {
    if (columnExists($pdo, $table, 'update')) {
        $pdo->exec("UPDATE \"{$table}\" SET \"update\" = COALESCE(\"update\", 0)");
    }
}

$relationshipColumns = [
    'Artikel_Dokumente' => [
        'XT_ARTIKEL_ID' => 'ALTER TABLE "Artikel_Dokumente" ADD COLUMN "XT_ARTIKEL_ID" INTEGER',
        'XT_Dokument_ID' => 'ALTER TABLE "Artikel_Dokumente" ADD COLUMN "XT_Dokument_ID" INTEGER',
    ],
];

foreach ($relationshipColumns as $table => $definitions) {
    foreach ($definitions as $column => $statement) {
        if (!columnExists($pdo, $table, $column)) {
            $pdo->exec($statement);
            fwrite(STDOUT, sprintf('Spalte "%s" zu %s hinzugefügt.' . PHP_EOL, $column, $table));
        }
    }
}

$metaColumns = [
    'Artikel' => [
        'Meta_Title' => 'ALTER TABLE "Artikel" ADD COLUMN Meta_Title TEXT',
        'Meta_Description' => 'ALTER TABLE "Artikel" ADD COLUMN Meta_Description TEXT',
    ],
    'category' => [
        'meta_title' => 'ALTER TABLE "category" ADD COLUMN meta_title TEXT',
        'meta_description' => 'ALTER TABLE "category" ADD COLUMN meta_description TEXT',
    ],
];

$metaAdded = 0;
foreach ($metaColumns as $table => $definitions) {
    foreach ($definitions as $column => $statement) {
        if (!columnExists($pdo, $table, $column)) {
            $pdo->exec($statement);
            $metaAdded++;
            fwrite(STDOUT, sprintf('Spalte "%s" zu %s hinzugefügt.' . PHP_EOL, $column, $table));
        }
    }
}

if ($metaAdded === 0) {
    fwrite(STDOUT, "Meta-Spalten sind bereits vorhanden.\n");
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
