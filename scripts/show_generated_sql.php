#!/usr/bin/env php
<?php

/**
 * Display generated SQL statements for documentation purposes
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

echo "=== Generated SQL Statements from Target Mapping ===\n\n";

$mappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
$targetMapping = new AFS_TargetMappingConfig($mappingPath);
$sqlBuilder = new AFS_SqlBuilder($targetMapping);

echo "Mapping Version: " . $targetMapping->getVersion() . "\n";
echo str_repeat('=', 80) . "\n\n";

// Article UPSERT
echo "1. Article UPSERT Statement:\n";
echo str_repeat('-', 80) . "\n";
$articleSql = $sqlBuilder->buildEntityUpsert('articles');
echo $articleSql . "\n\n";

// Article Images Relationship
echo "2. Article-Images Relationship UPSERT:\n";
echo str_repeat('-', 80) . "\n";
$imageRelSql = $sqlBuilder->buildRelationshipUpsert('article_images');
echo $imageRelSql . "\n\n";

echo "3. Article-Images Relationship DELETE:\n";
echo str_repeat('-', 80) . "\n";
$imageDelSql = $sqlBuilder->buildRelationshipDelete('article_images', ['Artikel_ID', 'Bild_ID']);
echo $imageDelSql . "\n\n";

// Article Documents Relationship
echo "4. Article-Documents Relationship UPSERT:\n";
echo str_repeat('-', 80) . "\n";
$docRelSql = $sqlBuilder->buildRelationshipUpsert('article_documents');
echo $docRelSql . "\n\n";

echo "5. Article-Documents Relationship DELETE:\n";
echo str_repeat('-', 80) . "\n";
$docDelSql = $sqlBuilder->buildRelationshipDelete('article_documents', ['Artikel_ID', 'Dokument_ID']);
echo $docDelSql . "\n\n";

// Article Attributes Relationship
echo "6. Article-Attributes Relationship UPSERT:\n";
echo str_repeat('-', 80) . "\n";
$attrRelSql = $sqlBuilder->buildRelationshipUpsert('article_attributes');
echo $attrRelSql . "\n\n";

echo "7. Article-Attributes Relationship DELETE:\n";
echo str_repeat('-', 80) . "\n";
$attrDelSql = $sqlBuilder->buildRelationshipDelete('article_attributes', ['Artikel_ID', 'Attribute_ID']);
echo $attrDelSql . "\n\n";

echo str_repeat('=', 80) . "\n";
echo "All SQL statements are now generated dynamically from target_sqlite.yml\n";
echo "Any changes to the mapping will automatically update these statements.\n";
