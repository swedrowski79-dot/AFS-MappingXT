# Remote Server Monitoring - Dokumentation

## Übersicht

Das Remote Server Monitoring Feature ermöglicht es, den Synchronisationsstatus von mehreren Servern (z.B. Slave-Servern) zentral in der Web-Oberfläche zu überwachen. Dies ist besonders nützlich, wenn die Schnittstelle auf mehreren Servern installiert ist und ein zentrales Monitoring gewünscht wird.

## Funktionen

- **Zentrale Überwachung**: Zeigt den Status aller konfigurierten Remote-Server in der Web-Oberfläche an
- **Echtzeit-Updates**: Automatische Aktualisierung des Status alle 10 Sekunden
- **Flexible Konfiguration**: Einfache Konfiguration über Umgebungsvariablen
- **Sichere Kommunikation**: Unterstützt HTTPS und optionale API-Key-Authentifizierung
- **Fehlerbehandlung**: Zeigt Verbindungsfehler und Timeout-Probleme übersichtlich an
- **Detaillierte Statusinformationen**: 
  - Aktueller Status (ready, running, error, etc.)
  - Fortschritt (Anzahl verarbeiteter Datensätze)
  - Statusmeldungen
  - Laufzeit

## Konfiguration

### 1. Umgebungsvariablen einrichten

Bearbeiten Sie die `.env`-Datei:

```bash
# Remote Server Monitoring aktivieren
REMOTE_SERVERS_ENABLED=true

# Remote-Server konfigurieren (komma-getrennt)
# Format: Name|URL|API_Key (API_Key ist optional)
REMOTE_SERVERS=Produktion|https://prod.example.com,Test|https://test.example.com|abc123

# Timeout für Remote-Anfragen (in Sekunden)
REMOTE_SERVER_TIMEOUT=5
```

#### Format der REMOTE_SERVERS Variable

Jeder Server wird in folgendem Format angegeben:
```
ServerName|ServerURL|OptionalerAPIKey
```

Mehrere Server werden durch Kommas getrennt:
```
Server1|https://server1.com|key1,Server2|https://server2.com|key2,Server3|https://server3.com
```

**Hinweise:**
- Der Server-Name ist frei wählbar und wird in der UI angezeigt
- Die URL muss die Basis-URL des Servers sein (z.B. `https://server.example.com`)
- Der API-Key ist optional und nur erforderlich, wenn der Remote-Server eine Authentifizierung verlangt
- Verwenden Sie HTTPS für Produktionsumgebungen
- Entfernen Sie den abschließenden Schrägstrich von URLs

### 2. Beispielkonfigurationen

#### Mehrere Server ohne API-Key
```bash
REMOTE_SERVERS_ENABLED=true
REMOTE_SERVERS=Hauptserver|https://main.example.com,Backup-Server|https://backup.example.com
```

#### Mit API-Key-Authentifizierung
```bash
REMOTE_SERVERS_ENABLED=true
REMOTE_SERVERS=Prod|https://prod.example.com|sk_prod_abc123,Stage|https://stage.example.com|sk_stage_xyz789
```

#### Einzelner Server
```bash
REMOTE_SERVERS_ENABLED=true
REMOTE_SERVERS=Slave-Server|https://slave.example.com
```

## API-Endpunkt

### Endpunkt: `/api/remote_status.php`

Dieser neue Endpunkt fragt die konfigurierten Remote-Server ab und gibt deren Status zurück.

#### Request

```
GET /api/remote_status.php
```

Keine zusätzlichen Parameter erforderlich.

#### Response

```json
{
  "ok": true,
  "enabled": true,
  "servers": [
    {
      "name": "Produktion",
      "url": "https://prod.example.com",
      "status": "ok",
      "data": {
        "state": "ready",
        "stage": null,
        "message": "System bereit",
        "total": 1000,
        "processed": 1000,
        "duration": 45.5,
        "started_at": "2025-10-26 10:00:00",
        "updated_at": "2025-10-26 10:00:45"
      }
    },
    {
      "name": "Test",
      "url": "https://test.example.com",
      "status": "error",
      "error": "Verbindungsfehler"
    }
  ],
  "timestamp": "2025-10-26 10:30:00"
}
```

#### Status-Codes

- **ok**: Server ist erreichbar und hat einen gültigen Status zurückgegeben
- **error**: Server ist nicht erreichbar oder hat einen Fehler zurückgegeben

#### Fehlerbehandlung

Bei Fehlern wird folgendes zurückgegeben:

```json
{
  "ok": false,
  "error": "Fehlermeldung"
}
```

Mögliche Fehler:
- Verbindungsfehler (Timeout, DNS-Fehler, etc.)
- HTTP-Fehler (404, 500, etc.)
- Ungültige JSON-Antwort
- Remote-Server meldet Fehler

## Web-Oberfläche

### Remote Server Status Sektion

Wenn Remote Server Monitoring aktiviert ist, erscheint eine neue Sektion "Remote Server Status" in der Web-Oberfläche.

#### Anzeige-Elemente

Für jeden konfigurierten Server wird angezeigt:

1. **Server-Name**: Der in der Konfiguration angegebene Name
2. **Server-URL**: Die vollständige URL des Servers
3. **Status-Indikator**: 
   - Grün (OK): Server läuft normal oder ist bereit
   - Orange (Warning): Server führt gerade eine Synchronisation aus
   - Rot (Error): Server hat einen Fehler oder ist nicht erreichbar
4. **Status-Text**: Aktueller Status (z.B. "Bereit", "Läuft...", "Fehler")
5. **Zusatzinformationen** (wenn verfügbar):
   - Aktuelle Statusmeldung
   - Fortschritt (z.B. "850/1000 (85%)")

#### Automatische Aktualisierung

Die Remote Server Status-Sektion aktualisiert sich automatisch alle 10 Sekunden, um den aktuellen Status der Server anzuzeigen.

## Technische Details

### Architektur

```
┌─────────────────┐
│  Web Browser    │
│  (index.php)    │
└────────┬────────┘
         │ JavaScript fetch()
         │ alle 10 Sekunden
         ▼
┌─────────────────┐
│ api/            │
│ remote_status   │
│     .php        │
└────────┬────────┘
         │ cURL Requests
         │ (parallel)
         ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Remote Server  │     │  Remote Server  │     │  Remote Server  │
│       #1        │     │       #2        │     │       #3        │
│                 │     │                 │     │                 │
│ api/sync_status │     │ api/sync_status │     │ api/sync_status │
│     .php        │     │     .php        │     │     .php        │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Sicherheit

1. **HTTPS**: Verwenden Sie immer HTTPS für Produktionsumgebungen
2. **SSL-Verifizierung**: Der API-Endpunkt verifiziert SSL-Zertifikate
3. **API-Key-Unterstützung**: Optional kann ein API-Key für jeden Remote-Server konfiguriert werden
4. **Timeout-Schutz**: Anfragen werden nach der konfigurierten Zeit abgebrochen
5. **Fehlerbehandlung**: Sensible Informationen werden bei Fehlern nicht preisgegeben

### Performance

- **Parallele Anfragen**: Alle Remote-Server werden gleichzeitig abgefragt (nicht sequenziell)
- **Caching**: Die JavaScript-Implementierung nutzt Browser-Caching
- **Timeout**: Anfragen werden nach 5 Sekunden (konfigurierbar) abgebrochen
- **Polling-Intervall**: 10 Sekunden (optimiert für Balance zwischen Aktualität und Server-Last)

## Troubleshooting

### Problem: Remote Server Status wird nicht angezeigt

**Lösung:**
1. Prüfen Sie, ob `REMOTE_SERVERS_ENABLED=true` in der `.env`-Datei gesetzt ist
2. Prüfen Sie, ob mindestens ein Server in `REMOTE_SERVERS` konfiguriert ist
3. Löschen Sie den Browser-Cache und laden Sie die Seite neu

### Problem: Server zeigt "Verbindungsfehler"

**Lösung:**
1. Prüfen Sie, ob die Server-URL korrekt ist
2. Stellen Sie sicher, dass der Remote-Server erreichbar ist (Ping/Firewall)
3. Prüfen Sie, ob der Remote-Server HTTPS verwendet und ein gültiges Zertifikat hat
4. Erhöhen Sie `REMOTE_SERVER_TIMEOUT`, wenn der Server langsam antwortet

### Problem: Server zeigt "HTTP 403" oder "HTTP 401"

**Lösung:**
1. Der Remote-Server verlangt möglicherweise einen API-Key
2. Fügen Sie den API-Key zur Konfiguration hinzu: `Server|URL|API_KEY`
3. Prüfen Sie, ob der API-Key korrekt ist

### Problem: Server zeigt immer "unknown" Status

**Lösung:**
1. Prüfen Sie, ob der Remote-Server das AFS-MappingXT System mit dem `/api/sync_status.php` Endpunkt hat
2. Prüfen Sie die Browser-Konsole auf JavaScript-Fehler
3. Testen Sie den Remote-Server direkt: `curl https://server.example.com/api/sync_status.php`

## Best Practices

1. **Naming**: Verwenden Sie aussagekräftige Server-Namen (z.B. "Produktion", "Test", "Backup")
2. **URLs**: Verwenden Sie vollständige URLs mit Protokoll (https://)
3. **API-Keys**: Verwenden Sie starke API-Keys für Produktionsumgebungen
4. **Monitoring**: Überwachen Sie die Logs auf wiederholte Verbindungsfehler
5. **Timeout**: Setzen Sie den Timeout auf einen angemessenen Wert (5-10 Sekunden)
6. **Anzahl Server**: Begrenzen Sie die Anzahl überwachter Server auf 5-10 für optimale Performance

## Integration in bestehende Workflows

### Verwendung mit Docker

```yaml
# docker-compose.yml
environment:
  - REMOTE_SERVERS_ENABLED=true
  - REMOTE_SERVERS=Prod|https://prod.example.com,Test|https://test.example.com
  - REMOTE_SERVER_TIMEOUT=5
```

### Verwendung mit Monitoring-Tools

Der `/api/remote_status.php` Endpunkt kann auch von externen Monitoring-Tools abgefragt werden:

```bash
# Beispiel: Nagios/Icinga Check
curl -s https://monitor.example.com/api/remote_status.php | jq '.servers[] | select(.status == "error")'
```

### Verwendung in Skripten

```php
<?php
// Beispiel: Status-Abfrage in PHP
$response = file_get_contents('https://monitor.example.com/api/remote_status.php');
$data = json_decode($response, true);

foreach ($data['servers'] as $server) {
    if ($server['status'] === 'error') {
        // Alert senden
        mail('admin@example.com', 'Server Down', "Server {$server['name']} ist nicht erreichbar");
    }
}
```

## Verwandte Dokumentation

- [DATA_TRANSFER_API.md](DATA_TRANSFER_API.md) - Server-to-Server Data Transfer API
- [MULTI_DATABASE_SYNC.md](MULTI_DATABASE_SYNC.md) - Multi-Database Sync Konfiguration
- [CONFIGURATION_MANAGEMENT.md](CONFIGURATION_MANAGEMENT.md) - Allgemeine Konfiguration

## Changelog

### Version 1.0.0 (2025-10-26)

- Initiales Release
- Remote Server Monitoring Feature
- Web-UI Integration
- API Endpunkt `/api/remote_status.php`
- Konfiguration über Umgebungsvariablen
- Automatisches Polling alle 10 Sekunden
