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
- [Data Transfer API](#data-transfer-api)
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

**Neu:** **Multi-Database Support** – Das System unterstützt jetzt mehrere Datenbank-Synchronisationspaare gleichzeitig. Konfigurieren Sie flexible Datenflüsse zwischen AFS (MSSQL), EVO (SQLite) und XT-Commerce (MySQL). Details siehe [MULTI_DATABASE_SYNC.md](docs/MULTI_DATABASE_SYNC.md).

**Architektur:** Vollständig **mapping-basiertes System** – alle Feldzuordnungen und SQL-Statements werden dynamisch aus YAML-Konfigurationen (`mappings/*.yml`) generiert. Keine hardcodierten Feldnamen oder SQL-Queries mehr im Code. Details siehe [YAML_MAPPING_GUIDE.md](docs/YAML_MAPPING_GUIDE.md).

**Neu:** **Server-to-Server Data Transfer API** – Sichere API zum Transferieren von Delta-Datenbanken, Bildern und Dokumenten zwischen verschiedenen Servern. Mit API-Key-Authentifizierung und flexibler Konfiguration über Umgebungsvariablen. Details siehe [DATA_TRANSFER_API.md](docs/DATA_TRANSFER_API.md).

**Neu:** **Remote Server Monitoring** – Überwachen Sie den Synchronisationsstatus von mehreren Servern zentral in der Web-Oberfläche. Ideal für Multi-Server-Setups mit Master/Slave-Konfigurationen. Konfigurieren Sie einfach die URLs der Remote-Server und sehen Sie deren Status in Echtzeit. Details siehe [REMOTE_SERVER_MONITORING.md](docs/REMOTE_SERVER_MONITORING.md).

---

## Technische Fakten

| Komponente           | Beschreibung |
|----------------------|--------------|
| Sprache              | PHP ≥ 8.1 (CLI/CGI) |
| Web Server           | Apache 2.4 mit mpm_event + PHP-FPM (empfohlen) oder mod_php |
| Datenbanken          | MSSQL (Quelle), SQLite (`db/evo.db`, `db/status.db`) |
| Logging              | JSON-Logs in `logs/YYYY-MM-DD.log` (strukturiert, rotierbar) |
| Caching              | `AFS_ConfigCache` für YAML-Konfigurationsdateien |
| Deployment           | Docker Compose (empfohlen) oder manuelle Installation |
| Verzeichnisstruktur  | `classes/` (Business Logic), `api/` (Endpoints), `scripts/` (CLI-Helfer), `Files/` (Medienausgabe), `logs/` (JSON-Logs), `assets/` (CSS/JS) |
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
- **Zugangskontrolle**: Konfigurierbarer Sicherheitsmodus für Einschränkung des direkten Zugriffs auf index.php und indexcli.php

Ausführliche Dokumentation siehe [docs/SECURITY.md](docs/SECURITY.md) und [docs/SECURITY_CONFIGURATION.md](docs/SECURITY_CONFIGURATION.md)

---

## Inbetriebnahme

### Voraussetzungen

**Option 1: Docker (empfohlen)**
- Docker & Docker Compose
- Siehe [Quick Start Guide](docs/QUICK_START_DOCKER.md)

**Option 2: Manuelle Installation**
- PHP ≥ 8.1 CLI (empfohlen mit Extensions: `pdo_sqlite`, `pdo_sqlsrv`/`sqlsrv`, `json`)
- Apache 2.4 mit mpm_event + PHP-FPM
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
3. Apache mpm_event + PHP-FPM einrichten
4. Die SQLite-Datenbanken initialisieren:
   ```bash
   php scripts/setup.php
  ```
  Dadurch werden `db/evo.db` und `db/status.db` gemäß `scripts/create_*.sql` erstellt.
- Bei Updates von älteren Installationen:
  - `php scripts/migrate_update_columns.php` (fügt die neuen `update`-Spalten in den Verknüpfungstabellen hinzu)
  - `php scripts/migrate_add_hash_columns.php` (fügt Hash-Spalten für effiziente Ängerungserkennung hinzu)
  - `php scripts/migrate_add_indexes.php` (fügt Performance-Indizes hinzu - **empfohlen für bestehende Installationen**)

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
   - `AFS_GITHUB_AUTO_UPDATE`: Automatische Updates von GitHub (true/false)
   - Weitere Optionen siehe [docs/CONFIGURATION_MANAGEMENT.md](docs/CONFIGURATION_MANAGEMENT.md)

3. **Docker-Container starten:**
   ```bash
   docker-compose up -d
   ```

Alle Konfigurationswerte haben sinnvolle Defaults und können zentral über Umgebungsvariablen gesteuert werden.

**Ausführliche Dokumentation:** [docs/CONFIGURATION_MANAGEMENT.md](docs/CONFIGURATION_MANAGEMENT.md)

### GitHub Auto-Update

Das System kann automatisch nach Updates auf GitHub suchen und diese beim Start der Synchronisation (CLI oder Web) anwenden:

**Aktivierung:**
```bash
# In .env-Datei:
AFS_GITHUB_AUTO_UPDATE=true
```

**Eigenschaften:**
- Prüft vor jedem Sync-Start auf Updates
- Führt automatisch `git pull` aus, wenn Updates verfügbar sind
- Schützt die `.env`-Datei (wird durch `.gitignore` nicht überschrieben)
- Kann mit `--skip-update` (CLI) übersprungen werden
- Manueller Update-Check: `php indexcli.php update`

**Wichtig:** Die Umgebungskonfiguration (`.env`) wird durch Updates **nicht** überschrieben, da sie in `.gitignore` ausgeschlossen ist.

**Detaillierte Dokumentation:** [docs/GITHUB_AUTO_UPDATE.md](docs/GITHUB_AUTO_UPDATE.md)

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
  - `--skip-update`: Überspringt die automatische Update-Prüfung
- `php indexcli.php status`
- `php indexcli.php log [--level=error --limit=200]`
- `php indexcli.php clear-errors`
- `php indexcli.php update`: Manuell nach GitHub-Updates suchen und installieren

Die CLI verweigert `run`, wenn bereits ein Sync aktiv ist (busy-Schutz analog zur API).

Weitere Skripte:
- `scripts/clear_evo.php`: leert alle Tabellen in `evo.db`
- `scripts/setup.php`: initialisiert beide SQLite-Datenbanken
- `scripts/migrate_update_columns.php`: ergänzt fehlende `update`-Spalten in bestehenden Installationen

---

## Data Transfer API

Die **Data Transfer API** ermöglicht die sichere Übertragung von Delta-Datenbanken, Bildern und Dokumenten zwischen verschiedenen Servern.

### Kernfunktionen

- **API-Key-Authentifizierung**: Sichere Authentifizierung über konfigurierbare API-Keys
- **Delta-Datenbank-Transfer**: Kopiert `evo_delta.db` zwischen Servern
- **Bilder-Transfer**: Synchronisiert Bilder-Verzeichnisse rekursiv
- **Dokumente-Transfer**: Synchronisiert Dokumente-Verzeichnisse rekursiv
- **Flexibles Logging**: Optional strukturiertes JSON-Logging aller Transfers
- **Fehlerbehandlung**: Detaillierte Fehlerprotokolle und Status-Rückgaben

### Quick Start

1. **API-Key generieren**:
   ```bash
   openssl rand -hex 32
   ```

2. **In `.env` konfigurieren**:
   ```bash
   DATA_TRANSFER_API_KEY=dein_generierter_api_key
   DB_TRANSFER_SOURCE=/pfad/zur/quelle/db/evo_delta.db
   DB_TRANSFER_TARGET=/pfad/zum/ziel/db/evo_delta.db
   ```

3. **API aufrufen**:
   ```bash
   curl -X POST \
     -H "X-API-Key: dein_generierter_api_key" \
     -d "transfer_type=all" \
     https://your-server.com/api/data_transfer.php
   ```

### Transfer-Typen

- `database`: Nur Delta-Datenbank transferieren
- `images`: Nur Bilder-Verzeichnis synchronisieren
- `documents`: Nur Dokumente-Verzeichnis synchronisieren
- `all`: Alle drei Typen transferieren (Standard)

### Dokumentation

Vollständige Dokumentation siehe [DATA_TRANSFER_API.md](docs/DATA_TRANSFER_API.md):
- Detaillierte API-Spezifikation
- Request/Response-Beispiele
- Konfigurationsoptionen
- Sicherheits-Best-Practices
- Integration in Workflows
- Troubleshooting

### Test-Scripts

- `scripts/test_api_transfer.php`: Unit-Tests für API_Transfer-Klasse
- `scripts/test_api_transfer_integration.php`: Integrationstests mit echten Dateitransfers
- `scripts/test_api_transfer_curl.sh`: Curl-basierter API-Endpunkt-Test

---

## Logging

Das System verwendet ein zweistufiges Logging-Konzept:

1. **StatusTracker** (`db/status.db`): Speichert den aktuellen Sync-Status für die Web-Oberfläche (temporär, begrenzte Einträge)
2. **MappingLogger** (`logs/YYYY-MM-DD.log`): Permanente, strukturierte JSON-Logs für alle Mapping- und Delta-Operationen

### Lean & Targeted Logging

Das Logging-System ist optimiert für **schlanke und gezielte** Protokollierung:

**Standard-Modus (log_level='warning'):**
- ✅ Nur WARNING und ERROR Meldungen werden geloggt
- ✅ INFO-Meldungen (Routine-Operationen) werden gefiltert
- ✅ Reduzierte Sample-Größen (5 statt 12) für kompakte Fehlerberichte
- ✅ Geringeres Log-Volumen bei gleichbleibender Aussagekraft

**Verbose-Modus (log_level='info'):**
- Alle Informationen werden geloggt (für detailliertes Troubleshooting)
- Empfohlen nur während der Fehlersuche

**Konfiguration:**
```php
// config.php
'logging' => [
    'mapping_version' => '1.0.0',
    'log_rotation_days' => 30,
    'enable_file_logging' => true,
    'log_level' => 'warning',  // 'info', 'warning', or 'error'
    'sample_size' => 5,        // Anzahl der Beispiele in Fehler-Arrays
],
```

**Umgebungsvariablen:**
```bash
# .env
AFS_LOG_LEVEL=warning        # Minimaler Log-Level
AFS_LOG_SAMPLE_SIZE=5        # Sample-Größe für Fehler-Arrays
```

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
- `info`: Normale Informationen (gefiltert im Standard-Modus)
- `warning`: Warnungen (z.B. fehlende Dateien) - **immer geloggt**
- `error`: Fehler mit Exception-Details - **immer geloggt**

### Log-Rotation

Logs älter als 30 Tage werden automatisch gelöscht (konfigurierbar in `config.php`):

```php
'logging' => [
    'mapping_version' => '1.0.0',
    'log_rotation_days' => 30,
    'enable_file_logging' => true,
    'log_level' => 'warning',
    'sample_size' => 5,
],
```

### Logs auslesen

**Manuell:**
```bash
# Heutige Logs anzeigen
cat logs/2025-10-25.log | jq .

# Nur Fehler filtern
cat logs/2025-10-25.log | jq 'select(.level == "error")'

# Nur Warnungen und Fehler
cat logs/2025-10-25.log | jq 'select(.level == "warning" or .level == "error")'

# Sync-Zusammenfassungen anzeigen
cat logs/2025-10-25.log | jq 'select(.operation == "sync_complete")'
```

**Programmatisch:**
```php
// Standard-Modus (nur Warnings und Errors)
$logger = new AFS_MappingLogger(__DIR__ . '/logs', '1.0.0', 'warning');

// Verbose-Modus (alle Logs)
$logger = new AFS_MappingLogger(__DIR__ . '/logs', '1.0.0', 'info');

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

**Die Klassen sind nach Datenbank-Typ organisiert für maximale Flexibilität.**

Siehe [CLASS_STRUCTURE.md](docs/CLASS_STRUCTURE.md) für eine vollständige Dokumentation der Klassenstruktur.

### Verzeichnisstruktur
```
classes/
├── afs/           # AFS-spezifische Klassen (Mapping-Daten, Aggregation)
├── mapping/       # Generische Mapping- und Transformationsklassen
├── mssql/         # MSSQL-Datenbankoperationen
├── evo/           # EVO-Zwischendatenbank (SQLite)
├── sqlite/        # SQLite-spezifische Operationen
├── mysql/         # MySQL-Datenbankoperationen (Platzhalter)
├── xt/            # XT-Commerce-spezifische Klassen (Platzhalter)
├── status/        # Status- und Logging-Klassen
└── file/          # Dateibasierte Datenstrukturen (Platzhalter)
```

### Wichtige Klassen

| Klasse | Verzeichnis | Zweck |
|--------|-------------|-------|
| `AFS` | `afs/` | Aggregiert Rohdaten (Artikel, Warengruppen, Dokumente, Bilder, Attribute) aus einer Quelle |
| `AFS_Get_Data` | `afs/` | Liest MSSQL-Tabellen, normalisiert & säubert Werte |
| `MSSQL_Connection` | `mssql/` | SQLSRV-Wrapper mit Komfortmethoden (`select`, `count`, `scalar`, Quoting) |
| `SQLite_Connection` | `sqlite/` | **NEU:** PDO-basierter SQLite-Wrapper mit Transaktionen, Performance-Optimierungen |
| `EVO` | `evo/` | Orchestriert alle Sync-Schritte, koordiniert Status-Tracker und Logger |
| `EVO_ImageSync` | `evo/` | Importiert Bilder in SQLite, kopiert Dateien, meldet fehlende Bilder |
| `EVO_DocumentSync` | `evo/` | Importiert Dokumente, kopiert PDFs anhand des Titels, Analysephase |
| `EVO_AttributeSync` | `evo/` | Überträgt Attribute für Artikel |
| `EVO_CategorySync` | `evo/` | Synchronisiert Warengruppen (inkl. Parent-Verknüpfungen) |
| `EVO_ArticleSync` | `evo/` | Hauptlogik: Artikel schreiben, Medien & Attribute verknüpfen |
| `AFS_HashManager` | `afs/` | **NEU:** Effiziente Änderungserkennung via SHA-256 Hashes (siehe [HashManager.md](docs/HashManager.md)) |
| `AFS_ConfigCache` | `afs/` | **NEU:** In-Memory-Cache für YAML-Konfigurationsdateien – beschleunigt wiederholte Config-Loads um 3-5x |
| `AFS_YamlParser` | `afs/` | **NEU:** Native PHP YAML-Parser – keine externe Extension erforderlich |
| `AFS_MappingConfig` | `afs/` | YAML-Konfiguration für Source-Datenbank-Mapping (nutzt `AFS_ConfigCache` und `AFS_YamlParser`) |
| `AFS_TargetMappingConfig` | `afs/` | YAML-Konfiguration für Target-Datenbank-Mapping (nutzt `AFS_ConfigCache` und `AFS_YamlParser`) |
| `STATUS_Tracker` | `status/` | Managt `sync_status`/`sync_log` in SQLite, Fortschrittsbalken & Logs für UI (nutzt `SQLite_Connection`) |
| `STATUS_MappingLogger` | `status/` | **NEU:** Strukturiertes JSON-Logging in tägliche Dateien mit Mapping-Version, Änderungen und Dauer |
| `AFS_GitHubUpdater` | `afs/` | **NEU:** Automatische Updates von GitHub – prüft auf neue Commits und führt `git pull` aus |
| `EVO_Reset` | `evo/` | Utility zum Leeren aller EVO-Tabellen |
| `EVO_DeltaExporter` | `evo/` | Exportiert Datensätze mit `update = 1` in `evo_delta.db` und setzt Flags zurück |
| `AFS_MetadataLoader` | `afs/` | Liest Metadaten (Titel/Beschreibung) aus der Dateistruktur und reichert Artikel/Kategorien an |
| `AFS_SyncBusyException` | `afs/` | Spezielle Exception bei parallelen Sync-Versuchen |
| `SourceMapper` | `mapping/` | Bildet Quelldaten auf normalisiertes Format ab |
| `TargetMapper` | `mapping/` | Bildet normalisierte Daten auf Zielformat ab |

Hilfsklassen wie `EVO_Base` stellen gemeinsame Utilities (Artikelreferenz, Logging, Normalisierung) bereit.

**Vorteile der neuen Struktur:**
- Klare Trennung nach Datenbank-Typ (MSSQL, SQLite/EVO, MySQL, XT-Commerce)
- Ermöglicht flexible YAML-basierte Konfigurationen (z.B. Source: AFS → Target: EVO, oder umgekehrt)
- **Multi-Database Support:** Mehrere Sync-Paare parallel konfigurierbar (siehe [MULTI_DATABASE_SYNC.md](docs/MULTI_DATABASE_SYNC.md))
- Vorbereitet für zukünftige Erweiterungen (MySQL, weitere SQLite-DBs, File-basierte Quellen, XT-Commerce)

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
