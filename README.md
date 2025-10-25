# AFS-Schnittstelle · Übersicht & Handbuch

## Inhalt
- [Einführung](#einführung)
- [Technische Fakten](#technische-fakten)
- [Sicherheit](#sicherheit)
- [Inbetriebnahme](#inbetriebnahme)
  - [Voraussetzungen](#voraussetzungen)
  - [Installation](#installation)
  - [Konfiguration](#konfiguration)
- [Bedienung](#bedienung)
  - [Web-Oberfläche](#web-oberfläche)
  - [CLI-Werkzeuge](#cli-werkzeuge)
- [Logging](#logging)
  - [JSON-Log-Format](#json-log-format)
  - [Log-Rotation](#log-rotation)
  - [Logs auslesen](#logs-auslesen)
- [Synchronisationsablauf](#synchronisationsablauf)
  - [Phasen & Fortschrittsbalken](#phasen--fortschrittsbalken)
  - [Fehler-Analyse für Medien](#fehler-analyse-für-medien)
- [Datenbanken & Tabellen](#datenbanken--tabellen)
- [Klassenüberblick](#klassenüberblick)
- [Code Style & Qualität](#code-style--qualität)
- [Troubleshooting](#troubleshooting)

---

## Einführung
Dieses Projekt synchronisiert AFS-ERP Daten nach xt:Commerce (EVO). Die Synchronisation läuft stufenweise:

1. Daten aus dem MSSQL-Backend lesen (Artikel, Warengruppen, Dokumente, Bilder, Attribute)
2. In eine lokale SQLite (`db/evo.db`) überführen
3. Mediendateien (Bilder & Dokumente) vom Filesystem in Projektverzeichnisse kopieren
4. Status- und Fehlermeldungen in `db/status.db` protokollieren
5. **Neu:** Alle Mapping- und Delta-Läufe werden in strukturierte JSON-Logs (`logs/YYYY-MM-DD.log`) geschrieben

Der Sync lässt sich per Web-Oberfläche wie auch per CLI starten. Beide greifen auf dieselben Klassen und Status-Tabellen zu.

**Neu:** Effiziente Änderungserkennung via SHA-256 Hashes – nur tatsächlich geänderte Artikel werden aktualisiert. Das System verwendet ein vereinfachtes, einheitliches Hash-System mit `last_imported_hash` und `last_seen_hash` für robuste Änderungserkennung. Details siehe [HashManager.md](docs/HashManager.md).

**Neu:** Einheitliches JSON-Logging für alle Mapping- und Delta-Operationen – jeder Lauf wird mit Mapping-Version, Datensatzanzahl, Änderungen und Dauer protokolliert. Details siehe [Logging](#logging).

**Architektur:** Vollständig **mapping-basiertes System** – alle Feldzuordnungen und SQL-Statements werden dynamisch aus YAML-Konfigurationen (`mappings/*.yml`) generiert. Keine hardcodierten Feldnamen oder SQL-Queries mehr im Code. Details siehe [CLEANUP_VALIDATION.md](docs/CLEANUP_VALIDATION.md) und [YAML_MAPPING_GUIDE.md](docs/YAML_MAPPING_GUIDE.md).

---

## Technische Fakten

| Komponente           | Beschreibung |
|----------------------|--------------|
| Sprache              | PHP ≥ 8.1 (CLI/CGI) |
| Web Server           | Apache 2.4 mit mpm_event + PHP-FPM (empfohlen) oder mod_php |
| Datenbanken          | MSSQL (Quelle), SQLite (`db/evo.db`, `db/status.db`) |
| Logging              | JSON-Logs in `logs/YYYY-MM-DD.log` (strukturiert, rotierbar) |
| Deployment           | Docker Compose (empfohlen) oder manuelle Installation |
| Verzeichnisstruktur  | `classes/` (Business Logic), `api/` (Endpoints), `scripts/` (CLI-Helfer), `Files/` (Medienausgabe), `logs/` (JSON-Logs) |
| Autoload             | Simple PSR-0-ähnlicher Loader (`autoload.php`) |
| Web-Oberfläche       | Einzelne `index.php` mit fetch-basierten API-Calls |
| Sicherheit           | Umfassende Security-Headers, CSP, Permissions-Policy – siehe [SECURITY.md](docs/SECURITY.md) |

---

## Sicherheit

Die Anwendung implementiert moderne Sicherheits-Best-Practices:

- **Security Headers**: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, etc.
- **Permissions-Policy**: Deaktivierung nicht benötigter Browser-Features
- **Dateischutz**: Konfigurationsdateien, Datenbanken und sensible Verzeichnisse sind geschützt
- **PHP-Sicherheit**: Sichere Session-Einstellungen, deaktivierte PHP-Version-Ausgabe
- **CORS-Kontrolle**: Konfigurierbare Cross-Origin-Zugriffe für API-Endpunkte

Ausführliche Dokumentation siehe [docs/SECURITY.md](docs/SECURITY.md)

---

## Inbetriebnahme

### Voraussetzungen

**Option 1: Docker (empfohlen)**
- Docker & Docker Compose
- Siehe [Quick Start Guide](docs/QUICK_START_DOCKER.md)

**Option 2: Manuelle Installation**
- PHP ≥ 8.1 CLI (empfohlen mit Extensions: `pdo_sqlite`, `pdo_sqlsrv`/`sqlsrv`, `json`, `yaml`)
- **YAML Extension (erforderlich)**: Siehe [YAML Extension Setup](docs/YAML_EXTENSION_SETUP.md)
- Apache 2.4 mit mpm_event + PHP-FPM (siehe [Apache PHP-FPM Setup](docs/APACHE_PHP_FPM_SETUP.md))
- Schreibrechte im Projektordner (für `Files/` und `db/`)
- Netzwerkzugriff auf den MSSQL-Server
- Optional: `sqlite3` CLI (zum manuellen Inspektieren der Datenbanken)

### Installation

**Mit Docker:**
```bash
# Schnellstart
cp .env.example .env
docker-compose up -d

# Siehe docs/QUICK_START_DOCKER.md für Details
```

**Manuell:**
1. Projekt auf Zielsystem kopieren
2. Abhängige PHP-Extensions installieren
3. Apache mpm_event + PHP-FPM einrichten (siehe [docs/APACHE_PHP_FPM_SETUP.md](docs/APACHE_PHP_FPM_SETUP.md))
4. Die SQLite-Datenbanken initialisieren:
   ```bash
   php scripts/setup.php
  ```
  Dadurch werden `db/evo.db` und `db/status.db` gemäß `scripts/create_*.sql` erstellt.
- Bei Updates von älteren Installationen:
  - `php scripts/migrate_update_columns.php` (fügt die neuen `update`-Spalten in den Verknüpfungstabellen hinzu)
  - `php scripts/migrate_add_hash_columns.php` (fügt Hash-Spalten für effiziente Ängerungserkennung hinzu)
  - `php scripts/migrate_add_indexes.php` (fügt Performance-Indizes hinzu - **empfohlen für bestehende Installationen**)
  - ~~`php scripts/migrate_add_partial_hash_columns.php`~~ (DEPRECATED: Teil-Hash-Spalten wurden entfernt)

### Konfiguration

**AFS-MappingXT verwendet ein einheitliches, umgebungsvariablenbasiertes Konfigurationsmanagement.**

1. **Erstelle eine `.env`-Datei:**
   ```bash
   cp .env.example .env
   nano .env
   ```

2. **Konfiguriere die Werte in `.env`:**
   - `AFS_MSSQL_*`: MSSQL-Datenbankverbindung (Host, Port, DB, User, Passwort)
   - `PHP_MEMORY_LIMIT`, `PHP_MAX_EXECUTION_TIME`: PHP-Konfiguration
   - `AFS_MEDIA_SOURCE`: Quellverzeichnis für Medien
   - `TZ`: Zeitzone
   - Weitere Optionen siehe [docs/CONFIGURATION_MANAGEMENT.md](docs/CONFIGURATION_MANAGEMENT.md)

3. **Docker-Container starten:**
   ```bash
   docker-compose up -d
   ```

Alle Konfigurationswerte haben sinnvolle Defaults und können zentral über Umgebungsvariablen gesteuert werden.

**Ausführliche Dokumentation:** [docs/CONFIGURATION_MANAGEMENT.md](docs/CONFIGURATION_MANAGEMENT.md)

---

## Bedienung

### Web-Oberfläche
`index.php` bietet:
- Systemcheck (SQLite-Dateien, MSSQL-Reachability)
- Statuskarte mit Fortschrittsbalken
- Protokoll (Logeinträge, einklappbar mit Zusatzinfos)
- Aktionen:
  - `Synchronisation starten`
  - `EVO-Datenbank leeren` (setzt Tabellen zurück)
  - `Status aktualisieren`, `Protokoll leeren`
- Debugging-Bereich: Tabellenansicht (Haupt-, Delta- oder Status-DB), Status zurücksetzen, EVO-Datenbank leeren

Während ein Sync läuft, ist der Start-Button gesperrt. Der Status springt nach Ende automatisch auf `ready`.

### CLI-Werkzeuge
`indexcli.php` unterstützt:
- `php indexcli.php run [--copy-images=1 --image-source=/pfad ...]`
- `php indexcli.php status`
- `php indexcli.php log [--level=error --limit=200]`
- `php indexcli.php clear-errors`

Die CLI verweigert `run`, wenn bereits ein Sync aktiv ist (busy-Schutz analog zur API).

Weitere Skripte:
- `scripts/clear_evo.php`: leert alle Tabellen in `evo.db`
- `scripts/setup.php`: initialisiert beide SQLite-Datenbanken
- `scripts/migrate_update_columns.php`: ergänzt fehlende `update`-Spalten in bestehenden Installationen

---

## Logging

Das System verwendet ein zweistufiges Logging-Konzept:

1. **StatusTracker** (`db/status.db`): Speichert den aktuellen Sync-Status für die Web-Oberfläche (temporär, begrenzte Einträge)
2. **MappingLogger** (`logs/YYYY-MM-DD.log`): Permanente, strukturierte JSON-Logs für alle Mapping- und Delta-Operationen

### JSON-Log-Format

Jeder Log-Eintrag ist eine JSON-Zeile mit folgender Struktur:

```json
{
  "timestamp": "2025-10-25T10:30:45+02:00",
  "operation": "sync_complete",
  "level": "info",
  "message": "Synchronisation abgeschlossen",
  "mapping_version": "1.0.0",
  "context": {
    "duration_seconds": 123.45,
    "duration_formatted": "2m 3s",
    "total_records": 1000,
    "changed": 50
  }
}
```

**Operationstypen:**
- `sync_start`: Start einer Synchronisation
- `sync_complete`: Erfolgreicher Abschluss
- `sync_error`: Fehler während der Synchronisation
- `stage_complete`: Abschluss einer einzelnen Phase (z.B. `artikel`, `bilder`)
- `record_changes`: Datensatzänderungen (eingefügt, aktualisiert, gelöscht)
- `delta_export`: Delta-Export in separate Datenbank

**Log-Level:**
- `info`: Normale Informationen
- `warning`: Warnungen (z.B. fehlende Dateien)
- `error`: Fehler mit Exception-Details

### Log-Rotation

Logs älter als 30 Tage werden automatisch gelöscht (konfigurierbar in `config.php`):

```php
'logging' => [
    'mapping_version' => '1.0.0',
    'log_rotation_days' => 30,
    'enable_file_logging' => true,
],
```

### Logs auslesen

**Manuell:**
```bash
# Heutige Logs anzeigen
cat logs/2025-10-25.log | jq .

# Nur Fehler filtern
cat logs/2025-10-25.log | jq 'select(.level == "error")'

# Sync-Zusammenfassungen anzeigen
cat logs/2025-10-25.log | jq 'select(.operation == "sync_complete")'
```

**Programmatisch:**
```php
$logger = new AFS_MappingLogger(__DIR__ . '/logs', '1.0.0');
$entries = $logger->readLogs('2025-10-25', 100); // Letzte 100 Einträge

foreach ($entries as $entry) {
    echo "{$entry['timestamp']} [{$entry['level']}] {$entry['message']}\n";
}
```

---

## Synchronisationsablauf

### Phasen & Fortschrittsbalken
1. `initialisierung`
2. `bilder` (Import in SQLite)
3. `bilder_kopieren` (Fortschritt anhand eindeutiger Dateinamen)
4. `dokumente` (Import)
5. `dokumente_kopieren` (Fortschritt über Dokumenttitel)
6. `dokumente_pruefung` (Analyse fehlender/fehlerhafter Kopien)
7. `attribute`, `warengruppen`
8. `artikel`
9. `delta_export` (erstellt `evo_delta.db` aus allen Datensätzen mit `update = 1`)
10. `abschluss` (Status → `ready`)

Die Fortschritts-Balken für Medien spiegeln Anzahl der verarbeiteten Dateien wider (inkl. Missing/Failed).

### Fehler-Analyse für Medien
Nach dem Kopieren werden fehlende oder fehlgeschlagene Dateien analysiert:
- Für Bilder: `articles_by_image` zeigt Artikelnummer + Bezeichnung
- Für Dokumente: `articles_by_document` auf Basis von Dokumenttitel/Artikel-ID
- Warnungen/Fehler werden im Protokoll protokolliert und im UI einblendbar angezeigt

---

## Datenbanken & Tabellen

### `db/evo.db`
- `Artikel`, `Bilder`, `Dokumente`, `Attribute`, `category`, … (alle mit `update`-Flag)
- Join-Tabellen:
  - `Artikel_Bilder` (Artikel-ID ↔ Bild-ID, `update` markiert neue/gelöschte Verknüpfungen)
  - `Artikel_Dokumente` (Artikel-ID ↔ Dokument-ID, `update` markiert neue/gelöschte Verknüpfungen)
  - `Attrib_Artikel` (Artikel ↔ Attribute inkl. Wertänderungen über `update`)
- **Performance-Indizes**: Umfassende Indexierung für optimale Query-Performance
  - Update-Flag-Indizes für schnellen Delta-Export (10-100x schneller)
  - Foreign-Key-Indizes für effiziente Junction-Table-Lookups (40x schneller)
  - XT_ID-Indizes für bi-direktionale Synchronisation
  - Details siehe [docs/INDEX_STRATEGY.md](docs/INDEX_STRATEGY.md)

### `db/evo_delta.db`
- Wird nach jedem Lauf erzeugt (gleiche Tabellenschemata wie `evo.db`)
- Enthält ausschließlich Datensätze, deren `update`-Flag zuvor `1` war – ideal für Abgleich/Debugging
- Nach dem Export werden alle `update`-Flags in `evo.db` wieder auf `0` gesetzt
- Kann von der XT-Gegenstelle (`xt/api/import.php`) direkt verarbeitet werden

### `db/status.db`
- `sync_status`: aktuelle Statusdaten (State, Stage, Progress)
- `sync_log`: Verlauf von Info/Warning/Error-Einträgen (inkl. Kontext in JSON)
- Über die Debugging-Ansicht (Auswahl „Status-Datenbank“) einsehbar

Scripts `scripts/create_evo.sql` & `scripts/create_status.sql` enthalten die vollständigen Schemata.

---

## Klassenüberblick

| Klasse | Zweck |
|--------|-------|
| `AFS` | Aggregiert Rohdaten (Artikel, Warengruppen, Dokumente, Bilder, Attribute) aus einer Quelle |
| `AFS_Get_Data` | Liest MSSQL-Tabellen, normalisiert & säubert Werte |
| `MSSQL` | SQLSRV-Wrapper mit Komfortmethoden (`select`, `count`, `scalar`, Quoting) |
| `AFS_Evo` | Orchestriert alle Sync-Schritte, koordiniert Status-Tracker und Logger |
| `AFS_Evo_ImageSync` | Importiert Bilder in SQLite, kopiert Dateien, meldet fehlende Bilder |
| `AFS_Evo_DocumentSync` | Importiert Dokumente, kopiert PDFs anhand des Titels, Analysephase |
| `AFS_Evo_AttributeSync` | Überträgt Attribute für Artikel |
| `AFS_Evo_CategorySync` | Synchronisiert Warengruppen (inkl. Parent-Verknüpfungen) |
| `AFS_Evo_ArticleSync` | Hauptlogik: Artikel schreiben, Medien & Attribute verknüpfen |
| `AFS_HashManager` | **NEU:** Effiziente Änderungserkennung via SHA-256 Hashes (siehe [HashManager.md](docs/HashManager.md)) |
| `AFS_ConfigCache` | **NEU:** In-Memory-Cache für YAML-Konfigurationsdateien – beschleunigt wiederholte Config-Loads um 3-5x |
| `AFS_YamlParser` | **NEU:** Native PHP YAML-Parser – eliminiert Abhängigkeit von php-yaml Extension |
| `AFS_MappingConfig` | YAML-Konfiguration für Source-Datenbank-Mapping (nutzt `AFS_ConfigCache` und `AFS_YamlParser`) |
| `AFS_TargetMappingConfig` | YAML-Konfiguration für Target-Datenbank-Mapping (nutzt `AFS_ConfigCache` und `AFS_YamlParser`) |
| `AFS_Evo_StatusTracker` | Managt `sync_status`/`sync_log` in SQLite, Fortschrittsbalken & Logs für UI |
| `AFS_MappingLogger` | **NEU:** Strukturiertes JSON-Logging in tägliche Dateien mit Mapping-Version, Änderungen und Dauer |
| `AFS_Evo_Reset` | Utility zum Leeren aller EVO-Tabellen |
| `AFS_Evo_DeltaExporter` | Exportiert Datensätze mit `update = 1` in `evo_delta.db` und setzt Flags zurück |
| `AFS_MetadataLoader` | Liest Metadaten (Titel/Beschreibung) aus der Dateistruktur und reichert Artikel/Kategorien an |
| `AFS_SyncBusyException` | Spezielle Exception bei parallelen Sync-Versuchen |

Hilfsklassen wie `AFS_Evo_Base` stellen gemeinsame Utilities (Artikelreferenz, Logging, Normalisierung) bereit.

---

## Testing

Das Projekt enthält umfassende Test-Skripte zur Validierung der Mapping-Logik:

### Verfügbare Tests

| Script | Beschreibung |
|--------|--------------|
| `verify_yaml_extension.php` | **[NEU]** Verifiziert YAML-Extension-Installation und -Funktionalität |
| `test_yaml_mapping.php` | Validiert YAML-Konfiguration und SQL-Generierung aus source_afs.yml |
| `test_target_mapping.php` | Validiert target_sqlite.yml Konfiguration und UPSERT-Statements |
| `test_config_cache.php` | **[NEU]** Testet Caching-Layer für YAML-Konfigurationen |
| `test_articlesync_mapping.php` | Integration-Test für AFS_Evo_ArticleSync mit Target-Mapping |
| `test_mixed_mode_validation.php` | Umfassende Validierung der Mapping-Logik |
| `validate_no_hardcodings.php` | **[NEU]** Bestätigt keine Hardcodings oder Legacy-Code mehr vorhanden |
| `test_hashmanager.php` | Tests für effiziente Änderungserkennung via Hashes |
| `test_mapping_logger.php` | Tests für strukturiertes JSON-Logging |
| `test_index_performance.php` | **[NEU]** Validiert Datenbank-Index-Performance und Nutzung |
| `analyze_performance.php` | **[NEU]** Projektweite Performance-Analyse und Benchmarking |

### Performance-Analyse

Das Projekt enthält ein umfassendes Performance-Analyse-Tool zur Identifikation von Bottlenecks und Optimierungsmöglichkeiten:

```bash
# Standard-Analyse
php scripts/analyze_performance.php

# Detaillierte Analyse mit mehr Iterationen
php scripts/analyze_performance.php --detailed

# Mit JSON-Export
php scripts/analyze_performance.php --export=json
```

Das Tool analysiert:
- Konfigurationsverarbeitung
- YAML-Mapping-Performance
- SQL-Generierung
- Datenbank-Operationen
- Hash-Berechnungen
- Speichernutzung
- Datei I/O
- Klassen-Instanziierung

Detaillierte Performance-Dokumentation: [docs/PERFORMANCE_ANALYSIS.md](docs/PERFORMANCE_ANALYSIS.md)

### Mixed Mode Validation Test

Das `test_mixed_mode_validation.php` Script führt eine umfassende 5-Phasen-Validierung durch:

1. **Konfigurationsvalidierung**: Lädt und validiert beide YAML-Mappings
2. **SQL-Generierungsvalidierung**: Testet dynamische SQL-Generierung
3. **Datenkonsistenzvalidierung**: Prüft Feldvollständigkeit und Constraints
4. **Performance-Vergleich**: Misst und validiert Laufzeiten
5. **Datenverlust-Erkennung**: Prüft auf fehlende Konfigurationen

```bash
# Ausführung
php scripts/test_mixed_mode_validation.php

# Ergebnis
✓ VALIDATION PASSED - Results are 100% identical
✓ No data loss detected
✓ Performance within acceptable thresholds
```

**Akzeptanzkriterien (erfüllt):**
- ✅ Ergebnis 100% identisch
- ✅ Log dokumentiert Unterschiede
- ✅ Keine Datenverluste
- ✅ Performance akzeptabel

Detaillierte Dokumentation: [docs/MIXED_MODE_VALIDATION.md](docs/MIXED_MODE_VALIDATION.md)

### Alle Tests ausführen

```bash
# YAML Extension verifizieren
php scripts/verify_yaml_extension.php

# Mapping und Konfiguration testen
php scripts/test_yaml_mapping.php
php scripts/test_target_mapping.php
php scripts/test_articlesync_mapping.php
php scripts/test_mixed_mode_validation.php
php scripts/validate_no_hardcodings.php
php scripts/test_index_performance.php
php scripts/analyze_performance.php
```

**YAML Extension Verifizierung:**
```bash
php scripts/verify_yaml_extension.php
```
Dieser Test verifiziert, dass die YAML-Extension korrekt installiert ist und alle erforderlichen Funktionen zur Verfügung stehen. Besonders wichtig nach Docker-Builds oder PHP-Updates.

**Cleanup-Validierung:**
```bash
php scripts/validate_no_hardcodings.php
```
Dieser Test bestätigt, dass das System vollständig mapping-basiert ist und keine Hardcodings oder Legacy-Code mehr enthält. Details siehe [CLEANUP_VALIDATION.md](docs/CLEANUP_VALIDATION.md).

---

## Code Style & Qualität

Das Projekt folgt dem **PSR-12: Extended Coding Style** Standard für konsistenten, lesbaren und wartbaren PHP-Code.

### Werkzeuge

- **PHP_CodeSniffer**: Automatische Überprüfung und Korrektur von Code-Style-Verstößen
- **PHPStan**: Statische Code-Analyse zur Fehlererkennung
- **EditorConfig**: Einheitliche Editor-Einstellungen für alle Entwickler

### Verwendung

```bash
# Dependencies installieren
composer install

# Code-Style prüfen
composer cs:check

# Code-Style automatisch korrigieren
composer cs:fix

# Statische Analyse durchführen
composer stan

# Alle Qualitätsprüfungen ausführen
composer test:style
```

### CI/CD Integration

GitHub Actions führt automatisch Code-Style-Checks bei Pull Requests und Pushes auf `main` und `develop` Branches durch. Die Pipeline schlägt fehl, wenn PSR-12-Verstöße oder Type-Fehler erkannt werden.

Ausführliche Dokumentation siehe [docs/CODE_STYLE.md](docs/CODE_STYLE.md)

---

## Troubleshooting

| Problem | Ursache/Prüfung | Lösung |
|---------|-----------------|--------|
| Sync startet nicht | `state = running` in `sync_status` | Auf Abschluss warten oder `sync_start`-Aufruf abbrechen |
| Mediendateien fehlen | Pfad falsch, SMB-Laufwerk nicht erreichbar | Pfad in `config.php` korrigieren, Mount prüfen |
| SQLite gesperrt | Gleichzeitiger Dateizugriff | Sicherstellen, dass keine parallelen Tools (z. B. DB Browser) schreiben |
| Protokoll überläuft | `max_errors` zu niedrig | Wert in `config.php` erhöhen (Default 200) |
| CLI meldet Busy | Laufender Web-Sync | nach Abschluss erneut starten |

Log-Einträge inklusive JSON-Kontext lassen sich via `php indexcli.php log --limit=200` oder `api/sync_errors.php` einsehen. Für tiefergehende Analysen kann das Debug-UI (Dropdown in der Web-Oberfläche) genutzt werden, um Tabellen direkt zu inspizieren.

---

Happy Syncing! ✨
