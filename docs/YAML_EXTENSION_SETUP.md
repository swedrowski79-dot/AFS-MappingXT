# YAML Extension - Nicht mehr erforderlich

## ⚠️ VERALTET

**Dieses Dokument ist veraltet.** Die YAML-Extension wird nicht mehr benötigt!

## Aktueller Stand (ab Version 1.x)

AFS-MappingXT verwendet jetzt einen **nativen PHP YAML-Parser** (`AFS_YamlParser`), der die externe php-yaml Extension vollständig ersetzt.

### Vorteile der nativen Implementierung

- ✅ **Keine externe Abhängigkeit**: Keine PECL-Extension erforderlich
- ✅ **Einfachere Installation**: Kein libyaml-dev oder pecl install nötig
- ✅ **Portabler**: Funktioniert auf allen PHP-Installationen
- ✅ **Wartbarer**: Code ist im Projekt enthalten und kann angepasst werden
- ✅ **Kleiner Docker-Container**: Weniger Dependencies, schnellerer Build

### Migration

Wenn Sie von einer älteren Version upgraden:

1. Entfernen Sie die YAML-Extension aus Ihrer PHP-Installation (optional)
2. Aktualisieren Sie Ihr Dockerfile (die yaml-Installation entfernen)
3. Das System funktioniert automatisch mit dem nativen Parser

### Technische Details

Der native Parser unterstützt alle YAML-Features, die im Projekt verwendet werden:
- Maps (key: value)
- Nested structures
- Strings, Numbers, Booleans, Null
- Lists
- Comments
- Environment variable substitution

Siehe `classes/AFS_YamlParser.php` für die Implementierung.

---

## Historische Informationen (nur zur Referenz)

<details>
<summary>Ursprüngliche Dokumentation (veraltet)</summary>

## Übersicht (VERALTET)

Die YAML-Extension ist eine **kritische Abhängigkeit** für AFS-MappingXT. Das System verwendet YAML-Dateien für die gesamte Mapping-Konfiguration zwischen AFS-ERP und xt:Commerce.

## Warum YAML?

- **Konfigurationsbasiert**: Alle Feldzuordnungen sind in `mappings/*.yml` definiert
- **Wartbarkeit**: Änderungen ohne Code-Modifikationen
- **Versionskontrolle**: YAML-Dateien sind git-freundlich
- **Lesbarkeit**: Menschenlesbare Konfiguration

## Installation

### In Docker (empfohlen)

Die YAML-Extension wird automatisch beim Docker-Build installiert:

```dockerfile
# System-Abhängigkeit
RUN apt-get install -y libyaml-dev

# PECL Extension mit Version-Pinning
RUN pecl install yaml-2.2.3 \
    && docker-php-ext-enable yaml \
    && php -m | grep -q yaml || (echo "ERROR: yaml extension not loaded" && exit 1)
```

**Wichtig**: Die Installation ist in separate RUN-Befehle aufgeteilt, um:
1. Bessere Fehlerbehandlung
2. Klare Build-Logs
3. Verifizierung der Installation

### Manuelle Installation

#### Debian/Ubuntu

```bash
# System-Bibliothek installieren
sudo apt-get install libyaml-dev

# PECL Extension installieren
sudo pecl install yaml

# Extension aktivieren
echo "extension=yaml.so" | sudo tee /etc/php/8.3/mods-available/yaml.ini
sudo phpenmod yaml

# PHP-FPM neu starten
sudo systemctl restart php8.3-fpm
```

#### RedHat/CentOS

```bash
# System-Bibliothek installieren
sudo yum install libyaml-devel

# PECL Extension installieren
sudo pecl install yaml

# Extension aktivieren
echo "extension=yaml.so" | sudo tee /etc/php.d/40-yaml.ini

# PHP-FPM neu starten
sudo systemctl restart php-fpm
```

## Verifizierung

### Schnelle Überprüfung

```bash
# Extension geladen?
php -m | grep yaml

# Version anzeigen
php -r "echo phpversion('yaml') . PHP_EOL;"
```

### Umfassende Verifizierung

Verwenden Sie das mitgelieferte Verifikations-Script:

```bash
php scripts/verify_yaml_extension.php
```

Das Script testet:
- ✓ Extension ist geladen
- ✓ Alle YAML-Funktionen verfügbar
- ✓ YAML-Parsing funktioniert
- ✓ YAML-File-Parsing funktioniert
- ✓ YAML-Emitting funktioniert

### In Docker-Container verifizieren

```bash
# PHP-FPM Container
docker-compose exec php-fpm php scripts/verify_yaml_extension.php

# Oder manuell
docker-compose exec php-fpm php -m | grep yaml
```

## Erwartete Ausgabe

Bei erfolgreicher Installation:

```
=== YAML Extension Verification ===

Test 1: Checking if YAML extension is loaded...
✓ YAML extension is loaded
ℹ YAML extension version: 2.2.3

Test 2: Checking YAML functions...
✓ Function 'yaml_parse' is available
✓ Function 'yaml_parse_file' is available
✓ Function 'yaml_emit' is available
✓ Function 'yaml_emit_file' is available

Test 3: Testing YAML parsing...
✓ YAML parsing works correctly

Test 4: Testing YAML file parsing...
✓ YAML file parsing works correctly
ℹ Loaded mapping file: source_afs.yml
ℹ Entities found: 3

Test 5: Testing YAML emitting...
✓ YAML emitting works correctly

=== Verification Summary ===

✓ All YAML extension tests passed successfully!
```

## Troubleshooting

### Problem: "YAML extension is not loaded"

**Ursache**: Extension wurde nicht installiert oder nicht aktiviert.

**Lösung**:
```bash
# 1. Prüfen ob libyaml-dev installiert ist
dpkg -l | grep libyaml-dev

# 2. Neu installieren
pecl install yaml

# 3. Extension aktivieren
docker-php-ext-enable yaml

# 4. PHP-FPM neu starten
systemctl restart php-fpm
```

### Problem: "Failed to parse YAML"

**Ursache**: Syntax-Fehler in YAML-Datei oder falsche Einrückung.

**Lösung**:
```bash
# YAML-Syntax validieren (mit Python)
python3 -c "import yaml; yaml.safe_load(open('mappings/source_afs.yml'))"

# Oder online: https://www.yamllint.com/
```

### Problem: Docker-Build schlägt fehl bei YAML-Installation

**Ursache**: Fehlende System-Abhängigkeit `libyaml-dev`.

**Lösung**:
Stellen Sie sicher, dass im Dockerfile ZUERST `libyaml-dev` installiert wird:

```dockerfile
# ZUERST System-Bibliothek
RUN apt-get install -y libyaml-dev

# DANN PECL Extension
RUN pecl install yaml-2.2.3
```

### Problem: "Function 'yaml_parse' does not exist"

**Ursache**: Extension ist geladen, aber Funktionen nicht verfügbar.

**Lösung**:
```bash
# Extension neu kompilieren
pecl uninstall yaml
pecl install yaml-2.2.3

# PHP neu starten
systemctl restart php-fpm apache2
```

## Versionskompatibilität

| YAML Extension | PHP Version | Status |
|----------------|-------------|--------|
| 2.2.3          | 8.1 - 8.3   | ✅ Empfohlen |
| 2.2.2          | 8.1 - 8.3   | ✅ Stabil |
| 2.2.1          | 8.0 - 8.2   | ⚠️ Veraltet |
| 2.1.x          | 7.4 - 8.1   | ❌ Nicht unterstützt |

**Empfehlung**: Verwenden Sie immer die neueste stabile Version (2.2.3).

## Best Practices

### 1. Version Pinning

Verwenden Sie immer eine spezifische Version in Dockerfile:

```dockerfile
# ✅ Gut: Spezifische Version
RUN pecl install yaml-2.2.3

# ❌ Schlecht: Automatische Version
RUN pecl install yaml
```

### 2. Verifizierung nach Installation

Fügen Sie Verifizierungsschritte im Dockerfile hinzu:

```dockerfile
RUN pecl install yaml-2.2.3 \
    && docker-php-ext-enable yaml \
    && php -m | grep -q yaml || (echo "ERROR: yaml not loaded" && exit 1)
```

### 3. Fehlerbehandlung bei MSSQL-Extensions

MSSQL-Extensions können fehlschlagen, YAML nicht:

```dockerfile
# YAML: Muss erfolgreich sein
RUN pecl install yaml-2.2.3 && docker-php-ext-enable yaml

# MSSQL: Optional, Fehler erlaubt
RUN pecl install sqlsrv pdo_sqlsrv || true
```

### 4. Separate RUN-Befehle

Trennen Sie YAML von anderen Extensions:

```dockerfile
# ✅ Gut: Separate Installationen
RUN pecl install yaml-2.2.3 && docker-php-ext-enable yaml
RUN pecl install sqlsrv pdo_sqlsrv || true

# ❌ Schlecht: Alle zusammen
RUN pecl install yaml sqlsrv pdo_sqlsrv
```

## Verwendung im Projekt

Die YAML-Extension wird verwendet von:

1. **AFS_MappingConfig** (`classes/AFS_MappingConfig.php`)
   - Lädt `mappings/source_afs.yml`
   - Definiert Source-Datenbank-Mapping

2. **AFS_TargetMappingConfig** (`classes/AFS_TargetMappingConfig.php`)
   - Lädt `mappings/target_sqlite.yml`
   - Definiert Target-Datenbank-Mapping

3. **Test-Scripts**
   - `test_yaml_mapping.php`
   - `test_target_mapping.php`
   - `test_mixed_mode_validation.php`

Beide Klassen prüfen die Extension beim Laden:

```php
if (!extension_loaded('yaml')) {
    throw new AFS_ConfigurationException(
        'YAML extension is not loaded. Please install php-yaml.'
    );
}
```

## Weiterführende Links

- [YAML Mapping Guide](./YAML_MAPPING_GUIDE.md)
- [Configuration Management](./CONFIGURATION_MANAGEMENT.md)
- [AFS_YamlParser Implementierung](../classes/AFS_YamlParser.php)

</details>

---

**Hinweis**: Die YAML-Extension wird nicht mehr benötigt. Das System verwendet jetzt den nativen `AFS_YamlParser`.
