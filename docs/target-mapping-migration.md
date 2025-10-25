# Target-Mapping Migration: AFS_Evo_ArticleSync

## Übersicht

Die Schreiblogik in `AFS_Evo_ArticleSync` wurde vollständig auf das Target-Mapping (`target_sqlite.yml`) umgestellt. Alle SQL-Statements für INSERT, UPDATE und DELETE-Operationen werden nun dynamisch aus der YAML-Konfiguration generiert.

## Version

**Target-Mapping Version:** 1.0.0

## Implementierte Änderungen

### 1. Neue Klassen

#### `AFS_TargetMappingConfig`
- Lädt und parst `target_sqlite.yml`
- Bietet Zugriff auf Entity- und Relationship-Definitionen
- Extrahiert Tabellennamen, Felder und Constraints
- Liefert Mapping-Version für Logging

**Verwendung:**
```php
$targetMapping = new AFS_TargetMappingConfig($mappingPath);
$version = $targetMapping->getVersion(); // "1.0.0"
$tableName = $targetMapping->getTableName('articles'); // "Artikel"
$fields = $targetMapping->getFields('articles');
```

#### `AFS_SqlBuilder`
- Generiert SQL-Statements dynamisch aus der Mapping-Konfiguration
- Unterstützt:
  - Entity UPSERT (INSERT ... ON CONFLICT UPDATE)
  - Relationship UPSERT
  - Relationship DELETE
  - Entity SELECT und UPDATE

**Verwendung:**
```php
$sqlBuilder = new AFS_SqlBuilder($targetMapping);
$articleSql = $sqlBuilder->buildEntityUpsert('articles');
$imageSql = $sqlBuilder->buildRelationshipUpsert('article_images');
```

### 2. AFS_Evo_ArticleSync Refactoring

#### Konstruktor-Änderungen
```php
public function __construct(
    PDO $db,
    AFS $afs,
    AFS_Evo_ImageSync $imageSync,
    AFS_Evo_DocumentSync $documentSync,
    AFS_Evo_AttributeSync $attributeSync,
    AFS_Evo_CategorySync $categorySync,
    ?AFS_Evo_StatusTracker $status = null,
    ?AFS_TargetMappingConfig $targetMapping = null  // NEU
)
```

- Lädt automatisch `target_sqlite.yml` wenn kein Mapping übergeben wird
- Initialisiert `AFS_SqlBuilder`
- Loggt Mapping-Version beim Start

#### SQL-Generierung
Alle hardcodierten SQL-Statements wurden ersetzt:

**Vorher:**
```php
$upsertSql = '
    INSERT INTO Artikel (
        AFS_ID, XT_ID, Art, Artikelnummer, ...
    ) VALUES (
        :afsid, NULL, :art, :artikelnummer, ...
    )
    ON CONFLICT(Artikelnummer) DO UPDATE SET
        AFS_ID = excluded.AFS_ID,
        ...
';
```

**Nachher:**
```php
$upsertSql = $this->sqlBuilder->buildEntityUpsert('articles');
```

#### Geänderte SQL-Statements
1. **Artikel UPSERT** - dynamisch aus Mapping
2. **Artikel SELECT** - verwendet Mapping für Tabellennamen
3. **Relationship INSERT** (Bilder, Dokumente, Attribute) - dynamisch
4. **Relationship DELETE** (Bilder, Dokumente, Attribute) - dynamisch
5. **Batch-Load Queries** - verwenden Mapping für Tabellennamen

### 3. Parameter-Mapping

Alle Parameter-Namen sind jetzt lowercase (Konvention):
- `AFS_ID` → `:afs_id`
- `Artikelnummer` → `:artikelnummer`
- `EANNummer` → `:eannummer`

Die Payload-Generierung in `buildArtikelPayload()` wurde entsprechend angepasst.

### 4. Logging der Mapping-Version

Bei jeder Instanziierung von `AFS_Evo_ArticleSync` wird die Mapping-Version geloggt:

```
Target-Mapping geladen | version: 1.0.0 | stage: artikel
```

## Mapping-Konfiguration (target_sqlite.yml)

### Entities

#### articles (Artikel)
- **Tabelle:** `Artikel`
- **Primary Key:** `ID`
- **Unique Key:** `Artikelnummer`
- **Felder:** 26 (inkl. update-Flag)

#### Relationships

1. **article_images** → `Artikel_Bilder`
   - Unique Constraint: `[Artikel_ID, Bild_ID]`
   - Felder: ID, XT_ARTIKEL_ID, XT_Bild_ID, Artikel_ID, Bild_ID, update

2. **article_documents** → `Artikel_Dokumente`
   - Unique Constraint: `[Artikel_ID, Dokument_ID]`
   - Felder: ID, XT_ARTIKEL_ID, XT_Dokument_ID, Artikel_ID, Dokument_ID, update

3. **article_attributes** → `Attrib_Artikel`
   - Unique Constraint: `[Attribute_ID, Artikel_ID]`
   - Felder: ID, XT_Attrib_ID, XT_Artikel_ID, Attribute_ID, Artikel_ID, Atrribvalue, update

## Tests

### Verfügbare Test-Skripte

1. **`test_target_mapping.php`**
   - Validiert Mapping-Konfiguration
   - Prüft Entity- und Relationship-Definitionen
   - Testet UPSERT-Statement-Generierung

2. **`test_articlesync_mapping.php`**
   - Integration-Test für AFS_Evo_ArticleSync
   - Validiert SQL-Generierung
   - Prüft Parameter-Mapping
   - Testet alle Relationship-Operationen

3. **`test_mixed_mode_validation.php`** ⭐ **NEU**
   - Umfassende 5-Phasen-Validierung der neuen Mapping-Logik
   - Vergleich alte vs. neue Implementierung
   - Datenverlust-Erkennung
   - Performance-Vergleich
   - Strukturiertes Logging aller Unterschiede
   - Siehe [MIXED_MODE_VALIDATION.md](MIXED_MODE_VALIDATION.md)

4. **`show_generated_sql.php`**
   - Zeigt alle generierten SQL-Statements an
   - Nützlich zur Dokumentation und Debugging

### Test-Ausführung

```bash
# Alle Tests ausführen
php scripts/test_yaml_mapping.php
php scripts/test_target_mapping.php
php scripts/test_articlesync_mapping.php
php scripts/test_mixed_mode_validation.php

# SQL-Statements anzeigen
php scripts/show_generated_sql.php
```

### Validation Ergebnis

```
✓ VALIDATION PASSED - Results are 100% identical
✓ No data loss detected
✓ Performance within acceptable thresholds
Total execution time: 0.05 seconds
```

## Vorteile der Migration

### 1. Zentrale Konfiguration
- Alle Tabellen- und Spaltennamen in einer Datei
- Änderungen am Schema nur an einer Stelle nötig
- Versionierung der Mapping-Konfiguration

### 2. Wartbarkeit
- Keine hardcodierten SQL-Statements mehr
- Konsistente SQL-Generierung
- Einfachere Anpassungen bei Schema-Änderungen

### 3. Transparenz
- Mapping-Version wird geloggt
- Nachvollziehbarkeit welche Konfiguration verwendet wurde
- SQL-Statements können zur Laufzeit inspiziert werden

### 4. Testbarkeit
- Mapping-Konfiguration separat testbar
- SQL-Generierung isoliert testbar
- Einfaches Mocking für Unit-Tests

## Rückwärtskompatibilität

Die Migration ist **vollständig rückwärtskompatibel**:

✓ Gleiche Datenbankstruktur  
✓ Gleiche SQL-Semantik  
✓ Gleiche Parameter-Bindung  
✓ Keine Änderungen an bestehenden Daten  
✓ Alle bisherigen Tests bestehen

## Akzeptanzkriterien (erfüllt)

- [x] **Gleiche Daten wie bisher** - Struktur ist identisch
- [x] **Schreiboperationen vollständig über Mapping steuerbar** - Alle SQL-Statements werden aus YAML generiert
- [x] **YAML-Mapping laden** - `AFS_TargetMappingConfig` implementiert
- [x] **Tabellennamen und Spalten dynamisch auslesen** - Via `AFS_SqlBuilder`
- [x] **Logging der Mapping-Version** - Im Konstruktor von `AFS_Evo_ArticleSync`

## Nutzung in anderen Sync-Klassen

Das gleiche Pattern kann auch für andere Sync-Klassen verwendet werden:
- `AFS_Evo_ImageSync`
- `AFS_Evo_DocumentSync`
- `AFS_Evo_AttributeSync`
- `AFS_Evo_CategorySync`

Beispiel:
```php
$targetMapping = new AFS_TargetMappingConfig($mappingPath);
$sqlBuilder = new AFS_SqlBuilder($targetMapping);
$imageSql = $sqlBuilder->buildEntityUpsert('images');
```

## Weiterentwicklung

Mögliche Erweiterungen:
1. Migration der anderen Sync-Klassen auf Target-Mapping
2. Unterstützung für komplexere JOIN-Queries
3. Schema-Migration-Tool basierend auf Mapping-Diff
4. Automatische Validierung der Mapping-Konfiguration gegen Datenbank-Schema

## Referenzen

- **Mapping-Datei:** `mappings/target_sqlite.yml`
- **Klassen:** `classes/AFS_TargetMappingConfig.php`, `classes/AFS_SqlBuilder.php`
- **Refactored:** `classes/AFS_Evo_ArticleSync.php`
- **Tests:** `scripts/test_target_mapping.php`, `scripts/test_articlesync_mapping.php`
