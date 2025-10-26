# Data Transfer API - Dokumentation

## Übersicht

Die Data Transfer API ermöglicht die sichere Übertragung von Delta-Datenbanken, Bildern und Dokumenten zwischen verschiedenen Servern. Die API ist durch einen API-Key geschützt und wird über Umgebungsvariablen konfiguriert.

## Funktionen

- **Delta-Datenbank-Transfer**: Kopiert die Delta-Datenbank (`evo_delta.db`) von einem Server zum anderen
- **Bilder-Transfer**: Synchronisiert das Bilder-Verzeichnis zwischen Servern
- **Dokumente-Transfer**: Synchronisiert das Dokumente-Verzeichnis zwischen Servern
- **Upload-Tracking**: Verfolgt den Upload-Status jedes Bildes und Dokuments mit dem `uploaded`-Feld
- **Einzelfile-Upload**: Überträgt einzelne Bilder oder Dokumente basierend auf ihrer ID
- **Ausstehende Dateien**: Listet und überträgt nur Dateien mit `uploaded = 0`
- **API-Key-Authentifizierung**: Sichere Authentifizierung über konfigurierbare API-Keys
- **Logging**: Optional strukturiertes Logging aller Transfers
- **Fehlerbehandlung**: Detaillierte Fehlerprotokolle bei fehlgeschlagenen Transfers

## Upload-Tracking Feature

Das Upload-Tracking-Feature ermöglicht es, den Übertragungsstatus jedes einzelnen Bildes und Dokuments zu verfolgen:

### Funktionsweise

1. **Initialer Status**: Wenn ein neues Bild oder Dokument in die Datenbank importiert wird, wird das `uploaded`-Feld automatisch auf `0` gesetzt und das `update`-Feld auf `1`.

2. **Transfer**: Bei der Übertragung mit den `pending_*` oder `single_*` Transfer-Typen werden nur Dateien mit `uploaded = 0` übertragen.

3. **Markierung**: Nach erfolgreicher Übertragung wird das `uploaded`-Feld automatisch auf `1` gesetzt.

4. **Erneute Übertragung**: Wenn sich eine Datei ändert (neue md5-Prüfsumme), werden sowohl `update` als auch `uploaded` automatisch auf `0` zurückgesetzt, sodass die Datei erneut übertragen wird.

### Vorteile

- **Inkrementelle Übertragung**: Nur neue oder geänderte Dateien werden übertragen
- **Einzelfile-Übertragung**: Große Bildsammlungen können Datei für Datei übertragen werden
- **Wiederaufnahme**: Unterbrochene Übertragungen können fortgesetzt werden
- **Übersicht**: Jederzeit einsehbar, welche Dateien noch übertragen werden müssen
- **Effizienz**: Reduziert Bandbreite und Übertragungszeit

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
| `transfer_type` | string | Nein | Typ des Transfers | `database`, `images`, `documents`, `all`, `pending_images`, `pending_documents`, `pending_all`, `single_image`, `single_document`, `list_pending_images`, `list_pending_documents` (Standard: `all`) |
| `image_id` | int | Ja** | ID des zu übertragenden Bildes | Positive Ganzzahl |
| `document_id` | int | Ja** | ID des zu übertragenden Dokuments | Positive Ganzzahl |

*nur wenn nicht im Header übergeben  
**nur erforderlich für `single_image` bzw. `single_document`

### Transfer-Typen

#### Klassische Transfer-Typen
- `database`: Überträgt die Delta-Datenbank
- `images`: Überträgt alle Bilder im Verzeichnis
- `documents`: Überträgt alle Dokumente im Verzeichnis
- `all`: Überträgt Datenbank, Bilder und Dokumente

#### Neue Upload-Tracking Transfer-Typen
- `pending_images`: Überträgt nur Bilder mit `uploaded = 0` und markiert sie als `uploaded = 1`
- `pending_documents`: Überträgt nur Dokumente mit `uploaded = 0` und markiert sie als `uploaded = 1`
- `pending_all`: Überträgt alle ausstehenden Bilder und Dokumente
- `single_image`: Überträgt ein einzelnes Bild anhand der ID und markiert es als `uploaded = 1`
- `single_document`: Überträgt ein einzelnes Dokument anhand der ID und markiert es als `uploaded = 1`
- `list_pending_images`: Listet alle Bilder mit `uploaded = 0` (ohne Transfer)
- `list_pending_documents`: Listet alle Dokumente mit `uploaded = 0` (ohne Transfer)

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

### Nur ausstehende Bilder transferieren (uploaded = 0)

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=pending_images" \
  https://server1.example.com/api/data_transfer.php
```

**Response:**
```json
{
  "ok": true,
  "transfer_type": "pending_images",
  "results": {
    "pending_images": {
      "success": true,
      "total": 25,
      "transferred": 25,
      "failed": 0,
      "skipped": 0,
      "files": ["image1.jpg", "image2.jpg", "..."],
      "errors": [],
      "duration": 1.234,
      "timestamp": "2025-10-26 14:30:45"
    }
  },
  "total_duration": 1.234,
  "timestamp": "2025-10-26 14:30:45"
}
```

### Einzelnes Bild übertragen

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=single_image" \
  -d "image_id=123" \
  https://server1.example.com/api/data_transfer.php
```

**Response:**
```json
{
  "ok": true,
  "transfer_type": "single_image",
  "results": {
    "single_image": {
      "success": true,
      "image_id": 123,
      "filename": "product_123.jpg",
      "size": 245760,
      "duration": 0.034,
      "timestamp": "2025-10-26 14:30:45"
    }
  },
  "total_duration": 0.034,
  "timestamp": "2025-10-26 14:30:45"
}
```

### Ausstehende Bilder auflisten

```bash
curl -X POST \
  -H "X-API-Key: abc123def456..." \
  -d "transfer_type=list_pending_images" \
  https://server1.example.com/api/data_transfer.php
```

**Response:**
```json
{
  "ok": true,
  "transfer_type": "list_pending_images",
  "results": {
    "pending_images": [
      {
        "id": 123,
        "filename": "product_123.jpg",
        "md5": "abc123def456..."
      },
      {
        "id": 124,
        "filename": "product_124.jpg",
        "md5": "def456abc789..."
      }
    ]
  },
  "total_duration": 0.001,
  "timestamp": "2025-10-26 14:30:45"
}
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
$transfer = new API_Transfer($config, $logger, $db);

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

// Ausstehende Bilder auflisten
$pendingImages = $transfer->getPendingImages();

// Ausstehende Dokumente auflisten
$pendingDocuments = $transfer->getPendingDocuments();

// Einzelnes Bild übertragen
$result = $transfer->transferSingleImage($imageId);

// Einzelnes Dokument übertragen
$result = $transfer->transferSingleDocument($documentId);

// Alle ausstehenden Bilder übertragen
$result = $transfer->transferPendingImages();

// Alle ausstehenden Dokumente übertragen
$result = $transfer->transferPendingDocuments();

// Bild als hochgeladen markieren
$success = $transfer->markImageAsUploaded($imageId);

// Dokument als hochgeladen markieren
$success = $transfer->markDocumentAsUploaded($documentId);
```

Die Klasse befindet sich in `classes/file/API_Transfer.php`.

### Neue Methoden für Upload-Tracking

#### getPendingImages(): array
Gibt alle Bilder zurück, die noch nicht hochgeladen wurden (`uploaded = 0`).

**Rückgabe:**
```php
[
    [
        'id' => 123,
        'filename' => 'product_123.jpg',
        'md5' => 'abc123...'
    ],
    // ...
]
```

#### getPendingDocuments(): array
Gibt alle Dokumente zurück, die noch nicht hochgeladen wurden (`uploaded = 0`).

**Rückgabe:**
```php
[
    [
        'id' => 456,
        'title' => 'Produktdatenblatt',
        'filename' => 'produktdatenblatt.pdf',
        'md5' => 'def456...'
    ],
    // ...
]
```

#### transferSingleImage(int $imageId): array
Überträgt ein einzelnes Bild und markiert es als hochgeladen.

**Parameter:**
- `$imageId`: Die ID des Bildes in der Datenbank

**Rückgabe:**
```php
[
    'success' => true,
    'image_id' => 123,
    'filename' => 'product_123.jpg',
    'size' => 245760,
    'duration' => 0.034,
    'timestamp' => '2025-10-26 14:30:45'
]
```

#### transferSingleDocument(int $documentId): array
Überträgt ein einzelnes Dokument und markiert es als hochgeladen.

**Parameter:**
- `$documentId`: Die ID des Dokuments in der Datenbank

**Rückgabe:**
```php
[
    'success' => true,
    'document_id' => 456,
    'title' => 'Produktdatenblatt',
    'filename' => 'produktdatenblatt.pdf',
    'size' => 512000,
    'duration' => 0.056,
    'timestamp' => '2025-10-26 14:30:45'
]
```

#### transferPendingImages(): array
Überträgt alle ausstehenden Bilder (`uploaded = 0`) und markiert sie als hochgeladen.

**Rückgabe:**
```php
[
    'success' => true,
    'total' => 25,
    'transferred' => 25,
    'failed' => 0,
    'skipped' => 0,
    'files' => ['image1.jpg', 'image2.jpg', ...],
    'errors' => [],
    'duration' => 1.234,
    'timestamp' => '2025-10-26 14:30:45'
]
```

#### transferPendingDocuments(): array
Überträgt alle ausstehenden Dokumente (`uploaded = 0`) und markiert sie als hochgeladen.

**Rückgabe:**
```php
[
    'success' => true,
    'total' => 10,
    'transferred' => 10,
    'failed' => 0,
    'skipped' => 0,
    'files' => ['doc1.pdf', 'doc2.pdf', ...],
    'errors' => [],
    'duration' => 0.567,
    'timestamp' => '2025-10-26 14:30:45'
]
```

#### markImageAsUploaded(int $imageId): bool
Markiert ein Bild als hochgeladen (`uploaded = 1`).

**Parameter:**
- `$imageId`: Die ID des Bildes

**Rückgabe:** `true` bei Erfolg, `false` wenn keine Zeile aktualisiert wurde

#### markDocumentAsUploaded(int $documentId): bool
Markiert ein Dokument als hochgeladen (`uploaded = 1`).

**Parameter:**
- `$documentId`: Die ID des Dokuments

**Rückgabe:** `true` bei Erfolg, `false` wenn keine Zeile aktualisiert wurde
