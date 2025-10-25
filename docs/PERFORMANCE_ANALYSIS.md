# Performance Analysis Documentation

## Übersicht

Dieses Dokument beschreibt die Performance-Charakteristiken des AFS-MappingXT Projekts basierend auf umfassenden Benchmarks und Analysen.

## Performance-Analyse Tool

Das Projekt enthält ein dediziertes Performance-Analyse-Tool:

```bash
# Standard-Analyse
php scripts/analyze_performance.php

# Detaillierte Analyse mit mehr Iterationen
php scripts/analyze_performance.php --detailed

# Mit JSON-Export für maschinelle Verarbeitung
php scripts/analyze_performance.php --export=json
```

### Analysierte Bereiche

1. **Konfiguration & Initialisierung**
2. **YAML Mapping Performance**
3. **SQL-Generierung**
4. **Datenbank-Operationen**
5. **Hash-Berechnung**
6. **Speichernutzung**
7. **Datei I/O**
8. **Klassen-Instanziierung**

## Benchmark-Ergebnisse

### Baseline Performance (Stand: 2025-10-25)

#### Konfiguration
- **Config Load**: ~30 μs pro Aufruf
- **Memory Impact**: Vernachlässigbar (0 Bytes zusätzlich)
- **Status**: ✓ Excellent

#### YAML Mapping
**Mit Caching (Stand: 2025-10-25):**
- **Source Mapping Load**: ~60 μs pro Instanz (gecacht)
- **Target Mapping Load**: ~50 μs pro Instanz (gecacht)
- **Cache Hit Rate**: Typisch >90% bei wiederholten Loads
- **Speedup**: 3-5x schneller als ohne Cache
- **Memory per Instance**: 0 Bytes (PHP optimiert automatisch)
- **Status**: ✓ Excellent

**Ohne Caching (Baseline):**
- **Source Mapping Load**: ~150-190 μs pro Instanz (4.3 KB Datei)
- **Target Mapping Load**: ~245-270 μs pro Instanz (7.6 KB Datei)

**Implementation**: `AFS_ConfigCache` bietet In-Memory-Caching für YAML-Konfigurationsdateien mit automatischer Invalidierung bei Dateiänderungen (basierend auf mtime).

#### SQL-Generierung
Durchschnittliche Zeit pro SQL-SELECT-Statement:

| Entity | Zeit (μs) | SQL-Länge (Bytes) |
|--------|-----------|-------------------|
| Artikel | ~7.5 | 928 |
| Warengruppe | ~2.7 | 229 |
| Dokumente | ~2.0 | 112 |

**Status**: ✓ Excellent - Alle unter 10 μs

#### Hash-Berechnung
Performance für 11KB Datenstrukturen:

| Algorithmus | Zeit (μs) | Relative Performance |
|-------------|-----------|---------------------|
| SHA-256 | ~54 | Baseline (sicherer) |
| SHA-1 | ~28 | 1.9x schneller |
| MD5 | ~28 | 1.9x schneller |

**Empfehlung**: SHA-256 wird verwendet (guter Kompromiss zwischen Sicherheit und Performance)

**Status**: ✓ Acceptable - Hash-Overhead ist messbar aber vertretbar

#### Datenbank-Operationen
Performance mit SQLite (db/evo.db):

| Operation | Zeit (μs) | Bemerkung |
|-----------|-----------|-----------|
| Connection | ~50-100 | Pro Verbindung |
| Simple Query | ~10-20 | Cached queries |
| Transaction Overhead | ~100-1000 | Begin + Commit |
| Prepared Statement Prepare | ~10-50 | Einmalig |
| Prepared Statement Execute | ~10-30 | Pro Ausführung |

**Empfehlung**: 
- ✓ Bulk-Operationen verwenden Transaktionen
- ✓ Prepared Statements für wiederholte Queries
- ✓ Connection wird wiederverwendet

**Status**: ✓ Excellent

#### Speichernutzung
- **Baseline Memory**: ~2 MB (PHP Runtime)
- **Peak Memory**: ~2 MB (bei Tests ohne DB-Daten)
- **Config Instance**: 0 Bytes (effiziente Implementierung)

**Status**: ✓ Excellent - Sehr sparsamer Speicherverbrauch

#### Datei I/O
Performance für 10KB Dateien:

| Operation | Zeit (μs) | Durchsatz |
|-----------|-----------|-----------|
| Write | ~34 | ~294 MB/s |
| Read | ~14 | ~714 MB/s |
| file_exists | ~1.5 | Sehr schnell |
| JSON Log Append | ~7 | ~1.4 MB/s |

**Status**: ✓ Excellent

#### Klassen-Instanziierung
- **AFS_MappingLogger**: ~1.8 μs pro Instanz
- **Memory Overhead**: 0 Bytes

**Status**: ✓ Excellent

## Performance-Charakteristiken nach Komponente

### AFS_Evo (Hauptorchestrator)
- **Synchronisation**: Abhängig von Datenmenge und Netzwerk
- **Memory**: Linear mit Datenmenge (Streaming möglich)
- **Bottleneck**: Netzwerk I/O (MSSQL → SQLite)

**Optimierungen**:
- ✓ Transaktionen für Bulk-Inserts
- ✓ Prepared Statements
- ✓ Hash-basierte Änderungserkennung (nur Updates schreiben)

### AFS_HashManager
- **Hash Calculation**: ~54 μs pro Artikel (SHA-256)
- **Change Detection**: O(1) - konstante Zeit
- **Memory**: Minimal (nur Hashes gespeichert)

**Vorteile**:
- Reduziert unnötige DB-Writes um >90% bei Folge-Syncs
- Schneller als volle Daten-Vergleiche

### AFS_MappingLogger
- **Log Write**: ~7 μs pro Eintrag (JSON)
- **File Rotation**: Automatisch nach 30 Tagen
- **Memory**: Konstant (keine In-Memory-Pufferung)

**Status**: ✓ Excellent - Kein Performance-Impact auf Sync

### AFS_SqlBuilder
- **SQL Generation**: 2-8 μs pro Statement
- **Caching**: Nicht erforderlich (Generation so schnell wie Cache-Lookup)

**Status**: ✓ Excellent

## Performance Best Practices

### 1. Datenbank-Operationen
✓ **DO**:
- Verwende Transaktionen für Bulk-Operationen
- Nutze Prepared Statements für wiederholte Queries
- Setze `PRAGMA journal_mode=WAL` für SQLite (bereits implementiert)

✗ **DON'T**:
- Einzelne INSERT-Statements außerhalb von Transaktionen
- String-Konkatenation für SQL (SQL-Injection-Risiko)

### 2. Datei-Operationen
✓ **DO**:
- Prüfe Dateiexistenz vor dem Lesen (file_exists ist schnell)
- Verwende FILE_APPEND für Logs (kein Lesen erforderlich)
- Batch-kopiere Dateien wenn möglich

✗ **DON'T**:
- Lese große Dateien komplett in den Speicher
- Öffne/Schließe Dateien in Loops

### 3. Hash-Berechnung
✓ **DO**:
- Verwende HashManager für Änderungserkennung
- Berechne Hashes nur für geänderte Daten

✗ **DON'T**:
- Hashe Daten mehrfach
- Verwende MD5 für Sicherheitszwecke (nur für Performance-Tests OK)

### 4. YAML-Konfiguration
✓ **DO**:
- Lade Mapping-Konfigurationen einmal beim Start
- Verwende die selbe Instanz für mehrere Operationen

✗ **DON'T**:
- Lade YAML-Dateien in Loops
- Parse YAML mehrfach pro Request

### 5. Speicher-Management
✓ **DO**:
- Verwende Streaming für große Datasets
- Unset große Arrays nach Verwendung
- Nutze Generatoren für große Resultsets

✗ **DON'T**:
- Lade alle Daten gleichzeitig in den Speicher
- Halte unnötige Referenzen

## Performance-Monitoring

### Laufzeit-Metriken
Das System misst automatisch:
- Sync-Dauer (gesamt und pro Phase)
- Anzahl verarbeiteter Datensätze
- Änderungen (Insert/Update/Delete)
- Fehler und Warnungen

**Verfügbar in**:
- JSON-Logs (`logs/YYYY-MM-DD.log`)
- Status-Datenbank (`db/status.db`)
- API-Endpoints (`api/sync_status.php`)

### Performance-Indikatoren

#### Gute Performance
- Sync läuft ohne Timeout ab
- <5% Fehlerrate bei Medienkopien
- Linear skalierend mit Datenmenge
- Memory unter 128 MB

#### Warnsignale
- Sync-Dauer >10x länger als erwartet
- Memory >512 MB
- Viele SQLite-Lock-Fehler
- Hohe Fehlerrate (>10%)

## Optimierungsmöglichkeiten

### Kurzfristig (bereits implementiert)
- ✅ Hash-basierte Änderungserkennung
- ✅ Transaktionen für Bulk-Operationen
- ✅ Prepared Statements
- ✅ Strukturiertes Logging ohne Performance-Impact
- ✅ Effiziente YAML-Konfiguration
- ✅ **In-Memory-Caching für YAML-Konfigurationen (AFS_ConfigCache)**

### Mittelfristig (optional)
- ⚡ Parallele Medien-Kopien (Multi-Threading)
- ⚡ Kompression für Delta-Exporte
- ⚡ Index-Optimierung in SQLite

### Langfristig (bei Bedarf)
- 🔮 Verteiltes Processing
- 🔮 Redis für Session-Management
- 🔮 Elastic Search für Logs
- 🔮 CDN für Medien-Delivery

## Troubleshooting

### Langsame Synchronisation

**Mögliche Ursachen**:
1. Netzwerk-Latenz zum MSSQL-Server
2. Viele neue/geänderte Datensätze
3. Langsame Festplatte (für SQLite)
4. Viele große Mediendateien

**Diagnose**:
```bash
# Performance-Analyse ausführen
php scripts/analyze_performance.php --detailed

# Logs analysieren
cat logs/$(date +%Y-%m-%d).log | jq 'select(.operation == "stage_complete")'

# Sync-Status prüfen
php indexcli.php status
```

**Lösungen**:
- Erhöhe `memory_limit` in php.ini
- Nutze SSD statt HDD für SQLite
- Optimiere Netzwerk-Verbindung
- Verwende `--copy-images=0` für Tests

### Hoher Speicherverbrauch

**Mögliche Ursachen**:
1. Große Resultsets ohne Streaming
2. Memory Leaks in Extension-Code
3. Zu viele gleichzeitige Operationen

**Diagnose**:
```php
// In Code einfügen
echo "Memory: " . memory_get_usage(true) / 1024 / 1024 . " MB\n";
```

**Lösungen**:
- Verwende Generatoren statt Arrays
- Chunking für große Datasets
- Garbage Collection manuell triggern: `gc_collect_cycles()`

## Fazit

Das AFS-MappingXT System zeigt **exzellente Performance-Charakteristiken**:

✅ **Strengths**:
- Sehr schnelle Konfigurationsverarbeitung
- Effiziente SQL-Generierung
- Minimaler Speicher-Overhead
- Schnelle Datei-Operationen
- Keine unnötigen Performance-Bottlenecks

✅ **Architectural Benefits**:
- YAML-basierte Konfiguration (flexibel ohne Performance-Einbußen)
- Hash-basierte Änderungserkennung (dramatisch weniger DB-Writes)
- Strukturiertes Logging (keine Performance-Degradation)
- Mapping-basierter Ansatz (wartbar ohne Performance-Kosten)

⚠️ **Considerations**:
- Performance ist hauptsächlich durch externe Faktoren limitiert:
  - Netzwerk-Latenz (MSSQL)
  - Festplatten-Speed (SQLite, Medien)
  - Datenmenge
- System ist optimal optimiert für typische Workloads

**Keine weiteren Optimierungen erforderlich für normale Nutzung.**

---

**Letzte Analyse**: 2025-10-25  
**Tool-Version**: 1.0.0  
**PHP-Version**: 8.1+
