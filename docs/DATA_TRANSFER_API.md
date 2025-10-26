# Data Transfer API - Dokumentation

## Übersicht

Die Data Transfer API ermöglicht die sichere Übertragung von Delta-Datenbanken, Bildern und Dokumenten zwischen verschiedenen Servern. Die API ist durch einen API-Key geschützt und wird über Umgebungsvariablen konfiguriert.

## Funktionen

- **Delta-Datenbank-Transfer**: Kopiert die Delta-Datenbank (`evo_delta.db`) von einem Server zum anderen
- **Bilder-Transfer**: Synchronisiert das Bilder-Verzeichnis zwischen Servern
- **Dokumente-Transfer**: Synchronisiert das Dokumente-Verzeichnis zwischen Servern
- **API-Key-Authentifizierung**: Sichere Authentifizierung über konfigurierbare API-Keys
- **Logging**: Optional strukturiertes Logging aller Transfers
- **Fehlerbehandlung**: Detaillierte Fehlerprotokolle bei fehlgeschlagenen Transfers

## Konfiguration

### Umgebungsvariablen (.env)

```bash
# API Key für Authentifizierung (ERFORDERLICH)
# Generieren Sie einen sicheren Key mit: openssl rand -hex 32
DATA_TRANSFER_API_KEY=your_secure_api_key_here

# Datenbank-Transfer-Konfiguration
DB_TRANSFER_SOURCE=/path/to/source/db/evo_delta.db
DB_TRANSFER_TARGET=/path/to/target/db/evo_delta.db

# Bilder-Transfer-Konfiguration
IMAGES_TRANSFER_SOURCE=/path/to/source/Files/Bilder
IMAGES_TRANSFER_TARGET=/path/to/target/Files/Bilder

# Dokumente-Transfer-Konfiguration
DOCUMENTS_TRANSFER_SOURCE=/path/to/source/Files/Dokumente
DOCUMENTS_TRANSFER_TARGET=/path/to/target/Files/Dokumente

# Transfer-Typen aktivieren/deaktivieren
DATA_TRANSFER_ENABLE_DB=true
DATA_TRANSFER_ENABLE_IMAGES=true
DATA_TRANSFER_ENABLE_DOCUMENTS=true

# Transfer-Optionen
DATA_TRANSFER_MAX_FILE_SIZE=104857600  # 100MB
DATA_TRANSFER_LOG_TRANSFERS=true
```

### Wichtige Hinweise zur Konfiguration

1. **API-Key Sicherheit**: 
   - Verwenden Sie einen starken, zufällig generierten Key
   - Geben Sie den Key niemals in Versionskontrolle ein
   - Ändern Sie den Key regelmäßig

2. **Pfade**:
   - Verwenden Sie absolute Pfade für Source und Target
   - Stellen Sie sicher, dass der Web-Server Lese-/Schreibrechte hat
   - Quellverzeichnisse müssen existieren, Zielverzeichnisse werden automatisch erstellt

3. **Dateigröße**:
   - Standardmäßig max. 100MB pro Datei
   - Größere Dateien werden übersprungen (mit Fehlerprotokoll)

## API-Endpunkt

### URL
```
POST /api/data_transfer.php
```

### Authentifizierung

Die API unterstützt zwei Methoden zur Übermittlung des API-Keys:

#### Methode 1: HTTP-Header (empfohlen)
```bash
curl -X POST \
  -H "X-API-Key: your_secure_api_key_here" \
  -d "transfer_type=all" \
  https://your-server.com/api/data_transfer.php
```

#### Methode 2: POST-Parameter
```bash
curl -X POST \
  -d "api_key=your_secure_api_key_here" \
  -d "transfer_type=all" \
  https://your-server.com/api/data_transfer.php
```

### Request-Parameter

| Parameter | Typ | Erforderlich | Beschreibung | Werte |
|-----------|-----|--------------|--------------|-------|
| `api_key` | string | Ja* | API-Key für Authentifizierung | Konfigurierter API-Key |
| `transfer_type` | string | Nein | Typ des Transfers | `database`, `images`, `documents`, `all` (Standard: `all`) |

*nur wenn nicht im Header übergeben

### Response

#### Erfolgreiche Response

```json
{
  "ok": true,
  "transfer_type": "all",
  "results": {
    "database": {
      "success": true,
      "source": "/path/to/source/db/evo_delta.db",
      "target": "/path/to/target/db/evo_delta.db",
      "size": 1048576,
      "duration": 0.123,
      "timestamp": "2025-10-26 14:30:45"
    },
    "images": {
      "success": true,
      "source": "/path/to/source/Files/Bilder",
      "target": "/path/to/target/Files/Bilder",
      "files_copied": 150,
      "directories_created": 5,
      "total_size": 52428800,
      "duration": 2.456,
      "timestamp": "2025-10-26 14:30:47"
    },
    "documents": {
      "success": true,
      "source": "/path/to/source/Files/Dokumente",
      "target": "/path/to/target/Files/Dokumente",
      "files_copied": 45,
      "directories_created": 2,
      "total_size": 10485760,
      "duration": 0.789,
      "timestamp": "2025-10-26 14:30:48"
    }
  },
  "total_duration": 3.368,
  "timestamp": "2025-10-26 14:30:48"
}
```

#### Fehler-Response

```json
{
  "ok": false,
  "error": "Ungültiger API-Key"
}
```

### HTTP-Statuscodes

| Code | Bedeutung |
|------|-----------|
| 200 | Erfolgreicher Transfer |
| 400 | Ungültige Request-Parameter |
| 401 | API-Key fehlt |
| 403 | Ungültiger API-Key |
| 405 | Ungültige HTTP-Methode (nur POST erlaubt) |
| 500 | Server- oder Konfigurationsfehler |

## Verwendungsbeispiele

### Transfer aller Datentypen

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=all" \
  https://server1.example.com/api/data_transfer.php
```

### Nur Datenbank transferieren

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=database" \
  https://server1.example.com/api/data_transfer.php
```

### Nur Bilder transferieren

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=images" \
  https://server1.example.com/api/data_transfer.php
```

### Mit PHP

```php
<?php
$apiKey = 'your_secure_api_key_here';
$url = 'https://server1.example.com/api/data_transfer.php';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['transfer_type' => 'all']);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-API-Key: {$apiKey}"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['ok']) {
    echo "Transfer erfolgreich!\n";
    print_r($result['results']);
} else {
    echo "Transfer fehlgeschlagen: " . $result['error'] . "\n";
}

curl_close($ch);
```

### Mit JavaScript/Fetch

```javascript
const apiKey = 'your_secure_api_key_here';
const url = 'https://server1.example.com/api/data_transfer.php';

fetch(url, {
  method: 'POST',
  headers: {
    'X-API-Key': apiKey,
    'Content-Type': 'application/x-www-form-urlencoded'
  },
  body: 'transfer_type=all'
})
.then(response => response.json())
.then(data => {
  if (data.ok) {
    console.log('Transfer erfolgreich!', data.results);
  } else {
    console.error('Transfer fehlgeschlagen:', data.error);
  }
})
.catch(error => console.error('Fehler:', error));
```

## Sicherheit

### Best Practices

1. **HTTPS verwenden**: Übertragen Sie API-Keys nur über verschlüsselte Verbindungen
2. **API-Key rotieren**: Ändern Sie den Key regelmäßig
3. **Zugriffsrechte**: Stellen Sie sicher, dass nur autorisierte Systeme Zugriff haben
4. **Logging überwachen**: Überprüfen Sie regelmäßig die Transfer-Logs
5. **Netzwerksicherheit**: Beschränken Sie den Zugriff per Firewall/IP-Whitelist wenn möglich

### Fehlerbehandlung

Die API liefert detaillierte Fehlermeldungen:

- **Konfigurationsfehler**: Fehlende oder ungültige Konfiguration
- **Dateifehler**: Quell-/Zieldateien nicht gefunden, Berechtigungsprobleme
- **Authentifizierungsfehler**: Ungültiger oder fehlender API-Key

Bei Fehlern werden keine sensiblen Informationen (Passwörter, interne Pfade) in der Response preisgegeben.

## Logging

Wenn `DATA_TRANSFER_LOG_TRANSFERS=true` gesetzt ist, werden alle Transfers im strukturierten JSON-Log-Format protokolliert:

```json
{
  "type": "data_transfer",
  "operation": "database",
  "success": true,
  "size": 1048576,
  "duration": 0.123,
  "timestamp": "2025-10-26 14:30:45"
}
```

Die Logs werden im Verzeichnis `logs/` gespeichert (tägliche Dateien im Format `YYYY-MM-DD.log`).

## Troubleshooting

### Problem: "API-Key fehlt"

**Lösung**: Stellen Sie sicher, dass der API-Key entweder im Header (`X-API-Key`) oder als POST-Parameter (`api_key`) übergeben wird.

### Problem: "Ungültiger API-Key"

**Lösung**: 
1. Prüfen Sie, ob `DATA_TRANSFER_API_KEY` in `.env` korrekt gesetzt ist
2. Vergleichen Sie den übergebenen Key mit dem konfigurierten Key
3. Achten Sie auf Leerzeichen oder Zeilenumbrüche im Key

### Problem: "Quell-Datenbank nicht gefunden"

**Lösung**:
1. Prüfen Sie, ob der Pfad in `DB_TRANSFER_SOURCE` korrekt ist
2. Stellen Sie sicher, dass die Datei existiert und lesbar ist
3. Verwenden Sie absolute Pfade

### Problem: "Verzeichnis konnte nicht erstellt werden"

**Lösung**:
1. Prüfen Sie die Schreibrechte für den Webserver-Benutzer
2. Erstellen Sie das übergeordnete Verzeichnis manuell
3. Prüfen Sie SELinux/AppArmor-Einstellungen wenn vorhanden

### Problem: "Datei zu groß"

**Lösung**: Erhöhen Sie `DATA_TRANSFER_MAX_FILE_SIZE` in der `.env`-Datei.

## Integration in bestehende Workflows

Die Data Transfer API kann in verschiedene Szenarien integriert werden:

### Cron-Job für regelmäßige Synchronisation

```bash
#!/bin/bash
# sync-data.sh - Tägliche Synchronisation

API_KEY="your_secure_api_key_here"
API_URL="https://server1.example.com/api/data_transfer.php"

curl -X POST \
  -H "X-API-Key: ${API_KEY}" \
  -d "transfer_type=all" \
  "${API_URL}" \
  -o /var/log/data-transfer-$(date +%Y%m%d).log
```

Crontab:
```
0 2 * * * /path/to/sync-data.sh
```

### Nach Sync-Abschluss triggern

Erweitern Sie die bestehende Sync-Logik, um nach erfolgreichem Sync automatisch einen Transfer zu triggern.

### Multi-Server-Setup

Konfigurieren Sie mehrere Server mit unterschiedlichen Source/Target-Pfaden für komplexe Replikations-Szenarien.

## API-Klasse

Die Hauptlogik ist in der Klasse `API_Transfer` implementiert:

```php
// Grundlegende Verwendung
$transfer = new API_Transfer($config, $logger);

// API-Key validieren
if (!$transfer->validateApiKey($apiKey)) {
    die('Ungültiger API-Key');
}

// Datenbank transferieren
$result = $transfer->transferDatabase();

// Bilder transferieren
$result = $transfer->transferImages();

// Dokumente transferieren
$result = $transfer->transferDocuments();

// Alles transferieren
$results = $transfer->transferAll();
```

Die Klasse befindet sich in `classes/file/API_Transfer.php`.
