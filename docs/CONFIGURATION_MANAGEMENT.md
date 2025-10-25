# Konfigurations-Management

## Übersicht

AFS-MappingXT verwendet ein **einheitliches, umgebungsvariablenbasiertes Konfigurationsmanagement**. Alle Einstellungen können zentral über die `.env`-Datei verwaltet werden.

## Prinzipien

### 1. **Zentrale Konfiguration**
Alle konfigurierbaren Werte werden über Umgebungsvariablen gesteuert, die in der `.env`-Datei definiert werden.

### 2. **Fallback zu sinnvollen Defaults**
Wenn eine Umgebungsvariable nicht gesetzt ist, verwendet die Anwendung einen sinnvollen Default-Wert.

### 3. **Trennung von Code und Konfiguration**
Konfiguration wird niemals im Code hardcodiert. Alle Werte kommen aus `config.php`, das wiederum Umgebungsvariablen nutzt.

### 4. **Docker-Unterstützung**
Die Konfiguration funktioniert sowohl mit Docker als auch bei manueller Installation.

## Verfügbare Umgebungsvariablen

### Docker/Web Server

| Variable | Beschreibung | Default | Beispiel |
|----------|--------------|---------|----------|
| `HTTP_PORT` | Port für den Apache-Webserver | `8080` | `8080` |
| `ADMINER_PORT` | Port für Adminer (Datenbank-GUI) | `8081` | `8081` |

### PHP-Konfiguration

| Variable | Beschreibung | Default | Beispiel |
|----------|--------------|---------|----------|
| `PHP_MEMORY_LIMIT` | PHP Memory Limit | `256M` | `256M`, `512M`, `1G` |
| `PHP_MAX_EXECUTION_TIME` | Max. Ausführungszeit in Sekunden | `300` | `300`, `600` |
| `TZ` | Zeitzone | `Europe/Berlin` | `Europe/Berlin`, `UTC` |

### MSSQL-Datenbankverbindung

| Variable | Beschreibung | Default | Beispiel |
|----------|--------------|---------|----------|
| `AFS_MSSQL_HOST` | MSSQL Server Hostname/IP | `10.0.1.82` | `10.0.1.82`, `sql.example.com` |
| `AFS_MSSQL_PORT` | MSSQL Server Port | `1435` | `1435`, `1433` |
| `AFS_MSSQL_DB` | Datenbankname | `AFS_WAWI_DB` | `AFS_WAWI_DB` |
| `AFS_MSSQL_USER` | Datenbankbenutzer | `sa` | `sa`, `afs_user` |
| `AFS_MSSQL_PASS` | Datenbankpasswort | `W3laf!x` | - |

### Anwendungskonfiguration

| Variable | Beschreibung | Default | Beispiel |
|----------|--------------|---------|----------|
| `AFS_MEDIA_SOURCE` | Quellverzeichnis für Medien | `/var/www/data` | `/mnt/share/media` |
| `AFS_METADATA_ARTICLES` | Metadaten-Verzeichnis Artikel | `null` | `/path/to/article/metadata` |
| `AFS_METADATA_CATEGORIES` | Metadaten-Verzeichnis Kategorien | `null` | `/path/to/category/metadata` |
| `AFS_MAX_ERRORS` | Max. Fehlereinträge im Log | `200` | `200`, `500` |
| `AFS_LOG_ROTATION_DAYS` | Tage bis Log-Rotation | `30` | `30`, `60`, `90` |
| `AFS_MAPPING_VERSION` | Mapping-Version für Logs | `1.0.0` | `1.0.0`, `2.0.0` |
| `AFS_ENABLE_FILE_LOGGING` | JSON-Logging aktivieren | `true` | `true`, `false` |

## Verwendung

### Mit Docker (empfohlen)

1. **`.env`-Datei erstellen:**
   ```bash
   cp .env.example .env
   ```

2. **Werte anpassen:**
   ```bash
   nano .env
   ```

3. **Container starten:**
   ```bash
   docker-compose up -d
   ```

Die Umgebungsvariablen werden automatisch:
- An den PHP-FPM Container übergeben
- Vom Docker-Entrypoint-Script verarbeitet
- In PHP-Konfigurationsdateien eingesetzt

### Manuelle Installation

1. **`.env`-Datei erstellen:**
   ```bash
   cp .env.example .env
   nano .env
   ```

2. **Umgebungsvariablen exportieren:**
   
   **Option A: In der Shell-Session (temporär):**
   ```bash
   export $(cat .env | xargs)
   ```

   **Option B: System-weit (persistent):**
   ```bash
   # In /etc/environment (Ubuntu/Debian)
   sudo nano /etc/environment
   # Variablen hinzufügen
   ```

   **Option C: Für Apache (bei mod_php):**
   ```apache
   # In Apache vhost oder .htaccess
   SetEnv AFS_MSSQL_HOST "10.0.1.82"
   SetEnv AFS_MSSQL_PORT "1435"
   # ...
   ```

   **Option D: Für PHP-FPM:**
   ```ini
   ; In PHP-FPM Pool-Konfiguration
   env[AFS_MSSQL_HOST] = 10.0.1.82
   env[AFS_MSSQL_PORT] = 1435
   ; ...
   ```

## Konfigurationsablauf

### Docker-Umgebung

```
.env
  ↓
docker-compose.yml (liest .env)
  ↓
PHP-FPM Container (erhält ENV-Variablen)
  ↓
docker-entrypoint.sh (generiert php.ini und php-fpm.conf)
  ↓
PHP-FPM (verwendet konfigurierte Werte)
  ↓
config.php (liest ENV-Variablen mit getenv())
  ↓
Anwendung
```

### Entrypoint-Script

Das `docker/docker-entrypoint.sh` Script:
1. Liest Umgebungsvariablen (PHP_MEMORY_LIMIT, PHP_MAX_EXECUTION_TIME, TZ)
2. Generiert `php.ini` mit den konfigurierten Werten
3. Generiert `php-fpm.conf` mit den konfigurierten Werten
4. Übergibt MSSQL-Variablen an PHP via env[]
5. Startet PHP-FPM

### config.php

Die Datei `config.php`:
1. Ist die zentrale Konfigurationsdatei der Anwendung
2. Nutzt `getenv()` zum Lesen von Umgebungsvariablen
3. Bietet Fallback-Defaults für alle Werte
4. Wird von allen Komponenten der Anwendung verwendet

## Beispiele

### Beispiel 1: Entwicklungsumgebung

```env
# .env für Entwicklung
HTTP_PORT=8080
ADMINER_PORT=8081

PHP_MEMORY_LIMIT=512M
PHP_MAX_EXECUTION_TIME=600
TZ=Europe/Berlin

AFS_MSSQL_HOST=localhost
AFS_MSSQL_PORT=1433
AFS_MSSQL_DB=AFS_DEV
AFS_MSSQL_USER=dev_user
AFS_MSSQL_PASS=dev_password

AFS_MEDIA_SOURCE=/home/user/dev/media
AFS_MAX_ERRORS=500
AFS_LOG_ROTATION_DAYS=7
```

### Beispiel 2: Produktionsumgebung

```env
# .env für Produktion
HTTP_PORT=80
ADMINER_PORT=8081

PHP_MEMORY_LIMIT=1G
PHP_MAX_EXECUTION_TIME=900
TZ=Europe/Berlin

AFS_MSSQL_HOST=sql.production.example.com
AFS_MSSQL_PORT=1433
AFS_MSSQL_DB=AFS_WAWI_DB
AFS_MSSQL_USER=prod_user
AFS_MSSQL_PASS=***SECURE_PASSWORD***

AFS_MEDIA_SOURCE=/mnt/nas/afs/media
AFS_METADATA_ARTICLES=/mnt/nas/afs/metadata/articles
AFS_METADATA_CATEGORIES=/mnt/nas/afs/metadata/categories
AFS_MAX_ERRORS=1000
AFS_LOG_ROTATION_DAYS=90
AFS_MAPPING_VERSION=1.0.0
```

### Beispiel 3: Minimal-Konfiguration

Wenn nur die MSSQL-Verbindung geändert werden muss, reicht:

```env
# .env minimal
AFS_MSSQL_HOST=192.168.1.100
AFS_MSSQL_USER=myuser
AFS_MSSQL_PASS=mypassword
```

Alle anderen Werte nutzen ihre Defaults.

## Validierung

### Konfiguration prüfen

```bash
# Server-Konfiguration validieren
php scripts/validate_server_config.php

# Config-Werte anzeigen (ohne Passwörter)
php -r "print_r(require 'config.php');"
```

### Umgebungsvariablen prüfen

```bash
# In Docker-Container
docker-compose exec php-fpm env | grep AFS

# Lokal
env | grep AFS
```

## Best Practices

### ✅ DO

- **`.env` für lokale Konfiguration verwenden**
- **`.env.example` als Template pflegen**
- **Sensible Daten NUR in `.env` speichern (nicht committen)**
- **Produktions-Werte über Container-Orchestrierung injizieren**
- **Dokumentation aktuell halten**

### ❌ DON'T

- **`.env` NICHT in Git committen** (ist in `.gitignore`)
- **Keine Passwörter in Code oder Dokumentation**
- **Keine Hardcoded-Werte in config.php oder anderen PHP-Dateien**
- **Keine unterschiedlichen Konfigurationsmechanismen mischen**

## Sicherheit

### Sensible Daten

Sensible Daten wie Passwörter sollten:
- Nur in `.env` oder über sichere Umgebungsvariablen gesetzt werden
- Niemals in Git eingecheckt werden
- In Produktionsumgebungen über Secrets-Management (z.B. Docker Secrets, Kubernetes Secrets) verwaltet werden

### Dateiberechtigungen

```bash
# .env sollte nur für Owner lesbar sein
chmod 600 .env

# Datenbank-Dateien schützen
chmod 600 db/*.db
```

## Migration von alter Konfiguration

Falls Sie von einer älteren Version upgraden, bei der Werte direkt in `config.php` hardcodiert waren:

1. **Werte aus config.php extrahieren:**
   ```bash
   grep -E "^\s*'(host|port|database|username|password)'" config.php
   ```

2. **In .env übertragen:**
   ```bash
   cp .env.example .env
   # Werte eintragen
   ```

3. **Testen:**
   ```bash
   php scripts/validate_server_config.php
   docker-compose up -d
   ```

## Troubleshooting

### Problem: Umgebungsvariablen werden nicht erkannt

**Lösung 1: Docker**
```bash
# Container neu bauen und starten
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

**Lösung 2: PHP-FPM**
```bash
# PHP-FPM neu starten
sudo systemctl restart php8.3-fpm
```

**Lösung 3: Apache**
```bash
# Apache neu starten
sudo systemctl restart apache2
```

### Problem: Falsche Werte werden verwendet

**Debug-Script erstellen:**
```php
<?php
// debug_env.php
echo "AFS_MSSQL_HOST: " . getenv('AFS_MSSQL_HOST') . "\n";
echo "PHP_MEMORY_LIMIT: " . ini_get('memory_limit') . "\n";

$config = require 'config.php';
print_r($config['mssql']);
```

```bash
php debug_env.php
```

### Problem: Docker-Container startet nicht

**Logs prüfen:**
```bash
docker-compose logs php-fpm
docker-compose logs apache
```

**Entrypoint-Script prüfen:**
```bash
docker-compose exec php-fpm cat /usr/local/etc/php/conf.d/custom.ini
docker-compose exec php-fpm cat /usr/local/etc/php-fpm.d/zz-custom.conf
```

## Zusammenfassung

Das einheitliche Konfigurations-Management von AFS-MappingXT bietet:

✅ **Zentrale Verwaltung** aller Einstellungen in `.env`  
✅ **Flexibilität** durch Umgebungsvariablen  
✅ **Sicherheit** durch Trennung von Code und Konfiguration  
✅ **Docker-Kompatibilität** out-of-the-box  
✅ **Sinnvolle Defaults** für alle Werte  
✅ **Einfache Migration** zwischen Umgebungen  

Für weitere Fragen siehe:
- [README.md](../README.md)
- [QUICK_START_DOCKER.md](QUICK_START_DOCKER.md)
- [APACHE_PHP_FPM_SETUP.md](APACHE_PHP_FPM_SETUP.md)
