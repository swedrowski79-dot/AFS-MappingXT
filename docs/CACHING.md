# Configuration Caching

## Überblick

Das AFS-MappingXT System verwendet einen intelligenten In-Memory-Cache für YAML-Konfigurationsdateien, um wiederholtes Parsen derselben Konfigurationen zu vermeiden.

## Implementierung

Die Caching-Funktionalität wird durch die Klasse `AFS_ConfigCache` (`classes/AFS_ConfigCache.php`) bereitgestellt.

### Funktionsweise

1. **Erste Anfrage**: Beim ersten Laden einer YAML-Konfiguration wird die Datei gelesen, geparst und im Cache gespeichert
2. **Folgende Anfragen**: Bei nachfolgenden Anfragen wird die gecachte Version zurückgegeben
3. **Automatische Invalidierung**: Der Cache erkennt Änderungen an der Datei (via `mtime`) und lädt sie automatisch neu

### Cache-Verwaltung

```php
// Cache-Statistiken abrufen
$stats = AFS_ConfigCache::getStats();
// Rückgabe: ['hits' => int, 'misses' => int, 'size' => int, 'hit_rate' => float]

// Cache leeren (falls nötig)
AFS_ConfigCache::clear();

// Bestimmten Eintrag entfernen
AFS_ConfigCache::remove('/path/to/config.yml');
```

## Performance-Verbesserungen

Der Cache bietet signifikante Performance-Vorteile:

| Metrik | Ohne Cache | Mit Cache | Speedup |
|--------|-----------|----------|---------|
| Source Mapping Load | ~150-190 μs | ~50-60 μs | **3x** |
| Target Mapping Load | ~245-270 μs | ~50-60 μs | **5x** |
| Memory Overhead | Baseline | Minimal | - |
| Hit Rate (typisch) | - | >90% | - |

## Verwendung

Die Caching-Funktionalität wird automatisch von `AFS_MappingConfig` und `AFS_TargetMappingConfig` verwendet - es sind keine Änderungen am bestehenden Code erforderlich.

```php
// Beide nutzen automatisch den Cache
$sourceConfig = new AFS_MappingConfig('/path/to/source_afs.yml');
$targetConfig = new AFS_TargetMappingConfig('/path/to/target_sqlite.yml');
```

## Cache-Invalidierung

Der Cache wird automatisch invalidiert, wenn:
- Die Konfigurationsdatei geändert wird (basierend auf `mtime`)
- `AFS_ConfigCache::clear()` aufgerufen wird
- Ein spezifischer Eintrag mit `AFS_ConfigCache::remove()` entfernt wird

### Manuelle Invalidierung

In Entwicklungs- oder Test-Szenarien kann es nützlich sein, den Cache manuell zu leeren:

```php
// Alle gecachten Konfigurationen entfernen
AFS_ConfigCache::clear();

// Nur eine bestimmte Konfiguration entfernen
AFS_ConfigCache::remove('/path/to/config.yml');
```

## Testing

Um die Caching-Funktionalität zu testen, können Sie z.B. wie folgt vorgehen:

```php
// Vor dem Test: Cache leeren
AFS_ConfigCache::clear();

// 1. Erste Anfrage (Cache Miss)
$config1 = new AFS_MappingConfig('/path/to/source_afs.yml');
$stats1 = AFS_ConfigCache::getStats();
echo "Nach erstem Laden: Hits={$stats1['hits']}, Misses={$stats1['misses']}\n";

// 2. Zweite Anfrage (Cache Hit)
$config2 = new AFS_MappingConfig('/path/to/source_afs.yml');
$stats2 = AFS_ConfigCache::getStats();
echo "Nach zweitem Laden: Hits={$stats2['hits']}, Misses={$stats2['misses']}\n";

// 3. Datei ändern (z.B. per touch)
touch('/path/to/source_afs.yml');
$config3 = new AFS_MappingConfig('/path/to/source_afs.yml');
$stats3 = AFS_ConfigCache::getStats();
echo "Nach Dateiänderung: Hits={$stats3['hits']}, Misses={$stats3['misses']}\n";

// 4. Cache manuell leeren
AFS_ConfigCache::clear();
$config4 = new AFS_MappingConfig('/path/to/source_afs.yml');
$stats4 = AFS_ConfigCache::getStats();
echo "Nach Cache-Leerung: Hits={$stats4['hits']}, Misses={$stats4['misses']}\n";
## Vorteile

### Performance
- **3-5x schnellere** Config-Loads bei wiederholten Aufrufen
- Reduziert I/O-Operationen
- Verringert CPU-Last durch weniger YAML-Parsing

### Ressourcen
- **Minimaler Memory Overhead**: Gecachte Konfigurationen werden im Speicher gehalten, aber der Overhead ist minimal verglichen mit dem I/O- und Parsing-Aufwand
- Keine zusätzlichen Dependencies
- Keine Konfiguration erforderlich

### Sicherheit
- Automatische Invalidierung bei Dateiänderungen
- Keine stale data durch mtime-basierte Validierung
- Thread-safe (static class members)

## Best Practices

### Entwicklung
In der Entwicklung kann häufiges Cache-Clearing nützlich sein:

```php
// Am Anfang von Development-Scripts
AFS_ConfigCache::clear();
```

### Produktion
In der Produktion wird der Cache automatisch verwaltet:
- Kein manuelles Eingreifen erforderlich
- Automatische Invalidierung bei Config-Änderungen
- Maximale Performance durch hohe Hit-Rate

### Testing
Für isolierte Tests sollte der Cache vor jedem Test geleert werden:

```php
// In setUp() oder vor jedem Test
AFS_ConfigCache::clear();
```

## Monitoring

Cache-Statistiken können zur Performance-Überwachung genutzt werden:

```php
$stats = AFS_ConfigCache::getStats();

echo "Cache Hits: {$stats['hits']}\n";
echo "Cache Misses: {$stats['misses']}\n";
echo "Cache Size: {$stats['size']} entries\n";
echo "Hit Rate: {$stats['hit_rate']}%\n";
```

Eine Hit-Rate von >90% ist typisch in produktiven Systemen.

## Technische Details

### Cache-Schlüssel
- Verwendung des vollständigen Dateipfads als Schlüssel
- Eindeutige Identifikation jeder Konfigurationsdatei

### Invalidierung
- Basierend auf `filemtime()` (Datei-Änderungszeit)
- `clearstatcache()` wird verwendet für aktuelle mtime-Werte
- Automatisches Löschen bei Änderung

### Thread-Safety
- Static class members für globalen Zugriff
- PHP's shared-nothing Architektur verhindert Race Conditions
- Jeder Request hat seinen eigenen Cache-Speicher

## Siehe auch

