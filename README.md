# AFS-Schnittstelle · Übersicht & Handbuch

## Inhalt
- [Einführung](#einführung)
- [Technische Fakten](#technische-fakten)
- [Inbetriebnahme](#inbetriebnahme)
  - [Voraussetzungen](#voraussetzungen)
  - [Installation](#installation)
  - [Konfiguration](#konfiguration)
- [Bedienung](#bedienung)
  - [Web-Oberfläche](#web-oberfläche)
  - [CLI-Werkzeuge](#cli-werkzeuge)
- [Synchronisationsablauf](#synchronisationsablauf)
  - [Phasen & Fortschrittsbalken](#phasen--fortschrittsbalken)
  - [Fehler-Analyse für Medien](#fehler-analyse-für-medien)
- [Datenbanken & Tabellen](#datenbanken--tabellen)
- [Klassenüberblick](#klassenüberblick)
- [Troubleshooting](#troubleshooting)

---

## Einführung
Dieses Projekt synchronisiert AFS-ERP Daten nach xt:Commerce (EVO). Die Synchronisation läuft stufenweise:

1. Daten aus dem MSSQL-Backend lesen (Artikel, Warengruppen, Dokumente, Bilder, Attribute)
2. In eine lokale SQLite (`db/evo.db`) überführen
3. Mediendateien (Bilder & Dokumente) vom Filesystem in Projektverzeichnisse kopieren
4. Status- und Fehlermeldungen in `db/status.db` protokollieren

Der Sync lässt sich per Web-Oberfläche wie auch per CLI starten. Beide greifen auf dieselben Klassen und Status-Tabellen zu.

**Neu:** Effiziente Änderungserkennung via SHA-256 Hashes – nur tatsächlich geänderte Artikel werden aktualisiert. Details siehe [HashManager.md](docs/HashManager.md).

---

## Technische Fakten

| Komponente           | Beschreibung |
|----------------------|--------------|
| Sprache              | PHP ≥ 8.1 (CLI/CGI) |
| Datenbanken          | MSSQL (Quelle), SQLite (`db/evo.db`, `db/status.db`) |
| Verzeichnisstruktur  | `classes/` (Business Logic), `api/` (Endpoints), `scripts/` (CLI-Helfer), `Files/` (Medienausgabe) |
| Autoload             | Simple PSR-0-ähnlicher Loader (`autoload.php`) |
| Web-Oberfläche       | Einzelne `index.php` mit fetch-basierten API-Calls |

---

## Inbetriebnahme

### Voraussetzungen
- PHP CLI (empfohlen mit Extensions: `pdo_sqlite`, `pdo_sqlsrv`/`sqlsrv`, `json`)
- Schreibrechte im Projektordner (für `Files/` und `db/`)
- Netzwerkzugriff auf den MSSQL-Server
- Optional: `sqlite3` CLI (zum manuellen Inspektieren der Datenbanken)

### Installation
1. Projekt auf Zielsystem kopieren
2. Abhängige PHP-Extensions installieren
3. Die SQLite-Datenbanken initialisieren:
   ```bash
   php scripts/setup.php
  ```
  Dadurch werden `db/evo.db` und `db/status.db` gemäß `scripts/create_*.sql` erstellt.
- Bei Updates von älteren Installationen:
  - `php scripts/migrate_update_columns.php` (fügt die neuen `update`-Spalten in den Verknüpfungstabellen hinzu)
  - `php scripts/migrate_add_hash_columns.php` (fügt Hash-Spalten für effiziente Änderungserkennung hinzu)

### Konfiguration
- Kopiere `config.php` bzw. passe folgende Einträge an:
  - `paths.data_db`, `paths.status_db`: Speicherort der SQLite-Dateien
  - `paths.media.images` / `paths.media.documents`: Quell- und Zielpfade für Medien
  - `mssql.*`: Zugangsdaten (Host, Port, DB, User, Passwort)
  - `status.max_errors`: Max. Logeinträge, bevor alte Einträge rotiert werden
- Zugangsdaten können optional per Umgebungsvariablen (`AFS_MSSQL_*`) definiert werden

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
| `AFS_Evo` | Orchestriert alle Sync-Schritte, koordiniert Status-Tracker |
| `AFS_Evo_ImageSync` | Importiert Bilder in SQLite, kopiert Dateien, meldet fehlende Bilder |
| `AFS_Evo_DocumentSync` | Importiert Dokumente, kopiert PDFs anhand des Titels, Analysephase |
| `AFS_Evo_AttributeSync` | Überträgt Attribute für Artikel |
| `AFS_Evo_CategorySync` | Synchronisiert Warengruppen (inkl. Parent-Verknüpfungen) |
| `AFS_Evo_ArticleSync` | Hauptlogik: Artikel schreiben, Medien & Attribute verknüpfen |
| `AFS_HashManager` | **NEU:** Effiziente Änderungserkennung via SHA-256 Hashes (siehe [HashManager.md](docs/HashManager.md)) |
| `AFS_Evo_StatusTracker` | Managt `sync_status`/`sync_log`, Fortschrittsbalken & Logs |
| `AFS_Evo_Reset` | Utility zum Leeren aller EVO-Tabellen |
| `AFS_Evo_DeltaExporter` | Exportiert Datensätze mit `update = 1` in `evo_delta.db` und setzt Flags zurück |
| `AFS_MetadataLoader` | Liest Metadaten (Titel/Beschreibung) aus der Dateistruktur und reichert Artikel/Kategorien an |
| `AFS_SyncBusyException` | Spezielle Exception bei parallelen Sync-Versuchen |

Hilfsklassen wie `AFS_Evo_Base` stellen gemeinsame Utilities (Artikelreferenz, Logging, Normalisierung) bereit.

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
