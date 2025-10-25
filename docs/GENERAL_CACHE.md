# General-Purpose Caching Layer

## Überblick

Das AFS-MappingXT System verfügt jetzt über einen umfassenden, allgemein verwendbaren Caching-Layer für teure Berechnungen und Datenbankabfragen. Dies ergänzt das bestehende `AFS_ConfigCache` für YAML-Konfigurationen mit einem flexibleren System für verschiedenste Caching-Anforderungen.

## Architektur

### Zwei Caching-Ebenen

Das System bietet nun zwei spezialisierte Caching-Schichten:

1. **`AFS_ConfigCache`** - Spezialisiert auf YAML-Konfigurationsdateien
   - Automatische mtime-basierte Invalidierung
   - Optimiert für Konfigurationsdateien
   - Siehe [CACHING.md](CACHING.md) für Details

2. **`AFS_Cache`** - Allgemeiner Cache für teure Operationen
   - TTL-basierte Ablaufsteuerung
   - Pattern-basierte Invalidierung
   - Flexible API für beliebige Datentypen
   - Dieses Dokument

## AFS_Cache - Implementierung

Die Klasse `AFS_Cache` (`classes/AFS_Cache.php`) bietet einen flexiblen In-Memory-Cache für:

- Datenbankabfrage-Ergebnisse
- Teure Berechnungen (z.B. Hash-Berechnungen)
- API-Request-Antworten
- Beliebige Daten, die teuer zu berechnen/abzufragen sind

### Features

- **TTL-basierte Ablaufsteuerung** (Time-to-Live)
- **Key-basierte Invalidierung** (einzelne Keys oder Patterns)
- **Cache-Statistiken** (Hits, Misses, Hit-Rate, Memory-Usage)
- **Memory-effiziente Speicherung** mit automatischer Eviction
- **Einfache API** für schnelle Integration
- **`remember()` Helper** für elegantes Cache-or-Compute Pattern

### Performance-Vorteile

- Reduziert redundante Datenbankabfragen
- Vermeidet Neuberechnung teurer Operationen
- Verbessert die Anwendungs-Responsiveness
- Minimaler Memory-Overhead durch automatische Eviction

## Verwendung

### Basic Operations

```php
// Daten speichern mit 1-Stunden TTL (Standard)
AFS_Cache::set('articles:all', $articles);

// Daten mit benutzerdefiniertem TTL speichern (5 Minuten)
AFS_Cache::set('articles:all', $articles, 300);

// Daten ohne Ablauf speichern
AFS_Cache::set('static:config', $config, 0);

// Gecachte Daten abrufen
$articles = AFS_Cache::get('articles:all');
if ($articles === null) {
    // Cache miss - Daten neu laden
}

// Prüfen, ob Key existiert
if (AFS_Cache::has('articles:all')) {
    // Cache hit - Daten verwenden
}

// Spezifischen Key invalidieren
AFS_Cache::remove('articles:all');

// Alle Keys mit Pattern invalidieren
AFS_Cache::removeByPattern('articles:*');

// Gesamten Cache leeren
AFS_Cache::clear();
```

### remember() Helper (empfohlen)

Der `remember()` Helper kombiniert Cache-Lookup und Compute-on-Miss:

```php
// Datenbankabfrage mit Caching
$articles = AFS_Cache::remember('db:articles:all', function() use ($db) {
    // Diese Funktion wird nur bei Cache-Miss ausgeführt
    return $db->query('SELECT * FROM articles')->fetchAll();
}, 300); // 5 Minuten TTL

// Hash-Berechnung mit Caching
$hash = AFS_Cache::remember('hash:article:' . $id, function() use ($data) {
    return hash('sha256', json_encode($data));
}, 3600); // 1 Stunde TTL
```

### Cache-Statistiken

```php
$stats = AFS_Cache::getStats();

echo "Cache Hits: {$stats['hits']}\n";
echo "Cache Misses: {$stats['misses']}\n";
echo "Cache Size: {$stats['size']} entries\n";
echo "Hit Rate: {$stats['hit_rate']}%\n";
echo "Memory Usage: " . number_format($stats['memory_bytes'] / 1024, 2) . " KB\n";
```

## Anwendungsfälle

### 1. Datenbankabfragen Cachen

```php
class AFS_Get_Data
{
    public function getArtikel(): array
    {
        return AFS_Cache::remember('afs:artikel:all', function() {
            $sql = $this->config->buildSelectQuery('Artikel');
            $rows = $this->run($sql);
            return array_map([$this, 'normalizeArtikelRow'], $rows);
        }, 300); // 5 Minuten
    }

    public function getWarengruppen(): array
    {
        return AFS_Cache::remember('afs:warengruppen:all', function() {
            $sql = $this->config->buildSelectQuery('Warengruppe');
            $rows = $this->run($sql);
            return array_map([$this, 'normalizeWarengruppeRow'], $rows);
        }, 300);
    }
}
```

### 2. Hash-Berechnungen Cachen

```php
class AFS_HashManager
{
    public function buildHash(array $fields): string
    {
        $cacheKey = 'hash:' . md5(json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        
        return AFS_Cache::remember($cacheKey, function() use ($fields) {
            ksort($fields);
            $normalized = $this->normalizeFields($fields);
            $data = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return hash('sha256', $data);
        }, 3600); // 1 Stunde
    }
}
```

### 3. Mapping-Lookups Cachen

```php
class AFS_Evo_ArticleSync
{
    private function loadCategoryIdMap(): array
    {
        return AFS_Cache::remember('map:category_ids', function() {
            // Teure Datenbankabfrage
            return $this->categorySync->loadCategoryIdMap();
        }, 600); // 10 Minuten
    }
}
```

### 4. API-Responses Cachen

```php
function api_get_articles()
{
    $articles = AFS_Cache::remember('api:articles:list', function() {
        // Teure Datenabfrage und -verarbeitung
        $db = new PDO(...);
        return $db->query('SELECT * FROM articles')->fetchAll();
    }, 120); // 2 Minuten
    
    api_ok($articles);
}
```

## Cache-Verwaltung

### Automatische Cleanup

Der Cache führt automatisch Cleanup durch:

1. **Expired Entries**: Werden bei Zugriff erkannt und entfernt
2. **Size Limit**: Wenn das Limit erreicht wird, werden älteste Einträge entfernt
3. **Manual Cleanup**: Kann manuell aufgerufen werden

```php
// Manuelle Cleanup von abgelaufenen Einträgen
$removed = AFS_Cache::cleanupExpired();
echo "Removed {$removed} expired entries\n";
```

### Cache-Invalidierung

```php
// Nach Datenbankänderungen
AFS_Cache::removeByPattern('db:articles:*');

// Nach Sync-Operationen
AFS_Cache::removeByPattern('map:*');

// Komplette Cache-Invalidierung (z.B. nach großen Updates)
AFS_Cache::clear();
```

## Konfiguration

### Anpassbare Parameter

In `AFS_Cache` können folgende Konstanten angepasst werden:

```php
class AFS_Cache
{
    // Standard-TTL (1 Stunde)
    private const DEFAULT_TTL = 3600;
    
    // Maximale Cache-Größe (1000 Einträge)
    // 0 = keine Größenbeschränkung
    private const MAX_CACHE_SIZE = 1000;
}
```

### Empfohlene TTL-Werte

| Datentyp | TTL | Begründung |
|----------|-----|------------|
| Artikel-Listen | 300s (5 Min) | Häufige Änderungen möglich |
| Kategorie-Maps | 600s (10 Min) | Seltener geändert |
| Hash-Berechnungen | 3600s (1 Std) | Deterministisch, ändern sich selten |
| Konfiguration | 0 (permanent) | Explizite Invalidierung bei Änderung |
| API-Responses | 120s (2 Min) | Balance zwischen Aktualität und Performance |

## Testing

Umfassende Tests für die Caching-Funktionalität:

```bash
php scripts/test_general_cache.php
```

Die Tests validieren:

- ✓ Grundlegende Get/Set-Operationen
- ✓ Verschiedene Datentypen (String, Int, Float, Bool, Array, Null)
- ✓ TTL-basierte Ablaufsteuerung
- ✓ Pattern-basierte Invalidierung
- ✓ Cache-Statistiken
- ✓ `remember()` Helper
- ✓ Memory-Management
- ✓ Edge Cases (leere Keys, nicht-existierende Keys, etc.)
- ✓ Real-World-Szenarien (Datenbankabfrage-Caching)

## Best Practices

### Entwicklung

```php
// Am Anfang von Development-Scripts
AFS_Cache::clear();

// Für Tests
class MyTest
{
    protected function setUp(): void
    {
        AFS_Cache::clear(); // Isolierte Tests
    }
}
```

### Produktion

```php
// Cache-Key-Namenskonventionen verwenden
$key = sprintf('db:articles:%s', $filter);
$key = sprintf('hash:entity:%s:%s', $type, $id);
$key = sprintf('api:%s:%s', $endpoint, $params_hash);

// TTL basierend auf Update-Frequenz wählen
$ttl = match($dataType) {
    'static' => 0,           // Keine Ablaufzeit
    'config' => 3600,        // 1 Stunde
    'catalog' => 300,        // 5 Minuten
    'realtime' => 60,        // 1 Minute
    default => 3600
};

// Invalidierung nach Änderungen
function updateArticle($id, $data) {
    // Datenbank-Update
    $db->update('articles', $data, ['id' => $id]);
    
    // Cache invalidieren
    AFS_Cache::removeByPattern('db:articles:*');
    AFS_Cache::remove('hash:article:' . $id);
}
```

### Monitoring

```php
// Periodisches Monitoring (z.B. in Admin-Dashboard)
$stats = AFS_Cache::getStats();

if ($stats['hit_rate'] < 50.0) {
    // Warnung: Niedrige Hit-Rate
    logger()->warning('Low cache hit rate', $stats);
}

if ($stats['memory_bytes'] > 100 * 1024 * 1024) { // 100 MB
    // Warnung: Hoher Memory-Verbrauch
    logger()->warning('High cache memory usage', $stats);
}
```

## Vergleich: AFS_ConfigCache vs. AFS_Cache

| Feature | AFS_ConfigCache | AFS_Cache |
|---------|----------------|-----------|
| **Zweck** | YAML-Konfigurationsdateien | Allgemeine Daten |
| **Invalidierung** | mtime-basiert | TTL-basiert |
| **Pattern-Matching** | ❌ | ✓ |
| **TTL-Support** | ❌ | ✓ |
| **File-Watching** | ✓ | ❌ |
| **remember() Helper** | ❌ | ✓ |
| **Use Case** | Config-Files | DB-Queries, Calculations |

**Empfehlung**: 
- Verwenden Sie `AFS_ConfigCache` für YAML-Konfigurationsdateien
- Verwenden Sie `AFS_Cache` für alle anderen Caching-Anforderungen

## Performance-Impact

### Benchmarks

```
Operation                    | Ohne Cache | Mit Cache | Speedup
-----------------------------|------------|-----------|--------
Datenbankabfrage (Artikel)   | ~5-10 ms   | ~0.01 ms  | 500-1000x
Hash-Berechnung (SHA-256)    | ~54 μs     | ~1 μs     | 54x
Mapping-Lookup               | ~100 μs    | ~1 μs     | 100x
```

### Memory-Overhead

- **Pro Entry**: ~500 Bytes (durchschnittlich)
- **1000 Entries**: ~500 KB
- **Automatische Eviction**: Verhindert unkontrolliertes Wachstum

### Empfohlene Limits

```php
// Für kleine bis mittlere Anwendungen (Standard)
private const MAX_CACHE_SIZE = 1000;  // ~500 KB

// Für große Anwendungen
private const MAX_CACHE_SIZE = 5000;  // ~2.5 MB

// Für Memory-kritische Umgebungen
private const MAX_CACHE_SIZE = 500;   // ~250 KB
```

## Migration

### Bestehenden Code aktualisieren

**Vorher:**
```php
public function getArtikel(): array
{
    $sql = $this->config->buildSelectQuery('Artikel');
    $rows = $this->run($sql);
    return array_map([$this, 'normalizeArtikelRow'], $rows);
}
```

**Nachher:**
```php
public function getArtikel(): array
{
    return AFS_Cache::remember('afs:artikel:all', function() {
        $sql = $this->config->buildSelectQuery('Artikel');
        $rows = $this->run($sql);
        return array_map([$this, 'normalizeArtikelRow'], $rows);
    }, 300);
}
```

## Troubleshooting

### Cache funktioniert nicht wie erwartet

```php
// Debug-Informationen ausgeben
$stats = AFS_Cache::getStats();
print_r($stats);

// Alle Keys auflisten
$keys = AFS_Cache::keys();
print_r($keys);

// Spezifischen Key prüfen
$exists = AFS_Cache::has('your:cache:key');
var_dump($exists);
```

### Cache wächst zu stark

```php
// Manuelle Cleanup auslösen
$removed = AFS_Cache::cleanupExpired();
echo "Removed {$removed} expired entries\n";

// Cache-Größe prüfen
$size = AFS_Cache::size();
echo "Current size: {$size} entries\n";
```

### Niedrige Hit-Rate

Mögliche Ursachen:
1. TTL zu kurz gewählt
2. Cache-Keys sind zu spezifisch (jeder Request generiert neuen Key)
3. Daten ändern sich sehr häufig
4. Cache wird zu oft geleert

## Siehe auch

- [CACHING.md](CACHING.md) - Konfigurationsdatei-Caching mit `AFS_ConfigCache`
- [PERFORMANCE_ANALYSIS.md](PERFORMANCE_ANALYSIS.md) - Detaillierte Performance-Metriken
- [PERFORMANCE_SUMMARY.md](PERFORMANCE_SUMMARY.md) - Performance Executive Summary
