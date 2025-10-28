<?php
// settings.php
declare(strict_types=1);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Remove server signature
header_remove('X-Powered-By');
header_remove('Server');

$configPath = __DIR__ . '/config.php';
$autoloadPath = __DIR__ . '/autoload.php';

if (!is_file($configPath) || !is_file($autoloadPath)) {
    http_response_code(500);
    echo '<h1>Fehler: System nicht korrekt eingerichtet.</h1>';
    echo '<ul>';
    if (!is_file($configPath)) {
        echo '<li>config.php fehlt</li>';
    }
    if (!is_file($autoloadPath)) {
        echo '<li>autoload.php fehlt</li>';
    }
    echo '</ul>';
    exit;
}

$config = require $configPath;
require $autoloadPath;

// Security check: If security is enabled, only allow access from API
SecurityValidator::validateAccess($config, 'settings.php');

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl   = $scriptDir === '' ? '/' : $scriptDir . '/';
$apiBase   = $baseUrl . 'api/';

$title = (string)($config['ui']['title'] ?? 'AFS-Schnittstelle');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Einstellungen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <style>
<?php echo file_get_contents(__DIR__ . '/assets/css/main.css'); ?>
  </style>
  <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>assets/css/settings.css">
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Einstellungen</h1>
        <p>Konfiguration der lokalen und Remote-Umgebungsvariablen</p>
      </div>
      <div class="tag">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" class="back-link">← Zurück zur Übersicht</a>
      </div>
    </header>

    <div class="grid">
      <section class="card" style="grid-column: 1 / -1;">
        <div class="settings-header">
          <h2>Umgebungsvariablen (.env)</h2>
          <div class="settings-actions">
            <button id="btn-reload" class="btn-secondary">🔄 Neu laden</button>
            <button id="btn-save" class="btn-primary">💾 Speichern</button>
          </div>
        </div>
        
        <!-- Server Selector Section -->
        <div class="server-selector-section">
          <div class="server-selector-header">
            <div class="server-selector-label">🖥️ Server auswählen</div>
            <div class="server-selector-controls">
              <select id="server-select" class="server-select">
                <option value="local">Lokaler Server</option>
              </select>
              <button id="btn-manage-servers" class="btn-server-manage">🔧 Server verwalten</button>
            </div>
          </div>
          <div id="current-server-info" style="font-size: 0.85rem; color: rgba(226, 232, 240, 0.7);">
            Aktuell: <span id="current-server-name">Lokaler Server</span>
            <span id="current-server-badge" class="server-badge local">LOKAL</span>
            <span id="current-server-database" class="current-server-meta"></span>
          </div>
        </div>
        
        <div id="status-message" class="status-message"></div>
        
        <div id="settings-container">
          <p style="color: var(--muted);">Einstellungen werden geladen...</p>
        </div>
        
      <div class="settings-footer">
        <strong>Hinweis:</strong> Änderungen an den Einstellungen werden in der <code>.env</code> Datei gespeichert.
        Eine Sicherungskopie wird automatisch erstellt. Die Änderungen werden beim nächsten Start der Anwendung wirksam.
      </div>
    </section>

    <section class="card" id="databases-card">
      <div class="settings-header">
        <h2>Datenbanken &amp; Pfade</h2>
        <div class="settings-actions">
          <button id="btn-db-refresh" class="btn-secondary">🔄 Aktualisieren</button>
          <button id="btn-db-add" class="btn-primary">➕ Verbindung hinzufügen</button>
        </div>
      </div>
      <div id="databases-empty" style="color: var(--muted); font-size: 0.9rem;">Noch keine Verbindungen konfiguriert.</div>
      <div class="database-list" id="database-list" hidden></div>
    </section>
  </div>
</div>

  <div id="loading-overlay" class="loading-overlay">
    <div class="loading-spinner"></div>
  </div>

  <!-- Server Management Modal -->
  <div id="server-modal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Remote Server verwalten</div>
        <button class="modal-close" id="modal-close">&times;</button>
      </div>
      
      <div class="server-list" id="server-list">
        <!-- Server list will be populated by JavaScript -->
      </div>
      
      <div class="server-form" id="server-form" style="display: none;">
        <h3 style="margin-bottom: 1rem; color: rgba(226, 232, 240, 0.9);" id="form-title">Server hinzufügen</h3>
        
        <div class="form-group">
          <label class="form-label" for="server-name">Server-Name *</label>
          <input type="text" id="server-name" class="form-input" placeholder="z.B. Produktions-Server" required>
        </div>
        
        <div class="form-group">
          <label class="form-label" for="server-url">Server-URL *</label>
          <input type="url" id="server-url" class="form-input" placeholder="https://server.example.com" required>
        </div>
        
        <div class="form-group">
          <label class="form-label" for="server-api-key">API-Schlüssel (optional)</label>
          <input type="password" id="server-api-key" class="form-input" placeholder="Leer lassen für automatische Einrichtung">
          <small style="color: rgba(226, 232, 240, 0.6); font-size: 0.8rem;">
            Falls der Remote-Server noch keine .env hat, wird automatisch eine mit dem lokalen API-Key erstellt.
          </small>
        </div>
        
        <div class="form-group">
          <label class="form-label" for="server-database">Datenbank-Bezeichnung (optional)</label>
          <input type="text" id="server-database" class="form-input" placeholder="z.B. evo.db">
          <small style="color: rgba(226, 232, 240, 0.6); font-size: 0.8rem;">
            Wird in der Übersicht angezeigt, um die Zuordnung Server → Datenbank zu verdeutlichen.
          </small>
        </div>
        
        <div class="form-actions">
          <button id="btn-form-cancel" class="btn-secondary">Abbrechen</button>
          <button id="btn-form-save" class="btn-primary">Speichern</button>
        </div>
      </div>
      
      <button id="btn-add-server" class="btn-primary" style="width: 100%; margin-top: 1rem;">+ Neuen Server hinzufügen</button>
    </div>
  </div>

  <!-- Database Management Modal -->
  <div id="database-modal" class="modal-overlay">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title" id="db-modal-title">Verbindung hinzufügen</div>
        <button class="modal-close" id="db-modal-close">&times;</button>
      </div>

      <div class="form-group">
        <label class="form-label" for="db-title">Titel *</label>
        <input type="text" id="db-title" class="form-input" placeholder="z.B. Produktions-MSSQL" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="db-type">Datenbanktyp *</label>
        <select id="db-type" class="form-input"></select>
      </div>

      <div class="form-group">
        <label class="form-label">Verwendungszwecke</label>
        <div id="db-form-roles" class="db-form-roles"></div>
        <small style="color: rgba(226, 232, 240, 0.6); font-size: 0.8rem;">
          Es können mehrere Rollen ausgewählt werden, sofern sie zum Typ passen.
        </small>
      </div>

      <div id="db-form-fields"></div>

      <div id="db-form-status" class="status-message"></div>

      <div class="form-actions">
        <button id="db-btn-test" class="btn-secondary" type="button">🔍 Verbindung testen</button>
        <div style="flex:1"></div>
        <button id="db-btn-cancel" class="btn-secondary" type="button">Abbrechen</button>
        <button id="db-btn-save" class="btn-primary" type="button">Speichern</button>
      </div>
    </div>
  </div>

  <script>
    // Application configuration
    window.APP_CONFIG = {
      apiBase: <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>assets/js/settings.js"></script>
</body>
</html>
