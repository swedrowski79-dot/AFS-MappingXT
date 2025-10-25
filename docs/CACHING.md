# Configuration Caching

## Übersicht

Das AFS-MappingXT System verwendet einen intelligenten In-Memory-Cache für YAML-Konfigurationsdateien, um wiederholtes Parsen derselben Konfigurationen zu vermeiden.

## Implementierung

Die Caching-Funktionalität wird durch die Klasse `AFS_ConfigCache` bereitgestellt.

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
| Memory Overhead | - | 0 Bytes | - |
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

Die Caching-Funktionalität wird durch umfassende Tests abgedeckt:

```bash
php scripts/test_config_cache.php
```

Der Test validiert:
- ✓ Cache kann Daten speichern und abrufen
- ✓ Statistiken werden korrekt erfasst
- ✓ Cache wird bei Dateiänderungen invalidiert
- ✓ Clear entfernt alle Einträge
- ✓ Remove löscht spezifische Einträge
- ✓ Has-Methode prüft Cache-Präsenz korrekt
- ✓ Integration mit AFS_MappingConfig
- ✓ Integration mit AFS_TargetMappingConfig
- ✓ Performance-Verbesserungen sind messbar
- ✓ Nicht existierende Dateien werden korrekt behandelt

## Vorteile

### Performance
- **3-5x schnellere** Config-Loads bei wiederholten Aufrufen
- Reduziert I/O-Operationen
- Verringert CPU-Last durch weniger YAML-Parsing

### Ressourcen
- **Zero Memory Overhead**: PHP optimiert automatisch
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

- [Performance Analysis](PERFORMANCE_ANALYSIS.md) - Detaillierte Performance-Metriken
- [Performance Summary](PERFORMANCE_SUMMARY.md) - Executive Summary
- [Configuration Management](CONFIGURATION_MANAGEMENT.md) - Allgemeine Konfiguration
