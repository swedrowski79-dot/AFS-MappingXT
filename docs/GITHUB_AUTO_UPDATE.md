# GitHub Auto-Update Feature

## Überblick

Die GitHub Auto-Update-Funktion ermöglicht es der AFS-MappingXT-Anwendung, automatisch nach Updates auf GitHub zu suchen und diese anzuwenden. Dies gewährleistet, dass die Anwendung immer mit den neuesten Bugfixes und Features auf dem aktuellen Stand ist.

## Funktionsweise

### Automatische Updates

Wenn aktiviert, prüft die Anwendung bei jedem Start (CLI oder Web-Interface) auf verfügbare Updates:

1. **Fetch**: Abrufen der neuesten Commits vom GitHub-Repository
2. **Vergleich**: Vergleich des aktuellen lokalen Commits mit dem Remote-Commit
3. **Pull**: Automatisches Ausführen von `git pull`, wenn Updates verfügbar sind

### Schutz der Konfigurationsdatei

Die `.env`-Datei (Umgebungskonfiguration) wird **nicht** durch Updates überschrieben, da sie in `.gitignore` ausgeschlossen ist. Dies stellt sicher, dass:

- Datenbankverbindungen nicht zurückgesetzt werden
- Benutzerdefinierte Einstellungen erhalten bleiben
- Sensible Informationen geschützt sind

## Konfiguration

### Aktivierung

In der `.env`-Datei:

```bash
# GitHub Auto-Update aktivieren
AFS_GITHUB_AUTO_UPDATE=true

# Optional: Spezifischen Branch angeben (leer = aktueller Branch)
AFS_GITHUB_BRANCH=main
```

### Deaktivierung

```bash
AFS_GITHUB_AUTO_UPDATE=false
```

Oder die Zeile komplett entfernen (Standard ist deaktiviert).

## Verwendung

### CLI (Command Line Interface)

#### Automatischer Update-Check beim Sync

```bash
# Sync mit automatischem Update-Check (falls aktiviert)
php indexcli.php run
```

#### Update-Check überspringen

```bash
# Sync ohne Update-Check
php indexcli.php run --skip-update
```

#### Manueller Update-Check

```bash
# Manuell nach Updates suchen und installieren
php indexcli.php update
```

### Web-Interface

Wenn Auto-Update aktiviert ist, wird automatisch vor jedem Sync-Start geprüft:

1. Benutzer klickt auf "Synchronisation starten"
2. System prüft auf GitHub-Updates
3. Wenn Updates verfügbar: automatisches Pull
4. Synchronisation startet mit aktueller Version

### API-Endpoint

```php
// GET: Update-Informationen abrufen
GET /api/github_update.php

// POST: Update durchführen (nur wenn aktiviert oder force=1)
POST /api/github_update.php
POST /api/github_update.php?force=1
```

## Technische Details

### Klasse: AFS_GitHubUpdater

Hauptklasse für die Update-Funktionalität:

```php
$updater = new AFS_GitHubUpdater(
    $repoPath,      // Pfad zum Repository
    $autoUpdate,    // true = Auto-Update aktiviert
    $branch         // Branch-Name (leer = aktueller Branch)
);

// Updates prüfen
$info = $updater->checkForUpdates();

// Update durchführen
$result = $updater->performUpdate();

// Prüfen und automatisch updaten
$result = $updater->checkAndUpdate();
```

### Rückgabewerte

#### checkForUpdates()

```php
[
    'available' => true/false,        // Updates verfügbar?
    'current_commit' => 'abc123',     // Aktueller lokaler Commit (7 Zeichen)
    'remote_commit' => 'def456',      // Remote-Commit (7 Zeichen)
    'commits_behind' => 5,            // Anzahl Commits hinterher
    'branch' => 'main'                // Branch-Name
]
```

#### performUpdate()

```php
[
    'success' => true/false,          // Update erfolgreich?
    'message' => '...',               // Statusmeldung
    'output' => '...'                 // Git-Ausgabe (optional)
]
```

## Sicherheitsaspekte

### Lokale Änderungen

Das System prüft vor dem Update auf lokale Änderungen:

- **Keine Änderungen**: Update wird durchgeführt
- **Lokale Änderungen**: Update wird abgebrochen mit Fehlermeldung

### Fehlerbehandlung

Bei Update-Fehlern:

- CLI: Warnung wird ausgegeben, Sync läuft weiter
- Web: Fehler wird geloggt, Sync läuft weiter
- Update-Fehler verhindern **nicht** den Sync-Prozess

### Geschützte Dateien

Folgende Dateien werden **nicht** durch Updates überschrieben (via `.gitignore`):

- `.env` - Umgebungskonfiguration
- `db/*.db` - Datenbanken
- `logs/*.log` - Log-Dateien
- `Files/Bilder/*` - Medien (Bilder)
- `Files/Dokumente/*` - Medien (Dokumente)

## Troubleshooting

### Problem: "Not a git repository"

**Ursache**: Die Anwendung ist nicht in einem Git-Repository installiert.

**Lösung**: 
```bash
cd /pfad/zur/app
git init
git remote add origin https://github.com/swedrowski79-dot/AFS-MappingXT.git
git fetch
git checkout -b main origin/main
```

### Problem: "Could not authenticate against github.com"

**Ursache**: Fehlende Git-Credentials oder Token.

**Lösung**:
```bash
# Git-Credentials konfigurieren
git config --global credential.helper store

# Oder: SSH-Key verwenden
git remote set-url origin git@github.com:swedrowski79-dot/AFS-MappingXT.git
```

### Problem: "Es gibt lokale Änderungen"

**Ursache**: Lokale Dateien wurden geändert.

**Lösung**:
```bash
# Änderungen anzeigen
git status

# Änderungen verwerfen (VORSICHT!)
git reset --hard HEAD

# Oder: Änderungen committen
git add .
git commit -m "Lokale Änderungen"
```

### Problem: Update schlägt fehl, aber Sync muss laufen

**Lösung**:
```bash
# Auto-Update temporär deaktivieren
AFS_GITHUB_AUTO_UPDATE=false php indexcli.php run

# Oder mit --skip-update
php indexcli.php run --skip-update
```

## Best Practices

### Produktionsumgebung

1. **Testen vor Aktivierung**: 
   ```bash
   # Erst manuell testen
   php indexcli.php update
   ```

2. **Backup vor Auto-Update**:
   - Datenbank-Backups erstellen
   - Wichtige Dateien sichern

3. **Monitoring**:
   - Logs überwachen: `logs/YYYY-MM-DD.log`
   - Update-Status prüfen

### Entwicklungsumgebung

1. **Auto-Update deaktivieren**:
   ```bash
   AFS_GITHUB_AUTO_UPDATE=false
   ```

2. **Manuelle Updates**:
   ```bash
   git pull origin main
   ```

3. **Branch wechseln**:
   ```bash
   AFS_GITHUB_BRANCH=develop
   ```

## Beispiel-Workflow

### Szenario: Wöchentliche automatische Updates

```bash
# 1. .env konfigurieren
echo "AFS_GITHUB_AUTO_UPDATE=true" >> .env
echo "AFS_GITHUB_BRANCH=main" >> .env

# 2. Cronjob einrichten (täglich um 6 Uhr)
0 6 * * * cd /pfad/zur/app && php indexcli.php run >> /var/log/afs-sync.log 2>&1

# 3. Monitoring-Script erstellen
*/15 * * * * tail -n 100 /pfad/zur/app/logs/$(date +\%Y-\%m-\%d).log | grep -i "update"
```

## API-Integration

### JavaScript-Beispiel (Web-Interface)

```javascript
// Update-Status abrufen
fetch('/api/github_update.php')
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            console.log('Auto-Update enabled:', data.data.auto_update_enabled);
            console.log('Update info:', data.data.update_info);
        }
    });

// Update erzwingen
fetch('/api/github_update.php', {
    method: 'POST',
    body: new FormData([['force', '1']])
})
    .then(response => response.json())
    .then(data => {
        if (data.ok && data.data.result.updated) {
            alert('Update erfolgreich!');
        }
    });
```

## Changelog

### Version 1.0.0 (2025-10-26)

- ✨ Initiale Implementierung der GitHub Auto-Update-Funktion
- ✨ CLI-Integration mit `update`-Kommando und `--skip-update`-Flag
- ✨ Web-API-Endpoint für Update-Management
- ✨ Automatische Update-Prüfung bei Sync-Start
- 🔒 Schutz der `.env`-Datei und lokaler Konfigurationen
- 📚 Umfassende Dokumentation

## Support

Bei Problemen oder Fragen:

1. Logs prüfen: `logs/YYYY-MM-DD.log`
2. GitHub Issues: https://github.com/swedrowski79-dot/AFS-MappingXT/issues
3. Dokumentation: `docs/CONFIGURATION_MANAGEMENT.md`
