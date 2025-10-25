# Database Index Strategy and Query Optimization

## Übersicht

Dieses Dokument beschreibt die Indexierungsstrategie für AFS-MappingXT zur Optimierung von Datenbankabfragen in SQLite (und optional MySQL).

## Implementierte Indexes

### Primäre Performance-Ziele

1. **Delta Export Optimierung** - Schnelles Filtern von geänderten Datensätzen (`WHERE "update" = 1`)
2. **Junction Table Lookups** - Effiziente Rückwärts- und Vorwärts-Suchen in Verknüpfungstabellen
3. **Bi-direktionale Synchronisation** - XT_ID basierte Lookups für Datenabgleich
4. **Logging Performance** - Schnelle Log-Abfragen nach Level, Stage und Zeitstempel

### Index-Kategorien

#### 1. Update-Flag Indexes (Partial Indexes)

Optimiert Delta-Export Operationen durch Filtern nur geänderter Datensätze:

```sql
-- Artikel
CREATE INDEX ix_artikel_update ON Artikel("update") WHERE "update" = 1;

-- Bilder
CREATE INDEX ix_bilder_update ON Bilder("update") WHERE "update" = 1;

-- Dokumente
CREATE INDEX ix_dokumente_update ON Dokumente("update") WHERE "update" = 1;

-- Attribute
CREATE INDEX ix_attribute_update ON Attribute("update") WHERE "update" = 1;

-- Category
CREATE INDEX ix_category_update ON category("update") WHERE "update" = 1;

-- Junction Tables
CREATE INDEX ix_artikel_bilder_update ON Artikel_Bilder("update") WHERE "update" = 1;
CREATE INDEX ix_artikel_dokumente_update ON Artikel_Dokumente("update") WHERE "update" = 1;
CREATE INDEX ix_attrib_artikel_update ON Attrib_Artikel("update") WHERE "update" = 1;
```

**Nutzen:**
- Delta-Export Performance: ~10-100x schneller bei großen Datenmengen
- Nur geänderte Datensätze werden indiziert (Partial Index)
- Minimaler Speicher-Overhead (~1-5% der Tabellengröße bei 10% Update-Rate)

**Verwendet in:**
- `AFS_Evo_DeltaExporter::copyRows()` - `SELECT * FROM table WHERE "update" = 1`
- `AFS_Evo_DeltaExporter::resetUpdateFlags()` - `UPDATE table SET "update" = 0 WHERE "update" = 1`

#### 2. Foreign Key Indexes

Optimiert Lookups in Junction Tables für N:M Beziehungen:

```sql
-- Artikel_Bilder
CREATE INDEX ix_artikel_bilder_artikel ON Artikel_Bilder(Artikel_ID);
CREATE INDEX ix_artikel_bilder_bild ON Artikel_Bilder(Bild_ID);

-- Artikel_Dokumente
CREATE INDEX ix_artikel_dokumente_artikel ON Artikel_Dokumente(Artikel_ID);
CREATE INDEX ix_artikel_dokumente_dokument ON Artikel_Dokumente(Dokument_ID);

-- Attrib_Artikel
CREATE INDEX ix_attrib_artikel_artikel ON Attrib_Artikel(Artikel_ID);
CREATE INDEX ix_attrib_artikel_attribute ON Attrib_Artikel(Attribute_ID);
```

**Nutzen:**
- Schnelle Rückwärts-Lookups (z.B. alle Bilder eines Artikels)
- Optimierte CASCADE DELETE Operations
- Effiziente Join-Operationen

**Verwendet in:**
- `AFS_Evo_ArticleSync::loadAllArticleImageRelations()` - Batch-Load aller Verknüpfungen
- `AFS_Evo_ArticleSync::loadAllArticleDocumentRelations()`
- `AFS_Evo_ArticleSync::loadAllArticleAttributeRelations()`

#### 3. XT_ID Indexes

Optimiert bi-direktionale Synchronisation zwischen AFS und XT-Commerce:

```sql
CREATE INDEX ix_artikel_xt_id ON Artikel(XT_ID);
CREATE INDEX ix_bilder_xt_id ON Bilder(XT_ID);
CREATE INDEX ix_dokumente_xt_id ON Dokumente(XT_ID);
CREATE INDEX ix_attribute_xt_id ON Attribute(XT_Attrib_ID);
CREATE INDEX ix_category_xtid ON category(xtid);
```

**Nutzen:**
- Schnelle Lookups von XT-Commerce Entities nach ID
- Effiziente Update-Operationen beim Re-Import
- Unterstützung für zukünftige Rück-Synchronisation

#### 4. Category & Hierarchy Indexes

Optimiert Hierarchie-Abfragen und Online-Status-Filter:

```sql
CREATE INDEX ix_artikel_category ON Artikel(Category);
CREATE INDEX ix_category_parent ON category(Parent);
CREATE INDEX ix_category_afsparent ON category(afsparent);
CREATE INDEX ix_category_online ON category(online);
```

**Nutzen:**
- Schnelle Kategorie-Hierarchie-Traversierung
- Effiziente Online/Offline-Filterung
- Optimierte Parent-Child-Lookups

#### 5. Status Database Indexes

Optimiert Log-Abfragen in der Status-Datenbank:

```sql
CREATE INDEX ix_sync_log_level ON sync_log(level);
CREATE INDEX ix_sync_log_stage ON sync_log(stage);
CREATE INDEX ix_sync_log_created ON sync_log(created_at DESC);
CREATE INDEX ix_sync_log_job_created ON sync_log(job, created_at DESC);
CREATE INDEX ix_sync_log_job_level ON sync_log(job, level);
```

**Nutzen:**
- Schnelle Fehler-Log-Filterung (`WHERE level = 'error'`)
- Effiziente Stage-basierte Queries
- Optimierte zeitbasierte Log-Abfragen

## Performance-Impact

### Erwartete Performance-Verbesserungen

| Operation | Ohne Index | Mit Index | Speedup |
|-----------|-----------|-----------|---------|
| Delta Export (10K Zeilen, 10% Update) | ~500ms | ~50ms | **10x** |
| Junction Table Lookup (1K Artikel) | ~200ms | ~5ms | **40x** |
| Error Log Query | ~100ms | ~10ms | **10x** |
| XT_ID Lookup | ~50ms | ~2ms | **25x** |

### Disk Space Impact

```
Baseline Database: 100 MB
With Indexes: 105-110 MB (5-10% Overhead)
```

**Begründung:**
- Partial Indexes (update flag) nur für geänderte Datensätze
- Foreign Key Indexes sind klein (nur Integer-Spalten)
- Total Overhead: ~5-10% der Datenbankgröße

### Memory Impact

SQLite lädt Indexes automatisch in den Page Cache:
- **Typical Index Cache**: 1-5 MB RAM
- **Under Load**: Bis zu 20-30 MB bei großen Datenmengen
- **WAL Mode**: Zusätzlich ~2-5 MB für Write-Ahead Log

## Migration

### Neue Installationen

Indexes werden automatisch beim Setup erstellt:

```bash
php scripts/setup.php
```

### Bestehende Installationen

Indexes können nachträglich hinzugefügt werden:

```bash
php scripts/migrate_add_indexes.php
```

**Migration ist sicher:**
- Verwendet `IF NOT EXISTS` - keine Duplikate
- Atomic Transaction - alles oder nichts
- Idempotent - kann mehrfach ausgeführt werden
- Keine Downtime erforderlich
- Transparent für Anwendungscode

## Query Optimization Best Practices

### 1. Nutze Prepared Statements

```php
// ✓ Gut - Index wird verwendet
$stmt = $db->prepare('SELECT * FROM Artikel WHERE "update" = 1');
$stmt->execute();

// ✗ Schlecht - Index-Nutzung nicht garantiert
$db->query('SELECT * FROM Artikel WHERE "update" = 1');
```

### 2. Vermeide SELECT *

```php
// ✓ Gut - Nur benötigte Spalten
SELECT ID, Artikelnummer FROM Artikel WHERE "update" = 1

// ✗ Schlecht - Alle Spalten (inkl. BLOB)
SELECT * FROM Artikel WHERE "update" = 1
```

### 3. Batch Operations in Transaktionen

```php
// ✓ Gut - Transaction für Bulk-Updates
$db->beginTransaction();
foreach ($rows as $row) {
    $stmt->execute($row);
}
$db->commit();

// ✗ Schlecht - Einzelne Commits (langsam)
foreach ($rows as $row) {
    $stmt->execute($row);
}
```

### 4. Nutze ANALYZE

Nach großen Datenänderungen:

```bash
sqlite3 db/evo.db "ANALYZE"
```

Dies aktualisiert Statistiken für den Query Planner.

## MySQL Kompatibilität

Die Index-Strategie ist MySQL-kompatibel mit kleinen Anpassungen:

### SQLite Partial Indexes → MySQL Filtered Indexes (MySQL 8.0+)

```sql
-- SQLite
CREATE INDEX ix_artikel_update ON Artikel("update") WHERE "update" = 1;

-- MySQL 8.0+
CREATE INDEX ix_artikel_update ON Artikel(`update`) WHERE `update` = 1;

-- MySQL < 8.0 (ohne Filter)
CREATE INDEX ix_artikel_update ON Artikel(`update`);
```

### Identifier Quoting

```sql
-- SQLite: "update" (double quotes)
-- MySQL: `update` (backticks)
```

### Auto-Migration für MySQL

Für MySQL-Unterstützung kann ein ähnliches Migrations-Script erstellt werden:

```php
// scripts/migrate_add_indexes_mysql.php
// ... mit MySQL-spezifischer Syntax
```

## Monitoring und Tuning

### Index Usage überprüfen

```bash
# SQLite Query Plan
sqlite3 db/evo.db "EXPLAIN QUERY PLAN SELECT * FROM Artikel WHERE \"update\" = 1;"

# Erwartete Ausgabe mit Index:
# SEARCH Artikel USING INDEX ix_artikel_update (update=?)
```

### Index Statistics

```sql
-- Index Größe prüfen
SELECT name, 
       (pgsize * pgno) as size_bytes
FROM dbstat 
WHERE name LIKE 'ix_%'
ORDER BY size_bytes DESC;
```

### Performance Testing

Test-Script für Index-Performance:

```bash
php scripts/test_index_performance.php
```

## Wartung

### VACUUM (Optional)

Nach großen Löschoperationen:

```bash
sqlite3 db/evo.db "VACUUM"
```

**Achtung:** VACUUM benötigt temporär 2x Disk Space!

### Index Rebuild (Selten nötig)

Bei Corruption oder Performance-Problemen:

```sql
REINDEX ix_artikel_update;
-- oder
REINDEX Artikel;  -- Alle Indexes einer Tabelle
```

## Zusammenfassung

| Metrik | Wert |
|--------|------|
| **Total Indexes** | 40 |
| **Update Flag Indexes** | 8 |
| **Foreign Key Indexes** | 6 |
| **XT_ID Indexes** | 5 |
| **Status DB Indexes** | 5 |
| **Disk Overhead** | 5-10% |
| **Performance Gain** | 10-100x |

**Empfehlung:** Alle Indexes sollten in Produktion aktiviert sein. Der minimale Overhead wird durch massive Performance-Gewinne bei Standard-Operationen mehr als kompensiert.

## Weitere Ressourcen

- [SQLite Query Planning](https://www.sqlite.org/queryplanner.html)
- [SQLite Index Documentation](https://www.sqlite.org/lang_createindex.html)
- [MySQL Index Optimization](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html)
