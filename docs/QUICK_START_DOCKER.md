# Quick Start: Apache mpm_event + PHP-FPM

## Mit Docker (empfohlen)

```bash
# 1. Umgebungsvariablen konfigurieren
cp .env.example .env
# Editiere .env nach Bedarf

# 2. Container starten
docker-compose up -d

# 3. Status prüfen
docker-compose ps

# 4. Logs anzeigen
docker-compose logs -f

# 5. Anwendung öffnen
# Browser: http://localhost:8080
```

## Container verwalten

```bash
# Stoppen
docker-compose stop

# Neu starten
docker-compose restart

# Logs anzeigen
docker-compose logs -f apache
docker-compose logs -f php-fpm

# In Container einsteigen
docker-compose exec php-fpm bash
docker-compose exec apache bash

# Herunterfahren und aufräumen
docker-compose down
```

## Benchmark durchführen

```bash
# Standard-Benchmark (1000 requests, concurrency 10)
php scripts/benchmark_server.php

# Erweitert
php scripts/benchmark_server.php \
  --url=http://localhost:8080 \
  --requests=5000 \
  --concurrency=50

# Spezifische Endpoints
php scripts/benchmark_server.php \
  --endpoints=/,/api/health.php,/api/sync_status.php
```

## Wichtige URLs

- Web-Interface: http://localhost:8080
- Health Check: http://localhost:8080/api/health.php
- Adminer (optional): http://localhost:8081

## Troubleshooting

```bash
# Container Status
docker-compose ps

# Logs prüfen
docker-compose logs apache
docker-compose logs php-fpm

# PHP-FPM Status (im Apache Container)
docker-compose exec apache curl http://localhost/fpm-status

# Container neu starten
docker-compose restart apache
docker-compose restart php-fpm

# Alle Container neu bauen
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

## Ohne Docker

Siehe vollständige Anleitung in [docs/APACHE_PHP_FPM_SETUP.md](./APACHE_PHP_FPM_SETUP.md)

---

Weitere Details und Konfiguration: [APACHE_PHP_FPM_SETUP.md](./APACHE_PHP_FPM_SETUP.md)
