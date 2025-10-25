# Mixed Mode Validation Test - Dokumentation

## Überblick

Das Mixed Mode Validation Test Script (`test_mixed_mode_validation.php`) validiert die neue YAML-basierte Mapping-Logik und stellt sicher, dass keine Datenverluste auftreten und die Performance akzeptabel ist.

## Ziel

Sicherstellen, dass die neue Mapping-Logik identische Ergebnisse liefert wie erwartet.

## Testphasen

### Phase 1: Konfigurationsvalidierung
- Lädt und validiert `source_afs.yml` und `target_sqlite.yml`
- Prüft, ob alle erforderlichen Entities definiert sind
- Verifiziert Mapping-Version

**Getestete Entities:**
- Source: `Artikel`, `Warengruppe`, `Dokumente`
- Target: `articles`, `categories`, `documents`, `images`, `attributes`

### Phase 2: SQL-Generierungsvalidierung
- Testet dynamische SQL-Generierung aus YAML-Konfiguration
- Validiert UPSERT-Statements für Artikel und Beziehungen
- Prüft DELETE-Statements für Beziehungstabellen
- Verifiziert Parameter-Mapping-Konsistenz

**Getestete SQL-Operationen:**
- Article UPSERT (INSERT ... ON CONFLICT UPDATE)
- Relationship UPSERT für `article_images`, `article_documents`, `article_attributes`
- Relationship DELETE für alle Beziehungen
- Parameter-Mapping (lowercase naming convention)

### Phase 3: Datenkonsistenzvalidierung
- Prüft Vollständigkeit aller Felder
- Validiert Unique Keys und Constraints
- Überprüft Beziehungsfeld-Definitionen

**Validierte Felder (Artikel):**
```
AFS_ID, XT_ID, Art, Artikelnummer, Bezeichnung, EANNummer,
Bestand, Preis, AFS_Warengruppe_ID, XT_Category_ID, Category,
Master, Masterartikel, Mindestmenge, Gewicht, Online, Einheit,
Langtext, Werbetext, Meta_Title, Meta_Description, Bemerkung,
Hinweis, update, last_update
```

### Phase 4: Performance-Vergleich
- Misst Konfigurationsladezeit
- Misst SQL-Generierungszeit
- Misst Parameter-Mapping-Zeit
- Vergleicht mit definierten Schwellwerten

**Performance-Schwellwerte:**
- Konfigurationsladen: < 50 ms
- SQL-Generierung: < 10 ms
- Parameter-Mapping: < 5 ms

**Gemessene Werte (100 Iterationen):**
- Konfigurationsladen: ~0.25 ms ✓
- SQL-Generierung: ~0.15 ms ✓
- Parameter-Mapping: ~0.02 ms ✓

### Phase 5: Datenverlust-Erkennung
- Prüft auf fehlende Datentyp-Definitionen
- Validiert Auto-Increment-Konfiguration
- Überprüft Nullable-Feld-Konfiguration
- Verifiziert Unique Constraints

**Geprüfte Aspekte:**
- Alle Felder haben explizite Datentyp-Definitionen ✓
- ID-Feld ist als Auto-Increment konfiguriert ✓
- Kritische Felder (Artikelnummer, Bezeichnung) sind non-nullable ✓
- Alle Beziehungen haben Unique Constraints ✓

## Ausführung

```bash
# Test ausführen
php scripts/test_mixed_mode_validation.php

# Mit detaillierter Ausgabe
php scripts/test_mixed_mode_validation.php 2>&1 | tee validation.log
```

## Ausgabe

Das Script erzeugt:
1. **Konsolen-Ausgabe**: Detaillierter Fortschritt und Ergebnisse
2. **Log-Datei**: `logs/mixed_mode_validation_YYYY-MM-DD_HH-MM-SS.log`

### Exit Codes
- `0`: Alle Tests bestanden (100% identisch)
- `1`: Tests fehlgeschlagen (Unterschiede gefunden)

## Akzeptanzkriterien

✅ **Ergebnis 100% identisch**
- Alle Konfigurationen korrekt geladen
- SQL-Generierung korrekt
- Alle Felder vorhanden
- Constraints korrekt definiert

✅ **Log dokumentiert Unterschiede**
- Strukturierte JSON-Log-Einträge
- Timestamp für jeden Test
- Detaillierte Fehlerinformationen
- Zusammenfassung am Ende

✅ **Keine Datenverluste**
- Alle erforderlichen Felder vorhanden
- Datentypen korrekt definiert
- Unique Constraints vorhanden
- Auto-Increment korrekt konfiguriert

✅ **Performance akzeptabel**
- Konfigurationsladen < 50 ms
- SQL-Generierung < 10 ms
- Parameter-Mapping < 5 ms

## Testergebnis (2025-10-25)

```
=== Validation Report ===
Total execution time: 0.05 seconds
Total differences found: 0

✓ VALIDATION PASSED - Results are 100% identical
✓ No data loss detected
✓ Performance within acceptable thresholds
```

### Detaillierte Ergebnisse

**Phase 1: Konfigurationsvalidierung** ✓
- Source mapping geladen
- Target mapping geladen (Version 1.0.0)
- Alle Entities vorhanden

**Phase 2: SQL-Generierungsvalidierung** ✓
- Article UPSERT SQL gültig
- Alle Relationship SQL gültig
- Parameter-Mapping konsistent

**Phase 3: Datenkonsistenzvalidierung** ✓
- 31 Artikel-Felder vorhanden
- Alle Relationship-Felder vollständig
- Unique Key korrekt (Artikelnummer)

**Phase 4: Performance-Vergleich** ✓
- Config loading: 0.25 ms (Schwellwert: 50 ms)
- SQL generation: 0.15 ms (Schwellwert: 10 ms)
- Parameter mapping: 0.02 ms (Schwellwert: 5 ms)

**Phase 5: Datenverlust-Erkennung** ✓
- Alle Felder haben Datentyp-Definitionen
- Auto-Increment korrekt konfiguriert
- Nullable-Konfiguration korrekt
- Unique Constraints vorhanden

## Integration in CI/CD

Das Script kann in CI/CD-Pipelines integriert werden:

```yaml
# .github/workflows/validation.yml
- name: Run Mixed Mode Validation
  run: php scripts/test_mixed_mode_validation.php
```

## Fehlerbehebung

### Fehler: "Configuration file not found"
- Prüfen Sie, ob `mappings/source_afs.yml` und `mappings/target_sqlite.yml` existieren
- Verzeichnisrechte prüfen

### Fehler: "Missing entity in source mapping"
- YAML-Datei auf fehlende Entity-Definitionen prüfen
- Entity-Namen sind case-sensitive

### Fehler: "SQL generation validation failed"
- Mapping-Konfiguration auf Syntaxfehler prüfen
- Tabellen- und Spaltennamen validieren

### Performance-Warnung
- Wenn Performance-Schwellwerte überschritten werden, könnte YAML-Caching implementiert werden
- Aktuelle Performance ist jedoch weit unter den Schwellwerten

## Referenzen

- **Script**: `scripts/test_mixed_mode_validation.php`
- **Logs**: `logs/mixed_mode_validation_*.log`
- **Mappings**: `mappings/source_afs.yml`, `mappings/target_sqlite.yml`
- **Dokumentation**: `docs/target-mapping-migration.md`
