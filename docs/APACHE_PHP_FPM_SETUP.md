# Apache mpm_event + PHP-FPM Setup Guide

## Übersicht

Dieses Dokument beschreibt die Konfiguration von Apache mit dem **mpm_event** Modul in Kombination mit **PHP-FPM** für das AFS-MappingXT Projekt. Diese Konfiguration bietet:

- ✅ **Bessere Concurrency**: Event-basierte Verarbeitung statt prozessbasiert
- ✅ **Saubere Ressourcentrennung**: Web Server und PHP laufen getrennt
- ✅ **Vereinfachte Deployments**: Container-basiertes Setup mit Docker
- ✅ **Optimierte Performance**: Tuning für hohe Last und lange Sync-Operationen
- ✅ **Sicherheit**: Best Practices für Headers und Zugriffskontrolle

## Architektur

```
┌─────────────────────────────────────────────────────────────────┐
│  Client Requests                                                │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  Apache 2.4 (mpm_event)                                         │
│  • Port 80/443                                                  │
│  • Statische Dateien direkt ausliefern                         │
│  • PHP-Requests an PHP-FPM weiterleiten                        │
│  • Compression (mod_deflate)                                    │
│  • Security Headers                                             │
│  • Caching (mod_expires)                                        │
└────────────────────────────┬────────────────────────────────────┘
                             │ Unix Socket
                             │ /run/php/php-fpm.sock
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  PHP-FPM 8.3                                                    │
│  • Dynamic Process Management                                   │
│  • 50 max children                                              │
│  • OPcache + JIT enabled                                        │
│  • Extensions: pdo_sqlite, sqlsrv, yaml                        │
│  • Request timeout: 300s (für lange Syncs)                     │
└─────────────────────────────────────────────────────────────────┘
```

## Vorteile gegenüber mod_php

| Feature | mod_php (mpm_prefork) | PHP-FPM (mpm_event) |
|---------|----------------------|---------------------|
| Concurrency | ~150 gleichzeitige Verbindungen | ~400 gleichzeitige Verbindungen |
| Memory | Jeder Apache-Prozess lädt PHP | PHP nur bei Bedarf geladen |
| Isolation | Keine Trennung | Saubere Trennung Web/PHP |
| PHP-Versionen | Eine Version pro Server | Mehrere Versionen parallel möglich |
| Graceful Reload | Kompletter Apache-Restart | PHP-FPM unabhängig reloadbar |
| Keep-Alive | Blockiert PHP-Prozess | Keep-Alive ohne PHP-Last |

## Schnellstart mit Docker

### 1. Docker Compose starten

```bash
# Kopiere .env.example zu .env und passe Werte an
cp .env.example .env

# Starte die Container
docker-compose up -d

# Logs anzeigen
docker-compose logs -f

# Status prüfen
docker-compose ps
```

### 2. Anwendung aufrufen

```bash
# Web-Interface
http://localhost:8080

# Health Check
http://localhost:8080/api/health.php

# FPM Status (nur lokal)
docker-compose exec apache curl http://localhost/fpm-status
```

### 3. Benchmark durchführen

```bash
# Im Container oder lokal
php scripts/benchmark_server.php --url=http://localhost:8080 --requests=5000 --concurrency=50
```

## Konfigurationsdateien

### docker/php-fpm.conf

PHP-FPM Pool-Konfiguration mit:
- Dynamic Process Management (pm = dynamic)
- 50 max children, 10 start servers
- Request timeout 300s für lange Sync-Operationen
- Slow log bei Requests > 10s
- Status und Ping Endpoints

**Wichtige Parameter:**
```ini
pm.max_children = 50          # Max. Anzahl Child-Prozesse
pm.start_servers = 10         # Start mit 10 Prozessen
pm.min_spare_servers = 5      # Min. 5 Idle-Prozesse
pm.max_spare_servers = 15     # Max. 15 Idle-Prozesse
pm.max_requests = 500         # Nach 500 Requests Prozess neu starten
request_terminate_timeout = 300s  # 5 Minuten für lange Syncs
```

### docker/php.ini

PHP-Konfiguration mit Optimierungen:
- OPcache aktiviert (256MB, 10000 Dateien)
- JIT Tracing aktiviert (128MB Buffer)
- Memory Limit: 256MB
- Max Execution Time: 300s
- Realpath Cache optimiert

**OPcache + JIT:**
```ini
opcache.enable = 1
opcache.memory_consumption = 256
opcache.jit = tracing
opcache.jit_buffer_size = 128M
```

### docker/apache2.conf

Apache Hauptkonfiguration mit mpm_event:
- ServerLimit: 16
- ThreadsPerChild: 25
- MaxRequestWorkers: 400 (16 × 25)
- MaxConnectionsPerChild: 1000
- Compression (mod_deflate)
- Security Headers
- Cache Control

**mpm_event Tuning:**
```apache
ServerLimit              16
StartServers             3
MinSpareThreads          25
MaxSpareThreads          75
ThreadsPerChild          25
MaxRequestWorkers        400
MaxConnectionsPerChild   1000
```

### docker/afs-mappingxt.conf

VirtualHost-Konfiguration:
- PHP-FPM Proxy über Unix Socket
- Security: Blockiert Zugriff auf sensitive Dateien
- Compression für Text/JSON
- Cache Headers für statische Assets
- Security Headers (X-Frame-Options, CSP, etc.)

## Manuelle Installation (ohne Docker)

### Ubuntu/Debian

```bash
# Apache mit mpm_event installieren
sudo apt-get install apache2

# PHP-FPM installieren
sudo apt-get install php8.3-fpm php8.3-{cli,common,mbstring,xml,zip,sqlite3,curl}

# MSSQL Support
sudo apt-get install php8.3-{sqlsrv,pdo-sqlsrv}

# YAML Extension
sudo apt-get install php8.3-yaml

# mpm_prefork deaktivieren, mpm_event aktivieren
sudo a2dismod mpm_prefork
sudo a2dismod php8.3
sudo a2enmod mpm_event
sudo a2enmod proxy_fcgi setenvif

# Apache-PHP-FPM Konfiguration aktivieren
sudo a2enconf php8.3-fpm

# Notwendige Module aktivieren
sudo a2enmod rewrite headers deflate expires

# VirtualHost konfigurieren
sudo cp docker/afs-mappingxt.conf /etc/apache2/sites-available/afs-mappingxt.conf
sudo a2ensite afs-mappingxt
sudo a2dissite 000-default

# PHP-FPM Pool konfigurieren
sudo cp docker/php-fpm.conf /etc/php/8.3/fpm/pool.d/afs-mappingxt.conf

# PHP.ini anpassen
sudo cp docker/php.ini /etc/php/8.3/fpm/conf.d/99-custom.ini

# Services neu starten
sudo systemctl restart php8.3-fpm
sudo systemctl restart apache2

# Status prüfen
sudo systemctl status php8.3-fpm
sudo systemctl status apache2
```

## Performance-Tuning

### 1. PHP-FPM Pool anpassen

Basierend auf verfügbarem RAM:

```bash
# Formel: max_children = (RAM - Overhead) / (Durchschnittlicher Prozess-Speicher)
# Beispiel: (8GB - 2GB) / 120MB ≈ 50 Prozesse

pm.max_children = 50
```

**Empfohlene Werte nach Server-Größe:**

| RAM | max_children | start_servers | min_spare | max_spare |
|-----|--------------|---------------|-----------|-----------|
| 4 GB | 25 | 5 | 3 | 8 |
| 8 GB | 50 | 10 | 5 | 15 |
| 16 GB | 100 | 20 | 10 | 30 |

### 2. Apache mpm_event tuning

```apache
# Für 8GB RAM, moderate Last
ServerLimit              16
ThreadsPerChild          25
MaxRequestWorkers        400
MaxConnectionsPerChild   1000

# Für 16GB RAM, hohe Last
ServerLimit              32
ThreadsPerChild          25
MaxRequestWorkers        800
MaxConnectionsPerChild   1000
```

### 3. OPcache Monitoring

```bash
# Status abrufen (CLI)
php -r "print_r(opcache_get_status());"

# Oder im Browser
# Erstelle opcache-status.php:
<?php
phpinfo(INFO_GENERAL);
opcache_get_status();
?>
```

**Wichtige Metriken:**
- `opcache_hit_rate`: Sollte > 95% sein
- `num_cached_scripts`: Anzahl gecachter Dateien
- `memory_usage.used_memory`: Genutzter Cache-Speicher

### 4. Realpath Cache

```ini
# Reduziert Filesystem-Calls dramatisch
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

## Monitoring

### PHP-FPM Status

```bash
# Status-Seite abrufen
curl http://localhost/fpm-status?full

# Ping-Endpoint
curl http://localhost/fpm-ping
```

**Wichtige Metriken:**
- `active processes`: Aktuell laufende Prozesse
- `total processes`: Gesamtanzahl Prozesse
- `idle processes`: Wartende Prozesse
- `slow requests`: Langsame Requests (> 10s)

### Apache Status

```bash
# Server-Status (mod_status erforderlich)
curl http://localhost/server-status

# Logs
tail -f /var/log/apache2/access.log
tail -f /var/log/apache2/error.log
```

### Logs analysieren

```bash
# PHP-FPM Slow Log
tail -f /var/log/php-fpm-slow.log

# Top 10 langsamste Endpoints
grep -oP 'GET \K[^ ]+' /var/log/apache2/access.log | sort | uniq -c | sort -rn | head -10

# Fehlerrate
grep -c "500\|502\|503\|504" /var/log/apache2/access.log
```

## Benchmark-Ergebnisse

### Test-Setup
- PHP 8.3 + OPcache + JIT
- Apache mpm_event
- 1000 Requests, Concurrency 10

### Erwartete Werte

| Endpoint | Requests/sec | Avg Time | 95th Percentile |
|----------|-------------|----------|-----------------|
| /api/health.php | 500-800 | 10-15ms | 20-30ms |
| / (index.php) | 200-400 | 25-50ms | 60-100ms |
| /api/sync_status.php | 300-500 | 15-30ms | 40-60ms |

### Vergleich zu mod_php

| Metrik | mod_php | PHP-FPM (mpm_event) | Verbesserung |
|--------|---------|---------------------|--------------|
| Requests/sec | ~250 | ~500 | +100% |
| Avg Response Time | 40ms | 20ms | -50% |
| Memory per Request | 8MB | 2MB | -75% |
| Max Connections | 150 | 400 | +167% |

## Troubleshooting

### Problem: 502 Bad Gateway

**Ursache:** PHP-FPM läuft nicht oder Socket ist falsch konfiguriert

```bash
# PHP-FPM Status prüfen
sudo systemctl status php8.3-fpm

# Socket existiert?
ls -la /run/php/php-fpm.sock

# Apache Error Log prüfen
tail -f /var/log/apache2/error.log
```

**Lösung:**
```bash
sudo systemctl restart php8.3-fpm
```

### Problem: Slow Requests / Timeouts

**Ursache:** request_terminate_timeout zu niedrig für lange Syncs

```bash
# Slow Log prüfen
tail -f /var/log/php-fpm-slow.log
```

**Lösung:** `request_terminate_timeout` in php-fpm.conf erhöhen:
```ini
request_terminate_timeout = 600s  ; 10 Minuten
```

### Problem: Too many processes spawned

**Ursache:** pm.max_children zu niedrig für Last

**Lösung:** max_children erhöhen:
```ini
pm.max_children = 100
```

### Problem: High Memory Usage

**Ursache:** Memory Leaks oder zu viele Prozesse

```bash
# Speicherverbrauch pro Prozess
ps aux | grep php-fpm | awk '{sum+=$6} END {print sum/NR/1024 " MB average"}'
```

**Lösung:** max_requests senken für häufigeres Recycling:
```ini
pm.max_requests = 200
```

## Security Best Practices

### 1. PHP-FPM User

```ini
; PHP-FPM sollte nicht als root laufen
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
```

### 2. Disable dangerous functions

```ini
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
```

### 3. Apache Security Headers

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

### 4. Zugriffskontrolle

```apache
# Blockiere Zugriff auf sensitive Verzeichnisse
<DirectoryMatch "^/var/www/html/(db|logs|\.git)">
    Require all denied
</DirectoryMatch>
```

## Nächste Schritte

1. ✅ Docker Setup testen: `docker-compose up -d`
2. ✅ Benchmark durchführen: `php scripts/benchmark_server.php`
3. ✅ Monitoring einrichten: FPM Status + Apache Status
4. ✅ Production Tuning: Parameter basierend auf Last anpassen
5. ✅ SSL/TLS einrichten: Let's Encrypt + HTTPS VirtualHost
6. ✅ Backup-Strategie: Datenbanken + Konfiguration

## Ressourcen

- [Apache mpm_event Documentation](https://httpd.apache.org/docs/2.4/mod/event.html)
- [PHP-FPM Configuration](https://www.php.net/manual/en/install.fpm.configuration.php)
- [OPcache Best Practices](https://www.php.net/manual/en/opcache.configuration.php)
- [Apache Security Tips](https://httpd.apache.org/docs/2.4/misc/security_tips.html)

## Support

Bei Fragen oder Problemen:
1. Apache Error Log prüfen: `/var/log/apache2/error.log`
2. PHP-FPM Log prüfen: `/var/log/php-fpm-errors.log`
3. Slow Log prüfen: `/var/log/php-fpm-slow.log`
4. Docker Logs: `docker-compose logs -f`

---

**Happy Performance Tuning! 🚀**
