# Remote Setup und Automatische Updates

## Übersicht

Dieses Feature ermöglicht die Ferninstallation und automatische Aktualisierung der AFS-MappingXT Schnittstelle. Die Funktionalität umfasst:

1. **Initiale Ferneinrichtung**: Erstellen der `.env`-Datei ohne API-Key-Authentifizierung
2. **Automatische Git-Updates**: Updates werden vor jedem API-Aufruf überprüft und durchgeführt
3. **Update-Benachrichtigung**: Der Hauptserver wird über durchgeführte Updates informiert

## Komponenten

### 1. Initial Setup API (`api/initial_setup.php`)

#### Zweck
Ermöglicht die erstmalige Konfiguration der Schnittstelle ohne vorhandenen API-Key.

#### Authentifizierung
- **Keine Authentifizierung erforderlich**, wenn `.env` noch nicht existiert
- **API-Key erforderlich** (`X-API-Key` Header), wenn `.env` bereits existiert

#### Endpoints

##### GET `/api/initial_setup.php`
Prüft, ob eine initiale Einrichtung erforderlich ist.

**Response:**
```json
{
  "ok": true,
  "setup_needed": true,
  "env_exists": false,
  "env_writable": true
}
```

##### POST `/api/initial_setup.php`
Erstellt oder aktualisiert die `.env`-Datei.

**Request (JSON):**
```json
{
  "settings": {
    "DATA_TRANSFER_API_KEY": "your_secure_api_key_here",
    "AFS_MSSQL_HOST": "10.0.1.82",
    "AFS_MSSQL_PORT": "1435",
    "AFS_GITHUB_AUTO_UPDATE": "true"
  }
}
```

**Required Headers (nur wenn .env existiert):**
```
X-API-Key: your_existing_api_key
Content-Type: application/json
```

**Response:**
```json
{
  "ok": true,
  "message": "Initiale Konfiguration erfolgreich erstellt",
  "created": true,
  "updated_count": 4
}
```

#### Verwendungsbeispiel

**Erstmalige Einrichtung (keine Authentifizierung erforderlich):**
```bash
curl -X POST https://remote-server.example.com/api/initial_setup.php \
  -H "Content-Type: application/json" \
  -d '{
    "settings": {
      "DATA_TRANSFER_API_KEY": "generated_secure_key_123456789",
      "AFS_MSSQL_HOST": "10.0.1.82",
      "AFS_MSSQL_PORT": "1435",
      "AFS_MSSQL_DB": "AFS_2018",
      "AFS_MSSQL_USER": "sa",
      "AFS_MSSQL_PASS": "your_password",
      "AFS_GITHUB_AUTO_UPDATE": "true",
      "AFS_GITHUB_BRANCH": "main",
      "REMOTE_SERVERS": "MainServer|https://main-server.example.com|main_server_api_key"
    }
  }'
```

**Update bestehender Konfiguration (Authentifizierung erforderlich):**
```bash
curl -X POST https://remote-server.example.com/api/initial_setup.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: existing_api_key" \
  -d '{
    "settings": {
      "AFS_GITHUB_AUTO_UPDATE": "false"
    }
  }'
```

### 2. Automatische Update-Prüfung

#### Funktionsweise
Die automatische Update-Prüfung ist in `api/_bootstrap.php` implementiert und wird vor **jedem** API-Aufruf ausgeführt (außer `initial_setup.php`, `update_notification.php` und `github_update.php`).

#### Ablauf
1. **Prüfung**: Vor jedem API-Call wird geprüft, ob `AFS_GITHUB_AUTO_UPDATE=true` gesetzt ist
2. **Update**: Falls Updates verfügbar sind, wird `git pull` ausgeführt
3. **Benachrichtigung**: Bei erfolgreicher Aktualisierung wird der Hauptserver benachrichtigt
4. **Fortsetzung**: Erst danach wird der eigentliche API-Call verarbeitet

#### Konfiguration

**.env Einstellungen:**
```bash
# Automatische Updates aktivieren
AFS_GITHUB_AUTO_UPDATE=true

# Branch für Updates (leer = aktueller Branch)
AFS_GITHUB_BRANCH=main

# Hauptserver für Benachrichtigungen (Format: Name|URL|API_Key|Database)
REMOTE_SERVERS=MainServer|https://main-server.example.com|main_api_key|evo.db
```

#### Ausgeschlossene Endpoints
Diese Endpoints führen KEINE automatische Update-Prüfung durch:
- `initial_setup.php` (könnte während Setup Probleme verursachen)
- `update_notification.php` (vermeidet Rekursion)
- `github_update.php` (manuelle Update-Kontrolle)

#### Update-Information in API-Responses
Alle API-Responses enthalten Informationen über durchgeführte Updates:

```json
{
  "ok": true,
  "data": { /* normale Response-Daten */ },
  "github_update": {
    "checked": true,
    "updated": true,
    "info": {
      "available": true,
      "current_commit": "abc1234",
      "remote_commit": "def5678",
      "commits_behind": 3,
      "branch": "main"
    },
    "notification": {
      "success": true,
      "message": "Hauptserver erfolgreich benachrichtigt"
    }
  }
}
```

### 3. Update-Benachrichtigung (`AFS_UpdateNotifier`)

#### Zweck
Informiert den Hauptserver über durchgeführte Updates, damit dieser den Status aller Remote-Server überwachen kann.

#### Funktionsweise
1. Nach erfolgreichem Git-Update wird `AFS_UpdateNotifier` aufgerufen
2. Die Klasse liest die Hauptserver-URL aus `REMOTE_SERVERS` (erster Eintrag)
3. Sendet HTTP POST an `https://main-server.example.com/api/update_notification.php`
4. Überträgt Update-Informationen (Commits, Branch, Timestamp)

#### Benachrichtigungs-Payload
```json
{
  "event": "interface_updated",
  "timestamp": "2025-10-26 18:30:45",
  "update_info": {
    "available": true,
    "current_commit": "abc1234",
    "remote_commit": "def5678",
    "commits_behind": 3,
    "branch": "main"
  },
  "server_info": {
    "hostname": "remote-server-01",
    "php_version": "8.2.0"
  }
}
```

#### Fehlerbehandlung
- Benachrichtigungs-Fehler führen NICHT zum Abbruch des API-Calls
- Fehler werden geloggt und in der Response dokumentiert
- Der eigentliche API-Call wird normal fortgesetzt

### 4. Update-Benachrichtigungs-Endpoint (`api/update_notification.php`)

#### Zweck
Empfängt Update-Benachrichtigungen von Remote-Servern auf dem Hauptserver.

#### Authentifizierung
- **Erforderlich**: `X-API-Key` Header mit dem konfigurierten `DATA_TRANSFER_API_KEY`

#### Request
```bash
curl -X POST https://main-server.example.com/api/update_notification.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: main_server_api_key" \
  -d '{
    "event": "interface_updated",
    "timestamp": "2025-10-26 18:30:45",
    "update_info": {
      "branch": "main",
      "commits_behind": 3,
      "current_commit": "abc1234",
      "remote_commit": "def5678"
    },
    "server_info": {
      "hostname": "remote-server-01",
      "php_version": "8.2.0"
    }
  }'
```

#### Response
```json
{
  "ok": true,
  "message": "Benachrichtigung empfangen und protokolliert",
  "event": "interface_updated",
  "received_at": "2025-10-26 18:30:46"
}
```

#### Speicherung
Benachrichtigungen werden in `db/status.db` in der Tabelle `remote_updates` gespeichert:

```sql
CREATE TABLE remote_updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event TEXT NOT NULL,
    timestamp TEXT NOT NULL,
    server_hostname TEXT,
    update_info TEXT,
    remote_ip TEXT,
    received_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

## Installation und Einrichtung

### Szenario 1: Neue Remote-Installation

1. **Repository klonen:**
```bash
git clone https://github.com/your-org/AFS-MappingXT.git
cd AFS-MappingXT
```

2. **Initiale Konfiguration via API:**
```bash
# API-Key generieren
API_KEY=$(openssl rand -hex 32)

# Initiale Konfiguration erstellen
curl -X POST http://localhost:8080/api/initial_setup.php \
  -H "Content-Type: application/json" \
  -d "{
    \"settings\": {
      \"DATA_TRANSFER_API_KEY\": \"$API_KEY\",
      \"AFS_MSSQL_HOST\": \"10.0.1.82\",
      \"AFS_MSSQL_PORT\": \"1435\",
      \"AFS_MSSQL_DB\": \"AFS_2018\",
      \"AFS_MSSQL_USER\": \"sa\",
      \"AFS_MSSQL_PASS\": \"your_password\",
      \"AFS_GITHUB_AUTO_UPDATE\": \"true\",
      \"REMOTE_SERVERS\": \"MainServer|https://main.example.com|main_api_key|evo.db\"
    }
  }"
```

3. **Datenbanken initialisieren:**
```bash
php scripts/setup.php
```

4. **Fertig!** Die Schnittstelle ist jetzt betriebsbereit und aktualisiert sich automatisch.

### Szenario 2: Hauptserver-Konfiguration

Auf dem Hauptserver muss `REMOTE_SERVERS` NICHT konfiguriert werden, da er keine Benachrichtigungen sendet, sondern nur empfängt.

**.env auf dem Hauptserver:**
```bash
# Hauptserver benötigt keine REMOTE_SERVERS-Konfiguration
AFS_GITHUB_AUTO_UPDATE=true
DATA_TRANSFER_API_KEY=main_server_api_key_here
```

### Szenario 3: Remote-Server-Konfiguration

Remote-Server müssen den Hauptserver in `REMOTE_SERVERS` konfigurieren:

**.env auf Remote-Servern:**
```bash
# Auto-Update aktivieren
AFS_GITHUB_AUTO_UPDATE=true

# Hauptserver konfigurieren (erster Eintrag ist der Hauptserver)
REMOTE_SERVERS=MainServer|https://main-server.example.com|main_server_api_key|evo.db

# Eigener API-Key
DATA_TRANSFER_API_KEY=remote_server_api_key_here
```

## Sicherheitsüberlegungen

### 1. API-Key-Schutz
- Initial Setup erlaubt EINMALIG die Erstellung ohne API-Key
- Nach Erstellung ist API-Key **immer** erforderlich
- Verwenden Sie starke, zufällig generierte API-Keys

### 2. HTTPS-Verbindungen
- Verwenden Sie HTTPS für alle API-Calls in Produktion
- Initial Setup sollte über VPN oder sichere Netzwerke erfolgen

### 3. Git-Repository-Sicherheit
- Stellen Sie sicher, dass `.env` in `.gitignore` steht
- Verwenden Sie SSH-Keys oder sichere HTTPS-Credentials für Git

### 4. Update-Benachrichtigungen
- Benachrichtigungen erfordern gültigen API-Key
- Fehlgeschlagene Benachrichtigungen brechen den API-Call nicht ab

## Troubleshooting

### Problem: Initial Setup schlägt fehl

**Lösung:**
```bash
# Prüfen, ob .env.example existiert
ls -la .env.example

# Prüfen, ob Verzeichnis beschreibbar ist
ls -ld /path/to/AFS-MappingXT

# Logs prüfen
tail -f logs/$(date +%Y-%m-%d).log
```

### Problem: Auto-Update funktioniert nicht

**Lösung:**
```bash
# Prüfen, ob Git verfügbar ist
git --version

# Prüfen, ob lokale Änderungen vorhanden sind
git status

# Manuelles Update testen
php indexcli.php update

# Logs prüfen
tail -f logs/$(date +%Y-%m-%d).log | grep -i update
```

### Problem: Update-Benachrichtigung schlägt fehl

**Lösung:**
```bash
# Prüfen, ob Hauptserver erreichbar ist
curl -I https://main-server.example.com/api/update_notification.php

# API-Key überprüfen
# In .env auf Remote-Server und Hauptserver vergleichen

# Logs auf beiden Servern prüfen
# Remote-Server:
tail -f logs/$(date +%Y-%m-%d).log | grep -i notification

# Hauptserver:
tail -f logs/$(date +%Y-%m-%d).log | grep -i remote_update
```

### Problem: .env existiert, aber Initial Setup verweigert Zugriff

**Lösung:**
Verwenden Sie den korrekten API-Key im Header:
```bash
curl -X POST http://localhost:8080/api/initial_setup.php \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your_existing_api_key_from_env" \
  -d '{"settings": {"AFS_GITHUB_AUTO_UPDATE": "false"}}'
```

## Best Practices

1. **API-Key-Generierung**: Verwenden Sie `openssl rand -hex 32` für starke Keys
2. **Regelmäßige Updates**: Aktivieren Sie `AFS_GITHUB_AUTO_UPDATE=true` für automatische Sicherheitsupdates
3. **Monitoring**: Überwachen Sie die `remote_updates`-Tabelle auf dem Hauptserver
4. **Backup**: Erstellen Sie Backups vor manuellen Konfigurationsänderungen
5. **Logs**: Überprüfen Sie regelmäßig die JSON-Logs auf Update-Fehler
6. **Testing**: Testen Sie Updates zunächst auf einem Staging-Server

## Integration in bestehende Workflows

### CI/CD-Pipeline
```yaml
# .github/workflows/deploy.yml
name: Deploy to Remote Servers

on:
  push:
    branches: [main]

jobs:
  notify-servers:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger update on remote servers
        run: |
          # Remote-Server aktualisieren sich automatisch beim nächsten API-Call
          # Alternativ: Manueller Trigger via API
          curl -X POST https://remote1.example.com/api/sync_start.php \
            -H "X-API-Key: ${{ secrets.REMOTE_API_KEY }}"
```

### Monitoring-Script
```php
<?php
// monitor_updates.php - Überwacht Update-Status aller Remote-Server

require_once __DIR__ . '/config.php';

$pdo = new PDO('sqlite:' . $config['paths']['status_db']);
$stmt = $pdo->query('
    SELECT server_hostname, timestamp, update_info, received_at 
    FROM remote_updates 
    ORDER BY received_at DESC 
    LIMIT 10
');

foreach ($stmt as $row) {
    echo sprintf(
        "[%s] Server %s updated at %s (received: %s)\n",
        date('Y-m-d H:i:s'),
        $row['server_hostname'],
        $row['timestamp'],
        $row['received_at']
    );
}
```

## Weitere Ressourcen

- [GitHub Auto-Update Dokumentation](GITHUB_AUTO_UPDATE.md)
- [Data Transfer API Dokumentation](DATA_TRANSFER_API.md)
- [Remote Server Monitoring](REMOTE_SERVER_MONITORING.md)
- [Sicherheitsrichtlinien](SECURITY.md)
