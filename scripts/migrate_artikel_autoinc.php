<?php
declare(strict_types=1);
$pdo=new PDO('sqlite:' . __DIR__ . '/../db/evo.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys=off');
$pdo->beginTransaction();
try{
  $pdo->exec('CREATE TABLE artikel_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    model TEXT UNIQUE,
    name TEXT,
    ean TEXT,
    description_html TEXT,
    promo_html TEXT,
    note_html TEXT,
    unit TEXT,
    gross_weight TEXT,
    price TEXT,
    tax_class_id TEXT,
    stock TEXT,
    status TEXT,
    meta_title TEXT,
    meta_description TEXT,
    change TEXT,
    category TEXT,
    is_master TEXT,
    master_model TEXT,
    products_image TEXT,
    products_name TEXT,
    products_description TEXT,
    seo_slug TEXT,
    source_hash TEXT,
    updated_at TEXT
  )');
  $pdo->exec('INSERT INTO artikel_new (
      model,name,ean,description_html,promo_html,note_html,unit,gross_weight,price,tax_class_id,stock,status,meta_title,meta_description,change,category,is_master,master_model,products_image,products_name,products_description,seo_slug,source_hash,updated_at
    ) SELECT model,name,ean,description_html,promo_html,note_html,unit,gross_weight,price,tax_class_id,stock,status,meta_title,meta_description,change,category,is_master,master_model,products_image,products_name,products_description,seo_slug,source_hash,updated_at FROM artikel');
  $pdo->exec('DROP TABLE artikel');
  $pdo->exec('ALTER TABLE artikel_new RENAME TO artikel');
  // Drop dependent tables; mapping will rebuild
  $pdo->exec('DROP TABLE IF EXISTS artikel_system');
  $pdo->exec('DROP TABLE IF EXISTS artikel_attribute');
  $pdo->exec('DROP TABLE IF EXISTS artikel_attribute_system');
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  throw $e;
} finally {
  $pdo->exec('PRAGMA foreign_keys=on');
}
echo "OK\n";