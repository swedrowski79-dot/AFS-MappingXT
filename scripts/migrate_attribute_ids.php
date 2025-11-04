<?php
declare(strict_types=1);
$dsn = __DIR__ . '/../db/evo.db';
$pdo = new PDO('sqlite:' . $dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1) Create attribute_new with autoincrement ID
$pdo->exec('CREATE TABLE IF NOT EXISTS attribute_new (
    attribute_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE,
    updated_at TEXT
)');

// Seed from existing attribute names (if table exists)
try {
    $pdo->exec("INSERT OR IGNORE INTO attribute_new (name, updated_at)
               SELECT DISTINCT name, updated_at FROM attribute
               WHERE name IS NOT NULL AND TRIM(name) <> ''");
} catch (Throwable $e) {}

// Seed also from artikel_attribute.attribute_id which currently holds names
try {
    $pdo->exec("INSERT OR IGNORE INTO attribute_new (name, updated_at)
               SELECT DISTINCT attribute_id AS name, updated_at FROM artikel_attribute
               WHERE attribute_id IS NOT NULL AND TRIM(attribute_id) <> ''");
} catch (Throwable $e) {}

// Replace attribute table
$pdo->exec('PRAGMA foreign_keys=off');
$pdo->beginTransaction();
try {
    $pdo->exec('DROP TABLE IF EXISTS attribute');
    $pdo->exec('ALTER TABLE attribute_new RENAME TO attribute');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
} finally {
    $pdo->exec('PRAGMA foreign_keys=on');
}

// 2) Rebuild artikel_attribute to use integer IDs
$pdo->exec('CREATE TABLE IF NOT EXISTS artikel_attribute_new (
    id INTEGER,
    attribute_id INTEGER,
    status INTEGER,
    value TEXT,
    updated_at TEXT,
    UNIQUE(id, attribute_id)
)');

// Populate via joins (model -> artikel.id, name -> attribute.attribute_id)
$pdo->exec("INSERT OR IGNORE INTO artikel_attribute_new (id, attribute_id, status, value, updated_at)
           SELECT a.id AS id,
                  at.attribute_id AS attribute_id,
                  COALESCE(CAST(aa.status AS INTEGER), 1) AS status,
                  aa.value,
                  aa.updated_at
           FROM artikel_attribute aa
           JOIN artikel a ON a.model = aa.id
           JOIN attribute at ON at.name = aa.attribute_id");

// Swap tables
$pdo->exec('PRAGMA foreign_keys=off');
$pdo->beginTransaction();
try {
    $pdo->exec('DROP TABLE IF EXISTS artikel_attribute');
    $pdo->exec('ALTER TABLE artikel_attribute_new RENAME TO artikel_attribute');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
} finally {
    $pdo->exec('PRAGMA foreign_keys=on');
}

echo "Migration completed.\n";
