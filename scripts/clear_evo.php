#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript ist nur für den CLI-Einsatz gedacht.\n");
    exit(1);
}

$root = dirname(__DIR__);
$autoload = $root . '/autoload.php';
$configFile = $root . '/config.php';

if (!is_file($autoload) || !is_file($configFile)) {
    fwrite(STDERR, "Projekt nicht vollständig eingerichtet (autoload.php oder config.php fehlt).\n");
    exit(1);
}

require_once $autoload;
$config = require $configFile;

$dataDb = $config['paths']['data_db'] ?? ($root . '/db/evo.db');
if (!is_string($dataDb) || $dataDb === '') {
    fwrite(STDERR, "Pfad zur EVO-Datenbank konnte nicht ermittelt werden.\n");
    exit(1);
}

if (!is_file($dataDb)) {
    fwrite(STDERR, "SQLite-Datei nicht gefunden: {$dataDb}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dataDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

try {
    $result = AFS_Evo_Reset::clear($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, "Fehler beim Leeren der Datenbank: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "EVO-Datenbank wurde geleert.\n");
foreach ($result as $table => $count) {
    fwrite(STDOUT, sprintf("- %s: %d Zeilen entfernt\n", $table, $count));
}

