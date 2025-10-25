# Dead Code Detection Tool

## Übersicht

Das Dead Code Detection Tool (`detect_unused_code.php`) ist ein automatisches Werkzeug zur Erkennung nicht genutzter Klassen und Funktionen im AFS-MappingXT Projekt.

## Features

- 🔍 **Vollständige Code-Analyse**: Scannt alle PHP-Klassen im `/classes` Verzeichnis
- 📊 **Verwendungsanalyse**: Analysiert alle PHP-Dateien im Projekt auf Verwendungsmuster
- 🎯 **Präzise Erkennung**: Identifiziert nicht verwendete Klassen und öffentliche Methoden
- 📝 **Flexible Ausgabe**: Unterstützt sowohl menschenlesbare als auch JSON-Formate
- 🚫 **Intelligente Filterung**: Ignoriert automatisch:
  - Magic Methods (`__construct`, `__toString`, etc.)
  - Exception-Klassen (gelten als Entry-Points)
  - Private/Protected Methoden in verwendeten Klassen

## Installation

Das Tool ist bereits im Repository enthalten und benötigt keine zusätzliche Installation. Es erfordert PHP 8.1 oder höher.

## Verwendung

### Basis-Verwendung

```bash
php scripts/detect_unused_code.php
```

Gibt einen formatierten Report aus mit:
- Statistiken (Anzahl Klassen, Methoden, nicht verwendete Elemente)
- Liste nicht verwendeter Klassen
- Liste nicht verwendeter öffentlicher Methoden

### Ausführliche Ausgabe

```bash
php scripts/detect_unused_code.php --verbose
```

Zeigt zusätzliche Fortschrittsinformationen während der Analyse.

### JSON-Ausgabe

```bash
php scripts/detect_unused_code.php --json
```

Gibt die Ergebnisse im JSON-Format aus, nützlich für automatische Verarbeitung oder CI/CD-Integration:

```json
{
    "statistics": {
        "total_classes": 24,
        "total_methods": 197,
        "unused_classes": 0,
        "unused_methods": 0
    },
    "unused_classes": [],
    "unused_methods": []
}
```

### Hilfe anzeigen

```bash
php scripts/detect_unused_code.php --help
```

## Ausgabe-Format

### Erfolgreicher Report (keine ungenutzten Elemente)

```
===============================================================================
  DEAD CODE DETECTION REPORT
===============================================================================

📊 Statistik:
  • Klassen gesamt:          24
  • Methoden gesamt:         197
  • Nicht verwendete Klassen: 0
  • Nicht verwendete Methoden: 0

✅ Alle Klassen werden verwendet!

✅ Alle öffentlichen Methoden werden verwendet!

===============================================================================
✅ Keine nicht verwendeten Code-Elemente gefunden!
```

Exit-Code: **0**

### Report mit ungenutzten Elementen

```
===============================================================================
  DEAD CODE DETECTION REPORT
===============================================================================

📊 Statistik:
  • Klassen gesamt:          24
  • Methoden gesamt:         213
  • Nicht verwendete Klassen: 0
  • Nicht verwendete Methoden: 14

✅ Alle Klassen werden verwendet!

🟡 Nicht verwendete öffentliche Methoden:
-------------------------------------------------------------------------------
  📦 AFS_Evo (AFS_Evo.php):
     • copyBilder()
     • syncBilder()
     • getStatusTracker()
  
  📦 MSSQL (MSSQL.php):
     • select()
     • selectPaged()
     • count()
     • fetchGenerator()

===============================================================================
⚠️  Es wurden nicht verwendete Code-Elemente gefunden.
   Bitte überprüfen Sie, ob diese entfernt werden können.
```

Exit-Code: **1**

## Integration in CI/CD

Das Tool kann in Continuous Integration Pipelines integriert werden:

```yaml
# GitHub Actions Beispiel
- name: Check for unused code
  run: php scripts/detect_unused_code.php
  continue-on-error: true  # Optional: nicht als Fehler behandeln
```

## Was wird analysiert?

### Klassen-Verwendung

Das Tool erkennt folgende Verwendungsmuster für Klassen:

- `new ClassName()` - Instanziierung
- `ClassName::method()` - Statische Methodenaufrufe
- `extends ClassName` - Vererbung
- `throw new ClassName` - Exception-Werfen
- `catch (ClassName $e)` - Exception-Handling

### Methoden-Verwendung

Das Tool erkennt folgende Verwendungsmuster für Methoden:

- `$obj->methodName()` - Instanz-Methodenaufrufe
- `ClassName::methodName()` - Statische Methodenaufrufe

### Ausgeschlossene Verzeichnisse

Folgende Verzeichnisse werden nicht analysiert:
- `.git`
- `vendor`
- `node_modules`
- `docs`

## Einschränkungen

### Nicht erkannt werden:

1. **Dynamische Aufrufe**: 
   ```php
   $method = 'methodName';
   $obj->$method();  // Wird nicht erkannt
   ```

2. **Call-User-Func Aufrufe**:
   ```php
   call_user_func([$obj, 'methodName']);  // Wird nicht erkannt
   ```

3. **String-basierte Reflexion**:
   ```php
   $class = 'ClassName';
   new $class();  // Wird nicht erkannt
   ```

4. **Externe Verwendung**: Code außerhalb des Projekts, der auf öffentliche APIs zugreift

### Empfehlungen

- Vor dem Entfernen von als "ungenutzt" gemeldeten Methoden:
  - Prüfen Sie, ob die Methode Teil einer dokumentierten öffentlichen API ist
  - Prüfen Sie, ob externe Systeme die Methode verwenden könnten
  - Prüfen Sie, ob die Methode für zukünftige Features vorgesehen ist

- Führen Sie nach dem Entfernen von Code immer Tests durch:
  ```bash
  php scripts/validate_no_hardcodings.php
  php scripts/test_yaml_mapping.php
  php scripts/test_target_mapping.php
  ```

## Historische Ergebnisse

### Cleanup vom 2025-10-25

Initiale Analyse ergab:
- **Nicht verwendete Methoden**: 14
- **Entfernte Methoden**: 16 (inklusive transitiv ungenutzter Methoden)
- **Code-Reduktion**: 326 Zeilen

Entfernte Methoden:
- AFS_Evo: copyBilder(), syncBilder(), getStatusTracker()
- AFS_Evo_StatusTracker: clearErrors()
- AFS_Evo_ImageSync: sync()
- AFS_MappingConfig: getConnection(), getSource()
- AFS_SqlBuilder: buildEntitySelect(), buildEntityUpdate()
- AFS_TargetMappingConfig: getTarget(), getConnection()
- MSSQL: select(), selectPaged(), count(), fetchGenerator(), fetchOneAssoc()

## Weiterentwicklung

Mögliche zukünftige Verbesserungen:

1. **Erweiterte Analyse**:
   - Erkennung nicht verwendeter privater Methoden
   - Analyse von nicht verwendeten Konstanten
   - Erkennung toter Code-Pfade

2. **Automatische Bereinigung**:
   - Option zum automatischen Entfernen nicht verwendeter Methoden
   - Erstellung von Git-Patches

3. **IDE-Integration**:
   - PHPStorm Plugin
   - VS Code Extension

## Support

Bei Fragen oder Problemen:
1. Prüfen Sie die Ausgabe mit `--verbose` für mehr Details
2. Verwenden Sie `--json` für maschinelle Verarbeitung
3. Erstellen Sie ein Issue im Repository

## Siehe auch

- [CODE_STYLE.md](CODE_STYLE.md) - Code-Style Richtlinien
- [CLEANUP_VALIDATION.md](CLEANUP_VALIDATION.md) - Validierung der Code-Bereinigung
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Beitragen zum Projekt
