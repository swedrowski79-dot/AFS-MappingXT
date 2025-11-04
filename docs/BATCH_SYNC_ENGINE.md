# Batch Sync Engine Refactoring

## Übersicht

Die neue **BatchSyncEngine** refaktoriert die Synchronisationsphase für maximale Performance durch:

1. **RAM-Normalisierung**: Alle Rohdaten werden vollständig im Arbeitsspeicher normalisiert und verknüpft
2. **TEMP Staging Tables**: Pro Domäne werden temporäre Staging-Tabellen erstellt
3. **Batch-Insert**: Daten werden stapelweise eingefügt (Beachtung des SQLite-Bindlimits ~999)
4. **Set-basierte Merges**: `INSERT OR REPLACE` für effiziente UPSERT-Operationen

## Architektur

### Phase 1: RAM-Normalisierung

```
Source Data → Expression Evaluation → Foreign Key Resolution → Validation → Normalized Arrays
```

**Vorteile:**
- Alle Daten im RAM verfügbar
- FK-Lookups als In-Memory-Hashtables
- Keine DB-Roundtrips während der Transformation
- Einfaches Debugging (Daten inspizierbar)

### Phase 2: Batch-Write

```
Normalized Arrays → TEMP Staging Tables → Batch Insert → SET-based Merge → Target Tables
```

**Vorteile:**
- Minimale Transaktionszeit
- Optimale SQLite-Performance
- Atomic operations
- Rollback-fähig

## Performance-Verbesserungen

### Vorher (MappingSyncEngine)
- Row-by-row Verarbeitung
- Einzelne UPSERT-Statements
- Multiple DB-Roundtrips pro Row
- FK-Lookups per Query

### Nachher (BatchSyncEngine)
- Batch-Verarbeitung
- SET-basierte Operations
- Nur 2 Hauptphasen (Load → Write)
- FK-Lookups im RAM

### Benchmark-Beispiel

```
3 Artikel synchronisiert:
- Load & Normalize: 0.63 ms
- Write to DB: 1.10 ms
- Total: 1.73 ms
```

## Verwendung

```php
// Erstellen der Engine
$batchEngine = new BatchSyncEngine($sources, $targetMapper, $manifest);

// Entity synchronisieren
$stats = $batchEngine->syncEntity('artikel', $targetDb);

// Statistiken:
// - processed: Anzahl verarbeiteter Zeilen
// - inserted: Neu eingefügte Zeilen
// - updated: Aktualisierte Zeilen
// - errors: Fehler
// - timing: Performance-Metriken (ms)
```

## TEMP Staging Tables

Für jede Ziel-Tabelle wird eine TEMP-Tabelle erstellt:

```sql
-- Beispiel: _stg_artikel
CREATE TEMP TABLE _stg_artikel AS 
SELECT * FROM artikel WHERE 0;

-- Batch Insert
INSERT INTO _stg_artikel (model, name, category, price) 
VALUES 
  (:p0, :p1, :p2, :p3),
  (:p4, :p5, :p6, :p7),
  (:p8, :p9, :p10, :p11);

-- Merge
INSERT OR REPLACE INTO artikel (model, name, category, price)
SELECT model, name, category, price FROM _stg_artikel;

-- Cleanup
DROP TABLE _stg_artikel;
```

## SQLite Bind-Limit

SQLite hat ein Standard-Limit von ~999 Bind-Parametern. Die Engine berechnet automatisch die Batch-Größe:

```php
$maxRowsPerBatch = floor(999 / columnCount);
```

**Beispiel:**
- 5 Spalten → 199 Zeilen pro Batch
- 10 Spalten → 99 Zeilen pro Batch
- 50 Spalten → 19 Zeilen pro Batch

## Foreign Key Resolution

FK-Lookups werden einmalig vor der Normalisierung geladen:

```php
$lookups = [
    'category_by_afs_id' => ['WG001' => 1, 'WG002' => 2, ...],
    'artikel_by_model' => ['ART001' => 1, 'ART002' => 2, ...],
    'attribute_by_name' => ['color' => 1, 'size' => 2, ...],
];
```

Während der Normalisierung erfolgt dann O(1) Lookup:

```php
$categoryId = $lookups['category_by_afs_id'][$afsId] ?? 0;
```

## Migration von MappingSyncEngine

### Kompatibilität

Die BatchSyncEngine implementiert die gleiche Public API:

```php
// Beide Engines unterstützen:
- listEntityNames(): array
- hasFileCatcherSources(): bool
- syncEntity(string $entityName, PDO $targetDb): array
```

### Schrittweise Migration

1. **Testen**: BatchSyncEngine parallel testen
2. **Vergleichen**: Statistiken und Ergebnisse vergleichen
3. **Umstellen**: In SyncService Engine austauschen
4. **Optimieren**: Batch-Größen ggf. anpassen

### Wrapper für Transparenz

```php
class SyncEngineFactory
{
    public static function create($sources, $targetMapper, $manifest, $useBatch = true)
    {
        if ($useBatch) {
            return new BatchSyncEngine($sources, $targetMapper, $manifest);
        }
        return new MappingSyncEngine($sources, $targetMapper, $manifest);
    }
}
```

## Features

### ✅ Implementiert

- [x] RAM-Normalisierung aller Daten
- [x] TEMP Staging Tables
- [x] Batch-Insert mit SQLite Bind-Limit
- [x] SET-basierte Merge (INSERT OR REPLACE)
- [x] FK-Resolution im RAM
- [x] Performance-Tracking
- [x] Transaktions-Sicherheit
- [x] Artikel Master/Variant Sorting
- [x] Category Path Building

### 🚧 TODO

- [ ] Orphan Policy (batch mode)
- [ ] Delta Change Detection
- [ ] Flag Application (on_insert, on_update)
- [ ] SEO Slug Policy
- [ ] Deduplizierung (attribute table)
- [ ] ON CONFLICT DO UPDATE für neuere SQLite (3.24.0+)

## Konfiguration

### Manifest

Keine Änderungen am Manifest erforderlich:

```yaml
entities:
  artikel:
    from: afs.Artikel
    map:
      evo.artikel.model: afs.Artikel.Artikelnummer
      evo.artikel.name: afs.Artikel.Bezeichnung
```

### Environment

Für sehr große Datenmengen kann PHP Memory Limit erhöht werden:

```php
ini_set('memory_limit', '512M'); // oder höher
```

## Troubleshooting

### Memory Limit

Bei sehr großen Datenmengen (>100k Zeilen):

```php
// Strategie: Chunked Processing
foreach ($entityNames as $entity) {
    $stats = $engine->syncEntity($entity, $targetDb);
    gc_collect_cycles(); // Speicher freigeben
}
```

### SQLite Lock

Bei parallelen Zugriffen:

```php
$pdo->exec('PRAGMA busy_timeout = 5000'); // 5 Sekunden Timeout
```

### Performance-Tuning

```php
// Vor dem Sync
$pdo->exec('PRAGMA synchronous = OFF');
$pdo->exec('PRAGMA journal_mode = MEMORY');
$pdo->exec('PRAGMA temp_store = MEMORY');

// Nach dem Sync
$pdo->exec('PRAGMA synchronous = NORMAL');
$pdo->exec('PRAGMA optimize');
```

## Tests

```bash
# Unit-Tests
php scripts/test_batch_sync_engine.php

# Integration mit echten Daten
php scripts/test_batch_sync_integration.php
```

## Weitere Informationen

- Siehe: `BatchSyncEngine.php` für Implementierungsdetails
- Siehe: `MappingSyncEngine.php` für ursprüngliche Implementierung
- Siehe: `test_batch_sync_engine.php` für Beispiele
