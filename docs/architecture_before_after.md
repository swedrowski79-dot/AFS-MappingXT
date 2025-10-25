# AFS-MappingXT Architektur-Analyse

## Dokumentzweck
Dieses Dokument erfasst die aktuelle Architektur der AFS-zu-XT-Commerce-Schnittstelle und definiert den Migrationsplan für die Integration eines flexiblen Mapping-Systems.

---

## 1. Architektur-Übersicht (BEFORE)

### 1.1 Systemarchitektur

```
┌─────────────────────────────────────────────────────────────────┐
│                        AFS-MappingXT System                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────┐      ┌──────────────┐      ┌──────────────┐  │
│  │   AFS ERP   │ ───> │  Sync Layer  │ ───> │ XT-Commerce  │  │
│  │   (MSSQL)   │      │   (SQLite)   │      │  (MySQL/DB)  │  │
│  └─────────────┘      └──────────────┘      └──────────────┘  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### 1.2 Datenfluss

1. **Datenquelle**: AFS ERP (Microsoft SQL Server)
2. **Zwischenspeicher**: SQLite Datenbanken (`evo.db`, `status.db`, `evo_delta.db`)
3. **Medien-Dateien**: Bilder und Dokumente werden aus Netzwerk-Shares kopiert
4. **Ziel**: XT-Commerce (über Delta-Export)

---

## 2. Hauptklassen und ihre Verantwortlichkeiten

### 2.1 Datenzugriff und Aggregation

#### `MSSQL` (classes/MSSQL.php)
**Verantwortlichkeiten:**
- Wrapper für Microsoft SQL Server-Verbindungen (sqlsrv)
- Bereitstellung von Hilfsmethoden (`select`, `count`, `scalar`, `fetchAll`)
- Parameter-Quoting und sichere Query-Ausführung
- Connection-Pooling und Timeout-Management

**Schnittstellen:**
- Input: SQL-Queries, Parameter-Arrays
- Output: Assoziative Arrays mit Datenbankzeilen
- Externe Abhängigkeit: PHP sqlsrv Extension

**Mapping-Relevanz:** ⭐⭐ (Niedrig - reine Datenbankzugriffsschicht)

---

#### `AFS_Get_Data` (classes/AFS_Get_Data.php)
**Verantwortlichkeiten:**
- Liest Rohdaten aus AFS MSSQL (Artikel, Warengruppen, Dokumente)
- Normalisiert Datentypen (Int, Float, Bool, DateTime)
- Bereinigt Pfade und konvertiert RTF zu Text/HTML
- Wendet Geschäftslogik-Filter an (z.B. `Mandant = 1`, `Art < 255`, `Internet = 1`)

**Wichtige Methoden:**
- `getArtikel()`: Liefert gefilterte Artikel mit ~25 Feldern
- `getWarengruppen()`: Liefert Kategorien mit Parent-Beziehungen
- `getDokumente()`: Liefert Dokument-Metadaten

**Datenmapping:**
```
AFS-Feld             → Normalisiert als
─────────────────────────────────────
[VK3]                → Preis (float)
[Zusatzfeld01]       → Mindestmenge
[Zusatzfeld03-06]    → Attribname1-4
[Zusatzfeld15-18]    → Attribvalue1-4
[Internet]           → Online (bool)
[Update]             → last_update (ISO 8601)
```

**Mapping-Relevanz:** ⭐⭐⭐⭐⭐ (Sehr hoch - zentrale Transformation von AFS-Daten)

---

#### `AFS` (classes/AFS.php)
**Verantwortlichkeiten:**
- Aggregiert Daten aus `AFS_Get_Data`
- Erstellt deduplizierte Listen von Bildern und Attributen
- Reichert Artikel/Kategorien mit Metadaten an (via `AFS_MetadataLoader`)
- Hält zentrale Arrays: `$Artikel`, `$Warengruppe`, `$Dokumente`, `$Bilder`, `$Attribute`

**Wichtige Methoden:**
- `collectBilder()`: Sammelt alle Bild-Dateinamen aus Artikel und Warengruppen
- `collectAttributeNamen()`: Extrahiert einzigartige Attribut-Namen
- `enrichMetadata()`: Lädt SEO-Metadaten aus Dateisystem

**Mapping-Relevanz:** ⭐⭐⭐⭐ (Hoch - Daten-Aggregation und Anreicherung)

---

### 2.2 Synchronisations-Orchestrierung

#### `AFS_Evo` (classes/AFS_Evo.php)
**Verantwortlichkeiten:**
- **Hauptorchestrator** für den gesamten Sync-Prozess
- Koordiniert alle Sub-Sync-Klassen
- Verwaltet Transaktionen und Fehlerbehandlung
- Protokolliert Fortschritt über `AFS_Evo_StatusTracker`

**Sync-Phasen (in Reihenfolge):**
1. `initialisierung`
2. `bilder` (Import in SQLite)
3. `bilder_kopieren` (Dateien kopieren)
4. `dokumente` (Import)
5. `dokumente_kopieren`
6. `dokumente_pruefung` (Analyse fehlender Dateien)
7. `attribute`
8. `warengruppen`
9. `artikel`
10. `delta_export` (evo_delta.db erstellen)
11. `abschluss`

**Wichtige Methoden:**
- `syncAll()`: Haupteinstiegspunkt für vollständigen Sync
- `importBilder()`, `importDokumente()`, `importAttribute()`, `importWarengruppen()`, `importArtikel()`

**Mapping-Relevanz:** ⭐⭐⭐ (Mittel - Orchestrierung, aber wenig direkte Daten-Transformation)

---

#### `AFS_Evo_StatusTracker` (classes/AFS_Evo_StatusTracker.php)
**Verantwortlichkeiten:**
- Verwaltet Sync-Status in `status.db` (Tabelle: `sync_status`)
- Protokolliert Events in `sync_log` (Level: info/warning/error)
- Bereitstellt Fortschrittsbalken-Daten
- Rotiert alte Log-Einträge (max_errors Limit)

**Wichtige Methoden:**
- `begin(stage, message)`: Startet eine Phase
- `advance(stage, data)`: Aktualisiert Fortschritt
- `complete(data)`: Beendet Sync erfolgreich
- `fail(message, stage)`: Markiert Fehler
- `logInfo/logWarning/logError()`: Strukturierte Logs

**Status-Zustände:**
- `ready`: Bereit für neuen Sync
- `running`: Sync läuft gerade
- `error`: Fehler aufgetreten

**Mapping-Relevanz:** ⭐ (Niedrig - nur Monitoring)

---

### 2.3 Spezifische Sync-Klassen

#### `AFS_Evo_ImageSync` (classes/AFS_Evo_ImageSync.php)
**Verantwortlichkeiten:**
- Importiert Bild-Dateinamen in SQLite-Tabelle `Bilder`
- Kopiert physische Bilddateien vom Quellverzeichnis
- Verfolgt MD5-Checksummen zur Vermeidung unnötiger Kopien
- Meldet fehlende/fehlerhafte Dateien

**Datenmapping:**
```
Eingang (AFS)        → SQLite Tabelle: Bilder
─────────────────────────────────────────────
Bildname (String)    → Bildname, ID (AUTO)
                     → md5, update, uploaded
```

**Mapping-Relevanz:** ⭐⭐ (Niedrig - hauptsächlich Dateiverwaltung)

---

#### `AFS_Evo_DocumentSync` (classes/AFS_Evo_DocumentSync.php)
**Verantwortlichkeiten:**
- Importiert Dokument-Metadaten in Tabelle `Dokumente`
- Kopiert PDF-Dateien basierend auf Titel-Matching
- Analysiert Artikel-zu-Dokument-Beziehungen
- Warnt bei fehlenden Dokumenten

**Datenmapping:**
```
AFS-Dokument         → SQLite Tabelle: Dokumente
─────────────────────────────────────────────
Zaehler             → (nicht gespeichert)
Artikel             → (für Relation genutzt)
Dateiname           → Dateiname
Titel               → Titel (UNIQUE Key)
Art                 → Art
```

**Mapping-Relevanz:** ⭐⭐ (Niedrig - Metadaten-Transfer)

---

#### `AFS_Evo_AttributeSync` (classes/AFS_Evo_AttributeSync.php)
**Verantwortlichkeiten:**
- Importiert Attribut-Namen in Tabelle `Attribute`
- Entfernt Duplikate
- Erstellt ID-Maps für schnelle Zuordnung

**Datenmapping:**
```
AFS-Artikel          → SQLite Tabelle: Attribute
─────────────────────────────────────────────
Attribname1-4       → Attribname (dedupliziert)
                     → ID (AUTO), update
```

**Mapping-Relevanz:** ⭐⭐⭐ (Mittel - Attribut-Extraktion)

---

#### `AFS_Evo_CategorySync` (classes/AFS_Evo_CategorySync.php)
**Verantwortlichkeiten:**
- Importiert Warengruppen in Tabelle `category`
- Verknüpft Parent-Kategorien
- Verarbeitet Kategorie-Bilder (normal + groß)
- Setzt Online/Offline-Status

**Datenmapping:**
```
AFS-Warengruppe      → SQLite Tabelle: category
─────────────────────────────────────────────
Warengruppe         → afsid
Art                 → (genutzt für Logik)
Anhang              → afsparent (Parent-ID)
Ebene               → (für Hierarchie)
Bezeichnung         → name
Internet            → online (bool)
Bild                → picture, picture_id
Bild_gross          → picture_big, picture_big_id
Beschreibung        → description
Meta_Title          → meta_title (angereichert)
Meta_Description    → meta_description (angereichert)
```

**Mapping-Relevanz:** ⭐⭐⭐⭐ (Hoch - komplexe Hierarchie-Mappings)

---

#### `AFS_Evo_ArticleSync` (classes/AFS_Evo_ArticleSync.php)
**Verantwortlichkeiten:**
- **Kern der Daten-Synchronisation**
- Importiert Artikel in Tabelle `Artikel`
- Verwaltet Beziehungen zu Bildern, Dokumenten, Attributen
- Verarbeitet Varianten (Master/Child-Artikel)
- Setzt Update-Flags für Delta-Export

**Datenmapping:**
```
AFS-Artikel          → SQLite Tabelle: Artikel
─────────────────────────────────────────────
Artikel             → AFS_ID
Art                 → Art
Artikelnummer       → Artikelnummer (UNIQUE)
Bezeichnung         → Bezeichnung
EANNummer           → EANNummer
Bestand             → Bestand
Preis               → Preis
Warengruppe         → AFS_Warengruppe_ID, Category
Master              → Master
Mindestmenge        → Mindestmenge
Bruttogewicht       → Gewicht
Online              → Online (bool)
Einheit             → Einheit
Langtext            → Langtext
Werbetext1          → Werbetext
Meta_Title          → Meta_Title (angereichert)
Meta_Description    → Meta_Description (angereichert)
Bemerkung           → Bemerkung
Hinweis             → Hinweis
last_update         → last_update
```

**Join-Tabellen:**
- `Artikel_Bilder`: Verknüpft Artikel mit 1-10 Bildern
- `Artikel_Dokumente`: Verknüpft Artikel mit Dokumenten
- `Attrib_Artikel`: Speichert Attribut-Zuordnungen (Name/Wert-Paare)

**Mapping-Relevanz:** ⭐⭐⭐⭐⭐ (Sehr hoch - zentrale Artikel-Transformation)

---

#### `AFS_Evo_DeltaExporter` (classes/AFS_Evo_DeltaExporter.php)
**Verantwortlichkeiten:**
- Exportiert alle Datensätze mit `update = 1` nach `evo_delta.db`
- Kopiert komplette Tabellenstrukturen
- Setzt Update-Flags nach Export zurück
- Erstellt standalone Delta-Datenbank für XT-Commerce

**Mechanismus:**
1. Findet alle Tabellen mit `update`-Spalte
2. Erstellt leere Delta-Datenbank
3. Attachiert Delta-DB an Hauptdatenbank
4. Kopiert gefilterte Datensätze (`WHERE update = 1`)
5. Setzt `update = 0` in Hauptdatenbank
6. Detachiert Delta-DB

**Mapping-Relevanz:** ⭐⭐ (Niedrig - reine Export-Logik)

---

#### `AFS_Evo_Reset` (classes/AFS_Evo_Reset.php)
**Verantwortlichkeiten:**
- Utility-Klasse zum Leeren aller EVO-Tabellen
- Deaktiviert Foreign Keys vor dem Leeren
- Reaktiviert Foreign Keys danach

**Mapping-Relevanz:** ⭐ (Minimal - Wartungsfunktion)

---

### 2.4 Basis-Klassen und Utilities

#### `AFS_Evo_Base` (classes/AFS_Evo_Base.php)
**Verantwortlichkeiten:**
- Gemeinsame Basis für alle Sync-Klassen
- Stellt Hilfsmethoden bereit:
  - `normalizeStrings()`: Trimmt und entfernt leere Strings
  - `normalizeFilenames()`: Bereinigt Dateinamen
  - `fetchIdMap()`: Lädt ID-Mappings aus Datenbank
  - `ensureDirectory()`: Erstellt Verzeichnisse
  - `logInfo/logWarning/logError()`: Delegiert an StatusTracker

**Mapping-Relevanz:** ⭐⭐ (Niedrig - Infrastruktur)

---

#### `AFS_MetadataLoader` (classes/AFS_MetadataLoader.php)
**Verantwortlichkeiten:**
- Lädt SEO-Metadaten aus Dateisystem-Struktur
- Parst Meta-Titel und Beschreibungen
- Reichert Artikel und Kategorien an

**Datenmapping:**
```
Dateisystem          → Artikel/Kategorie
─────────────────────────────────────
metadata/articles/   → Meta_Title
  {Artikelnummer}/   → Meta_Description
metadata/categories/ → Meta_Title
  {Bezeichnung}/     → Meta_Description
```

**Mapping-Relevanz:** ⭐⭐⭐ (Mittel - Anreicherung von Marketing-Daten)

---

#### `AFS_SyncBusyException` (classes/AFS_SyncBusyException.php)
**Verantwortlichkeiten:**
- Spezielle Exception für parallele Sync-Versuche
- Ermöglicht differenzierte Fehlerbehandlung

**Mapping-Relevanz:** ⭐ (Minimal)

---

## 3. API-Endpunkte (api/)

### 3.1 `_bootstrap.php`
**Zweck:** Zentrale Initialisierung für alle API-Endpunkte
- Lädt Config und Autoloader
- Stellt Helper-Funktionen bereit (`api_json`, `api_error`, `api_ok`)
- Factory-Methoden für Datenbank-Verbindungen
- Prüft Busy-Status vor Sync-Start

---

### 3.2 Sync-Endpunkte

#### `sync_start.php`
**Methode:** POST  
**Funktion:** Startet vollständigen Sync-Prozess  
**Rückgabe:** Status und Summary  
**Fehler:** 409 wenn Sync bereits läuft

#### `sync_status.php`
**Methode:** GET  
**Funktion:** Liefert aktuellen Sync-Status aus `sync_status`  
**Rückgabe:** JSON mit state, stage, progress, message

#### `sync_status_reset.php`
**Methode:** POST  
**Funktion:** Setzt Status zurück auf `ready`  
**Anwendungsfall:** Nach abgebrochenem Sync

#### `sync_errors.php`
**Methode:** GET  
**Funktion:** Liefert Sync-Log mit Filtern  
**Parameter:** `level`, `limit`, `offset`

#### `sync_errors_clear.php`
**Methode:** POST  
**Funktion:** Löscht alle Log-Einträge  

#### `sync_health.php`
**Methode:** GET  
**Funktion:** System-Health-Check  
**Prüft:** SQLite-Dateien, MSSQL-Verbindung

---

### 3.3 Datenbank-Verwaltung

#### `db_setup.php`
**Methode:** POST  
**Funktion:** Initialisiert `evo.db` und `status.db`  
**Skripte:** `scripts/create_evo.sql`, `scripts/create_status.sql`

#### `db_migrate.php`
**Methode:** POST  
**Funktion:** Führt Schema-Updates durch  
**Beispiel:** Fügt `update`-Spalten zu bestehenden Tabellen hinzu

#### `db_clear.php`
**Methode:** POST  
**Funktion:** Leert alle Tabellen in `evo.db`

#### `db_table_view.php`
**Methode:** GET  
**Funktion:** Zeigt Tabelleninhalte für Debugging  
**Parameter:** `db` (main/delta/status), `table`, `limit`, `offset`

---

## 4. Datenbank-Schema (SQLite)

### 4.1 evo.db - Hauptdatenbank

#### Tabelle: `Artikel`
```sql
- ID (PRIMARY KEY)
- AFS_ID (AFS Artikel-ID)
- XT_ID (XT-Commerce Artikel-ID, NULL)
- Art (Artikeltyp)
- Artikelnummer (UNIQUE)
- Bezeichnung
- EANNummer
- Bestand
- Preis
- AFS_Warengruppe_ID
- XT_Category_ID (NULL)
- Category (Kategorie-Name)
- Master (Master-Artikel-Nummer)
- Masterartikel (AFS_ID des Masters)
- Mindestmenge
- Gewicht
- Online (0/1)
- Einheit
- Langtext
- Werbetext
- Meta_Title
- Meta_Description
- Bemerkung
- Hinweis
- "update" (0/1) ← Delta-Flag
- last_update (ISO 8601)
```

#### Tabelle: `category`
```sql
- id (PRIMARY KEY)
- afsid (AFS Warengruppen-ID)
- afsparent (Parent-ID)
- xtid (XT-Commerce ID)
- name
- online (0/1)
- picture
- picture_id (FK → Bilder)
- picture_big
- picture_big_id (FK → Bilder)
- description
- meta_title
- meta_description
- "update" (0/1)
```

#### Tabelle: `Bilder`
```sql
- ID (PRIMARY KEY)
- Bildname (UNIQUE)
- md5 (Checksum)
- "update" (0/1)
- uploaded (0/1)
```

#### Tabelle: `Dokumente`
```sql
- ID (PRIMARY KEY)
- Titel (UNIQUE)
- Dateiname
- Art
- "update" (0/1)
```

#### Tabelle: `Attribute`
```sql
- ID (PRIMARY KEY)
- Attribname (UNIQUE)
- "update" (0/1)
```

#### Join-Tabellen:

**`Artikel_Bilder`**
```sql
- Artikel_ID (FK → Artikel.ID)
- Bild_ID (FK → Bilder.ID)
- "update" (0/1)
- PRIMARY KEY (Artikel_ID, Bild_ID)
```

**`Artikel_Dokumente`**
```sql
- Artikel_ID (FK → Artikel.ID)
- Dokument_ID (FK → Dokumente.ID)
- "update" (0/1)
- PRIMARY KEY (Artikel_ID, Dokument_ID)
```

**`Attrib_Artikel`**
```sql
- Artikel_ID (FK → Artikel.ID)
- Attrib_ID (FK → Attribute.ID)
- Attribvalue (Wert als String)
- "update" (0/1)
- PRIMARY KEY (Artikel_ID, Attrib_ID)
```

---

### 4.2 status.db - Status-Datenbank

#### Tabelle: `sync_status`
```sql
- job (PRIMARY KEY, z.B. 'categories')
- state ('ready'|'running'|'error')
- stage (aktuelle Phase)
- message (Status-Text)
- processed (Anzahl verarbeitet)
- total (Gesamt-Anzahl)
- started_at (ISO 8601)
- finished_at (ISO 8601)
- updated_at (ISO 8601)
```

#### Tabelle: `sync_log`
```sql
- id (PRIMARY KEY)
- job
- level ('info'|'warning'|'error')
- stage
- message
- context (JSON)
- created_at (ISO 8601)
```

---

### 4.3 evo_delta.db - Delta-Export

Identisches Schema wie `evo.db`, aber enthält nur Datensätze mit `update = 1`.

---

## 5. Datenfluss im Detail

### 5.1 Kompletter Sync-Ablauf

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Web/CLI startet Sync (api/sync_start.php oder indexcli.php) │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         v
┌─────────────────────────────────────────────────────────────────┐
│ 2. _bootstrap.php erstellt Umgebung:                            │
│    - createMssql() → MSSQL-Verbindung                           │
│    - AFS_Get_Data($mssql) → Daten-Reader                        │
│    - AFS($dataSource) → Aggregator                              │
│    - createEvoPdo() → SQLite-Verbindung                         │
│    - createStatusTracker() → Status-Verwaltung                  │
│    - AFS_Evo($pdo, $afs, $tracker) → Orchestrator               │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         v
┌─────────────────────────────────────────────────────────────────┐
│ 3. AFS_Evo->syncAll() orchestriert Phasen:                     │
│                                                                  │
│    Phase: initialisierung                                       │
│    ├─> StatusTracker->begin('initialisierung')                 │
│    │                                                             │
│    Phase: bilder                                                │
│    ├─> AFS_Get_Data->getArtikel()                              │
│    │       └─> SQL: SELECT Bild1-10 FROM [Artikel]             │
│    ├─> AFS_Get_Data->getWarengruppen()                         │
│    │       └─> SQL: SELECT Bild, Bild_gross FROM [Warengruppe] │
│    ├─> AFS->collectBilder()                                    │
│    │       └─> Dedupliziert alle Bildnamen                     │
│    ├─> AFS_Evo_ImageSync->import()                             │
│    │       └─> INSERT INTO Bilder (Bildname)                   │
│    │                                                             │
│    Phase: bilder_kopieren                                       │
│    ├─> AFS_Evo_ImageSync->copy($sourceDir, $destDir)          │
│    │       ├─> Baut Datei-Index von $sourceDir                 │
│    │       ├─> Kopiert Dateien mit MD5-Prüfung                 │
│    │       └─> UPDATE Bilder SET md5=?, update=1               │
│    │                                                             │
│    Phase: dokumente                                             │
│    ├─> AFS_Get_Data->getDokumente()                           │
│    │       └─> SQL: SELECT * FROM [Dokument] WHERE Artikel>0  │
│    ├─> AFS_Evo_DocumentSync->import()                          │
│    │       └─> INSERT INTO Dokumente (Titel, Dateiname, Art)   │
│    │                                                             │
│    Phase: dokumente_kopieren                                    │
│    ├─> AFS_Evo_DocumentSync->copy($sourceDir, $destDir)       │
│    │       ├─> Sucht PDF nach Titel-Matching                   │
│    │       └─> Kopiert und setzt update=1                      │
│    │                                                             │
│    Phase: dokumente_pruefung                                    │
│    ├─> AFS_Evo_DocumentSync->analyseCopyIssues()              │
│    │       └─> Warnt bei fehlenden Dokumenten                  │
│    │                                                             │
│    Phase: attribute                                             │
│    ├─> AFS->collectAttributeNamen()                           │
│    │       └─> Extrahiert Attribname1-4 aus Artikeln          │
│    ├─> AFS_Evo_AttributeSync->import()                         │
│    │       └─> INSERT INTO Attribute (Attribname)              │
│    │                                                             │
│    Phase: warengruppen                                          │
│    ├─> AFS_Evo_CategorySync->import()                          │
│    │       ├─> INSERT/UPDATE category                          │
│    │       └─> Verknüpft Parent-Kategorien                     │
│    │                                                             │
│    Phase: artikel                                               │
│    ├─> AFS_Evo_ArticleSync->import()                           │
│    │       ├─> INSERT/UPDATE Artikel                           │
│    │       ├─> INSERT INTO Artikel_Bilder (1-10 Bilder)        │
│    │       ├─> INSERT INTO Artikel_Dokumente                   │
│    │       ├─> INSERT INTO Attrib_Artikel (1-4 Attribute)      │
│    │       └─> Setzt update=1 bei Änderungen                   │
│    │                                                             │
│    Phase: delta_export                                          │
│    ├─> AFS_Evo_DeltaExporter->export()                         │
│    │       ├─> CREATE DATABASE evo_delta.db                    │
│    │       ├─> ATTACH DATABASE delta                           │
│    │       ├─> INSERT INTO delta.* SELECT * WHERE update=1     │
│    │       ├─> UPDATE evo.db SET update=0                      │
│    │       └─> DETACH delta                                    │
│    │                                                             │
│    Phase: abschluss                                             │
│    └─> StatusTracker->complete()                               │
│            └─> UPDATE sync_status SET state='ready'            │
│                                                                  │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         v
┌─────────────────────────────────────────────────────────────────┐
│ 4. evo_delta.db wird von XT-Commerce abgeholt (xt/api/...)     │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. Mapping-Kandidaten für Integration

### 6.1 Höchste Priorität ⭐⭐⭐⭐⭐

#### `AFS_Get_Data` - Feld-Mappings
**Warum:**
- Hardcodierte Feld-Zuordnungen (z.B. `VK3 → Preis`, `Zusatzfeld01 → Mindestmenge`)
- Kunden-spezifische Anforderungen können variieren
- Filter-Bedingungen (`Mandant = 1`, `Art < 255`) sollten konfigurierbar sein

**Vorschlag:**
- Mapping-Konfig: `afs_field_mappings.json`
```json
{
  "artikel": {
    "preis": "VK3",
    "mindestmenge": "Zusatzfeld01",
    "attribname1": "Zusatzfeld03",
    "filter": {
      "Mandant": 1,
      "Art": "<255",
      "Internet": 1
    }
  }
}
```

---

#### `AFS_Evo_ArticleSync` - Zentrale Artikel-Transformation
**Warum:**
- Komplexeste Mapping-Logik im gesamten System
- Verarbeitet Beziehungen zu Bildern, Dokumenten, Attributen
- Master/Child-Logik für Varianten

**Vorschlag:**
- Mapper-Service: `ArticleMapper`
- Transformations-Regeln für:
  - Feldberechnung (z.B. Brutto → Netto)
  - Conditional Mappings (wenn Art = X dann...)
  - Custom-Funktionen (z.B. Gewichtsumrechnung)

---

### 6.2 Hohe Priorität ⭐⭐⭐⭐

#### `AFS_Evo_CategorySync` - Kategorie-Hierarchien
**Warum:**
- Parent-Kind-Beziehungen können komplex sein
- Unterschiedliche Kategorie-Systeme zwischen AFS und XT
- Bild-Zuordnungen (normal vs. groß)

**Vorschlag:**
- Kategorie-Mapping-Tabelle in Datenbank
- Unterstützung für Kategorie-Merging und Umbenennung

---

#### `AFS` - Metadaten-Anreicherung
**Warum:**
- SEO-Daten aus externen Quellen
- Erweiterbar für zusätzliche Anreicherungen (ERP-Daten, PIM-Systeme)

**Vorschlag:**
- Plugin-System für Metadaten-Quellen
- Konfigurierbare Anreicherungs-Reihenfolge

---

### 6.3 Mittlere Priorität ⭐⭐⭐

#### `AFS_Evo_AttributeSync` - Attribut-Extraktion
**Warum:**
- Derzeit fest auf Attribname1-4 und Attribvalue1-4
- Flexible Attribute könnten aus verschiedenen Feldern kommen

**Vorschlag:**
- Attribut-Mapping-Config mit regulären Ausdrücken
- Support für berechnete Attribute

---

### 6.4 Niedrige Priorität ⭐⭐

#### `AFS_Evo_ImageSync`, `AFS_Evo_DocumentSync`
**Warum:**
- Hauptsächlich Dateiverwaltung
- Wenig Geschäftslogik

**Vorschlag:**
- Konfigurierbare Dateinamen-Transformationen
- Custom Upload-Strategien (z.B. Cloud-Storage)

---

## 7. Architektur AFTER (Vorschlag)

### 7.1 Neue Komponenten

```
┌─────────────────────────────────────────────────────────────────┐
│                  Erweitertes AFS-MappingXT System                │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────┐      ┌──────────────────┐      ┌────────────┐ │
│  │   AFS ERP   │ ───> │  Mapping Layer   │ ───> │ XT-Commerce│ │
│  │   (MSSQL)   │      │  ┌────────────┐  │      │  (MySQL)   │ │
│  └─────────────┘      │  │ Mapper Svc │  │      └────────────┘ │
│                        │  └────────────┘  │                      │
│                        │  ┌────────────┐  │                      │
│                        │  │ Rule Engine│  │                      │
│                        │  └────────────┘  │                      │
│                        │  ┌────────────┐  │                      │
│                        │  │Config Store│  │                      │
│                        │  └────────────┘  │                      │
│                        └──────────────────┘                      │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### 7.2 Geplante Klassen/Module

#### `MappingConfigLoader`
- Lädt Mapping-Definitionen aus JSON/YAML/Datenbank
- Validiert Konfiguration
- Versionierung von Mappings

#### `FieldMapper`
- Basis-Interface für alle Mapper
- `map(source, config): target`

#### `ArticleFieldMapper implements FieldMapper`
- Spezialisiert auf Artikel-Mappings
- Unterstützt Transformationen (String, Number, Date, Boolean)
- Custom-Functions via Callbacks

#### `CategoryHierarchyMapper implements FieldMapper`
- Verwaltet Kategorie-Baum-Transformationen
- Flache Liste → Hierarchie und umgekehrt

#### `AttributeMapper implements FieldMapper`
- Flexible Attribut-Extraktion
- Multi-Value Support
- Type-Casting

#### `MappingRuleEngine`
- Evaluiert Bedingungen (if-then-else)
- Unterstützt Ausdrücke (`price * 1.19`, `UPPER(name)`)
- Plugin-fähig für Custom-Funktionen

#### `MappingAuditLog`
- Protokolliert alle Mapping-Operationen
- Ermöglicht Nachverfolgbarkeit
- Debugging von Transformationen

---

### 7.3 Migrations-Strategie

#### Phase 1: Abstraktion (nicht-invasiv)
1. Bestehende Klassen erweitern (nicht ersetzen)
2. `MappingConfigLoader` einführen
3. Optional Mappings aus Config laden, sonst Fallback auf Hardcoded

#### Phase 2: Mapper-Integration
1. `FieldMapper`-Interface und Basis-Implementierungen
2. `AFS_Get_Data` nutzt `ArticleFieldMapper` optional
3. A/B-Testing: Config vs. Hardcoded

#### Phase 3: Rule Engine
1. `MappingRuleEngine` für komplexe Transformationen
2. Migration von Business-Logic aus Code in Regeln
3. UI für Mapping-Verwaltung (optional)

#### Phase 4: Vollständige Migration
1. Alle Hardcoded-Mappings entfernen
2. Config-First Ansatz
3. Deprecation Warnings für alte APIs

---

## 8. Offene Fragen / Entscheidungen

### 8.1 Technische Entscheidungen

**Frage:** Soll das Mapping-System Plugin-basiert sein oder ein Monolith?  
**Optionen:**
- A) Monolithisch: Alle Mapper im Core
- B) Plugin-System: Erweiterbar via Composer-Packages
- C) Hybrid: Core-Mapper + Plugin-Support

**Empfehlung:** C (Hybrid)

---

**Frage:** Wo sollen Mapping-Configs gespeichert werden?  
**Optionen:**
- A) JSON/YAML-Dateien im `/config`-Verzeichnis
- B) Datenbank-Tabellen (`mapping_configs`)
- C) Beides (Files für Basis, DB für Overrides)

**Empfehlung:** C (Beides)

---

**Frage:** Soll der Mapper rückwärtskompatibel sein?  
**Antwort:** Ja, bestehende Installationen müssen ohne Config funktionieren (Fallback auf Hardcoded).

---

### 8.2 Geschäftslogik-Entscheidungen

**Frage:** Welche Transformations-Typen werden benötigt?  
**Identifizierte Typen:**
- String-Transformationen (UPPER, LOWER, TRIM, SUBSTR)
- Numerische (ROUND, FORMAT, CONVERT)
- Datum (FORMAT, PARSE, ADD_DAYS)
- Bedingt (IF, COALESCE, CASE)
- Aggregation (CONCAT, JOIN, SPLIT)

---

**Frage:** Wie sollen Fehler bei Mappings behandelt werden?  
**Optionen:**
- A) Strict: Fehler brechen Sync ab
- B) Lenient: Fehler loggen, mit Default fortfahren
- C) Konfigurierbar pro Mapping

**Empfehlung:** C (Konfigurierbar)

---

## 9. Anhang

### 9.1 Klassendiagramm (vereinfacht)

```
┌─────────────────┐
│     MSSQL       │
└────────┬────────┘
         │
         v
┌─────────────────┐        ┌──────────────┐
│ AFS_Get_Data    │───────>│     AFS      │
└─────────────────┘        └──────┬───────┘
                                   │
                                   v
                          ┌────────────────┐
                          │   AFS_Evo      │
                          └────────┬───────┘
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                           │
        v                          v                           v
┌───────────────┐        ┌──────────────────┐      ┌──────────────────┐
│ ImageSync     │        │  DocumentSync     │      │  AttributeSync   │
└───────────────┘        └──────────────────┘      └──────────────────┘
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                           │
        v                          v                           v
┌───────────────┐        ┌──────────────────┐      ┌──────────────────┐
│ CategorySync  │        │  ArticleSync      │      │ DeltaExporter    │
└───────────────┘        └──────────────────┘      └──────────────────┘
                                   │
                                   v
                         ┌──────────────────┐
                         │ StatusTracker    │
                         └──────────────────┘
```

---

### 9.2 Daten-Abhängigkeiten

**Import-Reihenfolge (muss eingehalten werden):**
1. Bilder → Dokumente (unabhängig voneinander)
2. Attribute (unabhängig)
3. Warengruppen (nutzt Bilder für picture_id)
4. Artikel (nutzt Warengruppen, Bilder, Dokumente, Attribute)
5. Delta-Export (nutzt alle obigen)

**Foreign Key Constraints:**
- `category.picture_id` → `Bilder.ID`
- `category.picture_big_id` → `Bilder.ID`
- `Artikel.AFS_Warengruppe_ID` → `category.afsid`
- `Artikel_Bilder.Artikel_ID` → `Artikel.ID`
- `Artikel_Bilder.Bild_ID` → `Bilder.ID`
- `Artikel_Dokumente.Artikel_ID` → `Artikel.ID`
- `Artikel_Dokumente.Dokument_ID` → `Dokumente.ID`
- `Attrib_Artikel.Artikel_ID` → `Artikel.ID`
- `Attrib_Artikel.Attrib_ID` → `Attribute.ID`

---

### 9.3 Performance-Überlegungen

**Aktuelle Optimierungen:**
- Batch-Insert mit Transaktionen
- `PRAGMA journal_mode=OFF` für Delta-Export
- ID-Maps werden einmalig geladen (kein N+1 Problem)
- Fortschritts-Updates nur alle 100 Datensätze

**Verbesserungspotential:**
- Index auf `update`-Spalten für schnellere Delta-Queries
- Prepared Statements cachen (weniger Parse-Overhead)
- Parallele Verarbeitung für unabhängige Phasen (Bilder + Dokumente)

---

### 9.4 Sicherheitsüberlegungen

**Aktuelle Maßnahmen:**
- Prepared Statements (SQL-Injection-Schutz)
- Path-Validierung bei Datei-Operationen
- Exception-Handling verhindert Datenlecks

**Zu überprüfen:**
- API-Authentifizierung (aktuell keine)
- Rate-Limiting für API-Endpoints
- Verschlüsselung sensibler Konfig-Daten (DB-Passwörter)

---

## 10. Zusammenfassung und nächste Schritte

### 10.1 Haupterkenntnisse

1. **Gut strukturiert:** Klare Trennung von Verantwortlichkeiten (Datenzugriff, Aggregation, Sync)
2. **Wartbar:** Konsistente Namenskonventionen, Basis-Klassen für gemeinsame Logik
3. **Erweiterbar:** Modularer Aufbau ermöglicht einfache Integration neuer Features

### 10.2 Kritische Mapping-Punkte

- `AFS_Get_Data`: Feld-Zuordnungen und Filter
- `AFS_Evo_ArticleSync`: Komplexe Artikel-Transformationen
- `AFS_Evo_CategorySync`: Hierarchie-Mappings

### 10.3 Nächste Schritte

1. ✅ Dieses Dokument reviewen mit Team
2. [ ] Entscheidungen zu technischen Fragen treffen (Kap. 8)
3. [ ] Mapping-Config-Schema definieren (JSON-Schema)
4. [ ] Prototyp: `MappingConfigLoader` + einfacher `FieldMapper`
5. [ ] Migrations-Plan für bestehende Installationen
6. [ ] Integration-Tests für Mapper schreiben
7. [ ] Dokumentation für Kunden (Mapping-Config-Beispiele)

---

**Version:** 1.0  
**Erstellt am:** 2025-10-24  
**Autor:** AFS-MappingXT Entwicklungsteam  
**Status:** Entwurf zur Diskussion
