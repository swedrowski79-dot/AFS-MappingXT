#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== Generated SQL Statements from Target Mapping ===\n\n";

$mappingPath = __DIR__ . '/../schemas/evo.yml';
if (!is_file($mappingPath)) {
    $legacy = __DIR__ . '/../mappings/evo.yml';
    if (is_file($legacy)) {
        $mappingPath = $legacy;
    }
}
$mappingData = YamlMappingLoader::load($mappingPath);
$targetMapper = TargetMapper::fromFile($mappingPath);

$version = $mappingData['version'] ?? 'n/a';
echo "Mapping Version: {$version}\n";
echo str_repeat('=', 80) . "\n\n";

$printUpsert = function(string $title, string $tableName) use ($targetMapper): void {
    try {
        $fields = $targetMapper->getFields($tableName);
    } catch (Throwable $e) {
        echo "⚠ Table {$tableName} not defined in mapping\n\n";
        return;
    }
    if ($fields === []) {
        echo "⚠ Table {$tableName} has no fields in mapping\n\n";
        return;
    }
    $sql = $targetMapper->generateUpsertSql($tableName, $fields);
    echo $title . "\n";
    echo str_repeat('-', 80) . "\n";
    echo $sql . "\n\n";
};

$printDelete = function(string $title, string $tableName) use ($targetMapper): void {
    try {
        $keys = $targetMapper->getUniqueKeyColumns($tableName);
    } catch (Throwable $e) {
        echo "⚠ Table {$tableName} not defined in mapping\n\n";
        return;
    }
    if ($keys === []) {
        echo "⚠ Table {$tableName} has no key definition in mapping\n\n";
        return;
    }
    $sql = $targetMapper->generateDeleteSql($tableName, $keys);
    echo $title . "\n";
    echo str_repeat('-', 80) . "\n";
    echo $sql . "\n\n";
};

$printUpsert('1. Artikel UPSERT Statement:', 'Artikel');
$printUpsert('2. Artikel-Bilder Relationship UPSERT:', 'Artikel_Bilder');
$printDelete('3. Artikel-Bilder Relationship DELETE:', 'Artikel_Bilder');
$printUpsert('4. Dokument-Artikel Relationship UPSERT:', 'Document_Artikel');
$printDelete('5. Dokument-Artikel Relationship DELETE:', 'Document_Artikel');
$printUpsert('6. Artikel-Attribute Relationship UPSERT:', 'Artikel_Attribute');
$printDelete('7. Artikel-Attribute Relationship DELETE:', 'Artikel_Attribute');

echo str_repeat('=', 80) . "\n";
echo "All SQL statements are now generated dynamically from evo.yml\n";
echo "Any changes to the mapping will automatically update these statements.\n";
