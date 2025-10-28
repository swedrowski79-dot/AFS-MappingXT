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
  <style>
    /* Additional styles for settings page */
    .settings-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
    }
    
    .settings-actions {
      display: flex;
      gap: 0.5rem;
    }
    
    .server-selector-section {
      background: rgba(148, 163, 184, 0.05);
      padding: 1rem;
      border-radius: 6px;
      margin-bottom: 1.5rem;
      border: 1px solid rgba(148, 163, 184, 0.2);
    }
    
    .server-selector-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.75rem;
    }
    
    .server-selector-label {
      font-weight: 600;
      color: rgba(226, 232, 240, 0.9);
      font-size: 0.95rem;
    }
    
    .server-selector-controls {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }
    
    .server-select {
      background: rgba(15, 23, 42, 0.6);
      border: 1px solid rgba(148, 163, 184, 0.3);
      color: #e2e8f0;
      padding: 0.5rem 0.75rem;
      border-radius: 4px;
      font-size: 0.9rem;
      min-width: 250px;
      cursor: pointer;
    }
    
    .server-select:focus {
      outline: none;
      border-color: var(--primary);
    }
    
    .btn-server-manage {
      background: rgba(59, 130, 246, 0.2);
      border: 1px solid rgba(59, 130, 246, 0.3);
      color: rgb(96, 165, 250);
      padding: 0.5rem 0.75rem;
      border-radius: 4px;
      cursor: pointer;
      font-size: 0.85rem;
      white-space: nowrap;
    }
    
    .btn-server-manage:hover {
      background: rgba(59, 130, 246, 0.3);
      border-color: rgba(59, 130, 246, 0.5);
    }
    
    .server-badge {
      display: inline-block;
      padding: 0.25rem 0.5rem;
      border-radius: 3px;
      font-size: 0.75rem;
      font-weight: 600;
      margin-left: 0.5rem;
    }
    
    .server-badge.local {
      background: rgba(34, 197, 94, 0.2);
      color: rgb(74, 222, 128);
    }
    
    .server-badge.remote {
      background: rgba(59, 130, 246, 0.2);
      color: rgb(96, 165, 250);
    }
    
    .current-server-meta {
      margin-left: 0.75rem;
      font-size: 0.8rem;
      color: rgba(226, 232, 240, 0.6);
    }

    .setting-row.setting-row-toggle {
      align-items: center;
    }

    .toggle-wrapper {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 46px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(148, 163, 184, 0.35);
      transition: 0.2s;
      border-radius: 24px;
    }

    .toggle-slider::before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      top: 3px;
      background-color: #fff;
      border-radius: 50%;
      transition: 0.2s;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.45);
    }

    .toggle-switch input:checked + .toggle-slider {
      background-color: rgba(34, 197, 94, 0.45);
    }

    .toggle-switch input:checked + .toggle-slider::before {
      transform: translateX(22px);
      background-color: #1e293b;
    }

    .toggle-text {
      font-size: 0.85rem;
      color: rgba(226, 232, 240, 0.8);
      min-width: 90px;
    }

    .database-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .database-item {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 0.9rem;
      border-radius: 6px;
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }

    .database-item header {
      margin-bottom: 0.35rem;
      font-weight: 600;
      color: rgba(226, 232, 240, 0.95);
    }

    .database-meta {
      font-size: 0.8rem;
      color: rgba(226, 232, 240, 0.65);
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }

    .database-actions {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      align-items: flex-end;
    }

    .database-status {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.25rem 0.5rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 600;
    }

    .database-status.ok {
      background: rgba(34, 197, 94, 0.15);
      color: rgb(74, 222, 128);
      border: 1px solid rgba(34, 197, 94, 0.35);
    }

    .database-status.error {
      background: rgba(239, 68, 68, 0.15);
      color: rgb(252, 165, 165);
      border: 1px solid rgba(239, 68, 68, 0.35);
    }

    .database-status.warning {
      background: rgba(234, 179, 8, 0.15);
      color: rgb(250, 204, 21);
      border: 1px solid rgba(234, 179, 8, 0.35);
    }

    .database-roles {
      display: flex;
      flex-wrap: wrap;
      gap: 0.35rem;
      margin-top: 0.35rem;
    }

    .database-role-pill {
      padding: 0.2rem 0.45rem;
      border-radius: 999px;
      background: rgba(59, 130, 246, 0.18);
      color: rgba(191, 219, 254, 0.95);
      font-size: 0.7rem;
      font-weight: 600;
    }

    .database-actions .btn-small {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      background: rgba(59, 130, 246, 0.18);
      border-color: rgba(59, 130, 246, 0.3);
      color: rgba(191, 219, 254, 0.95);
    }

    .database-actions .btn-small:hover {
      background: rgba(59, 130, 246, 0.28);
    }

    .db-form-roles {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }

    .db-role-option {
      display: flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.85rem;
      color: rgba(226, 232, 240, 0.85);
    }

    .db-type-badge {
      padding: 0.2rem 0.5rem;
      border-radius: 999px;
      background: rgba(148, 163, 184, 0.2);
      border: 1px solid rgba(148, 163, 184, 0.3);
      font-size: 0.7rem;
      color: rgba(226, 232, 240, 0.85);
      display: inline-block;
      margin-left: 0.5rem;
    }
    
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      z-index: 2000;
      align-items: center;
      justify-content: center;
    }
    
    .modal-overlay.visible {
      display: flex;
    }
    
    .modal-content {
      background: rgba(30, 41, 59, 0.98);
      border: 1px solid rgba(148, 163, 184, 0.3);
      border-radius: 8px;
      padding: 1.5rem;
      max-width: 600px;
      width: 90%;
      max-height: 80vh;
      overflow-y: auto;
    }
    
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    }
    
    .modal-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: rgba(226, 232, 240, 0.9);
    }
    
    .modal-close {
      background: none;
      border: none;
      color: rgba(226, 232, 240, 0.6);
      font-size: 1.5rem;
      cursor: pointer;
      padding: 0;
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .modal-close:hover {
      color: rgba(226, 232, 240, 1);
    }
    
    .server-list {
      margin-bottom: 1rem;
    }
    
    .server-list-item {
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid rgba(148, 163, 184, 0.2);
      padding: 0.75rem;
      border-radius: 4px;
      margin-bottom: 0.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .server-list-item-info {
      flex: 1;
    }
    
    .server-list-item-name {
      font-weight: 600;
      color: rgba(226, 232, 240, 0.9);
      margin-bottom: 0.25rem;
    }
    
    .server-list-item-url {
      font-size: 0.85rem;
      color: rgba(226, 232, 240, 0.6);
      font-family: monospace;
    }
    
    .server-list-item-actions {
      display: flex;
      gap: 0.5rem;
    }
    
    .btn-small {
      padding: 0.35rem 0.6rem;
      font-size: 0.8rem;
      border-radius: 3px;
      cursor: pointer;
      border: 1px solid;
    }
    
    .btn-edit {
      background: rgba(234, 179, 8, 0.2);
      border-color: rgba(234, 179, 8, 0.3);
      color: rgb(250, 204, 21);
    }
    
    .btn-edit:hover {
      background: rgba(234, 179, 8, 0.3);
    }
    
    .btn-delete {
      background: rgba(239, 68, 68, 0.2);
      border-color: rgba(239, 68, 68, 0.3);
      color: rgb(252, 165, 165);
    }
    
    .btn-delete:hover {
      background: rgba(239, 68, 68, 0.3);
    }
    
    .server-form {
      margin-top: 1.5rem;
      padding-top: 1.5rem;
      border-top: 1px solid rgba(148, 163, 184, 0.2);
    }
    
    .form-group {
      margin-bottom: 1rem;
    }
    
    .form-label {
      display: block;
      font-weight: 500;
      color: rgba(226, 232, 240, 0.9);
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
    }
    
    .form-input {
      width: 100%;
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid rgba(148, 163, 184, 0.2);
      color: #e2e8f0;
      padding: 0.5rem 0.75rem;
      border-radius: 4px;
      font-size: 0.9rem;
    }
    
    .form-input:focus {
      outline: none;
      border-color: var(--primary);
    }
    
    .form-actions {
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
      margin-top: 1rem;
    }
    
    .category-section {
      margin-bottom: 2rem;
    }
    
    .category-header {
      background: rgba(148, 163, 184, 0.1);
      padding: 0.75rem 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
      font-weight: 600;
      color: var(--primary);
    }
    
    .category-description {
      font-size: 0.85rem;
      color: rgba(226, 232, 240, 0.7);
      font-weight: 400;
      margin-top: 0.25rem;
    }
    
    .setting-row {
      display: grid;
      grid-template-columns: 1fr 2fr auto;
      gap: 0.5rem;
      margin-bottom: 1rem;
      align-items: center;
    }
    
    .setting-label {
      font-weight: 500;
      color: rgba(226, 232, 240, 0.9);
      font-size: 0.9rem;
    }
    
    .setting-input {
      background: rgba(15, 23, 42, 0.4);
      border: 1px solid rgba(148, 163, 184, 0.2);
      color: #e2e8f0;
      padding: 0.5rem 0.75rem;
      border-radius: 4px;
      font-family: 'Courier New', monospace;
      font-size: 0.9rem;
      width: 100%;
    }
    
    .setting-input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(15, 23, 42, 0.6);
    }
    
    .setting-input[type="password"] {
      font-family: monospace;
    }
    
    .status-message {
      padding: 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
      display: none;
    }
    
    .status-message.success {
      background: rgba(34, 197, 94, 0.1);
      border: 1px solid rgba(34, 197, 94, 0.3);
      color: rgb(74, 222, 128);
    }
    
    .status-message.error {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: rgb(252, 165, 165);
    }
    
    .status-message.visible {
      display: block;
    }
    
    .back-link {
      color: var(--primary);
      text-decoration: none;
      font-size: 0.9rem;
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }
    
    .back-link:hover {
      text-decoration: underline;
    }
    
    .settings-footer {
      margin-top: 2rem;
      padding-top: 1rem;
      border-top: 1px solid rgba(148, 163, 184, 0.2);
      color: rgba(226, 232, 240, 0.6);
      font-size: 0.85rem;
    }
    
    .loading-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      z-index: 1000;
      align-items: center;
      justify-content: center;
    }
    
    .loading-overlay.visible {
      display: flex;
    }
    
    .loading-spinner {
      border: 4px solid rgba(148, 163, 184, 0.2);
      border-top: 4px solid var(--primary);
      border-radius: 50%;
      width: 50px;
      height: 50px;
      animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    .btn-generate {
      background: rgba(59, 130, 246, 0.2);
      border: 1px solid rgba(59, 130, 246, 0.3);
      color: rgb(96, 165, 250);
      padding: 0.4rem 0.75rem;
      border-radius: 4px;
      cursor: pointer;
      font-size: 0.85rem;
      transition: all 0.2s ease;
      white-space: nowrap;
    }
    
    .btn-generate:hover {
      background: rgba(59, 130, 246, 0.3);
      border-color: rgba(59, 130, 246, 0.5);
    }
    
    .btn-generate:active {
      transform: scale(0.98);
    }
    
    .no-env-message {
      background: rgba(234, 179, 8, 0.1);
      border: 1px solid rgba(234, 179, 8, 0.3);
      color: rgb(250, 204, 21);
      padding: 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
    }
    
    .no-env-message h3 {
      margin: 0 0 0.5rem 0;
      font-size: 1rem;
    }
    
    .no-env-message p {
      margin: 0.5rem 0;
      font-size: 0.9rem;
    }
    
    .btn-create-env {
      background: rgba(34, 197, 94, 0.2);
      border: 1px solid rgba(34, 197, 94, 0.3);
      color: rgb(74, 222, 128);
      padding: 0.6rem 1rem;
      border-radius: 4px;
      cursor: pointer;
      font-size: 0.9rem;
      font-weight: 600;
      margin-top: 0.5rem;
    }
    
    .btn-create-env:hover {
      background: rgba(34, 197, 94, 0.3);
      border-color: rgba(34, 197, 94, 0.5);
    }
  </style>
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
  <script>
    (function(config) {
      'use strict';

      const API_BASE = config.apiBase;
      const settingsContainer = document.getElementById('settings-container');
      const statusMessage = document.getElementById('status-message');
      const btnSave = document.getElementById('btn-save');
      const btnReload = document.getElementById('btn-reload');
      const loadingOverlay = document.getElementById('loading-overlay');

      let currentSettings = {};
      let categories = {};

      const BOOLEAN_KEYS = new Set([
        'AFS_SECURITY_ENABLED',
        'AFS_ENABLE_FILE_LOGGING',
        'AFS_GITHUB_AUTO_UPDATE',
        'DATA_TRANSFER_ENABLE_DB',
        'DATA_TRANSFER_ENABLE_IMAGES',
        'DATA_TRANSFER_ENABLE_DOCUMENTS',
        'DATA_TRANSFER_LOG_TRANSFERS',
        'REMOTE_SERVERS_ENABLED',
        'SYNC_BIDIRECTIONAL'
      ]);

      const SELECT_OPTIONS = {
        PHP_MEMORY_LIMIT: [
          { value: '128M', label: '128 MB' },
          { value: '256M', label: '256 MB' },
          { value: '512M', label: '512 MB' },
          { value: '1G', label: '1 GB' },
          { value: '2G', label: '2 GB' }
        ],
        PHP_MAX_EXECUTION_TIME: [
          { value: '60', label: '60 Sekunden' },
          { value: '120', label: '2 Minuten' },
          { value: '300', label: '5 Minuten' },
          { value: '600', label: '10 Minuten' },
          { value: '1200', label: '20 Minuten' }
        ],
        TZ: [
          { value: 'Europe/Berlin', label: 'Europe/Berlin (Empfohlen)' },
          { value: 'UTC', label: 'UTC' },
          { value: 'Europe/Zurich', label: 'Europe/Zurich' },
          { value: 'America/New_York', label: 'America/New_York' },
          { value: 'Asia/Dubai', label: 'Asia/Dubai' }
        ],
        OPCACHE_MEMORY_CONSUMPTION: [
          { value: '128', label: '128 MB' },
          { value: '256', label: '256 MB' },
          { value: '512', label: '512 MB' }
        ],
        OPCACHE_INTERNED_STRINGS_BUFFER: [
          { value: '8', label: '8 MB' },
          { value: '16', label: '16 MB' },
          { value: '32', label: '32 MB' }
        ],
        OPCACHE_MAX_ACCELERATED_FILES: [
          { value: '4000', label: '4.000 Dateien' },
          { value: '10000', label: '10.000 Dateien' },
          { value: '20000', label: '20.000 Dateien' }
        ],
        OPCACHE_REVALIDATE_FREQ: [
          { value: '0', label: '0 Sekunden (Entwicklung)' },
          { value: '2', label: '2 Sekunden' },
          { value: '60', label: '60 Sekunden' },
          { value: '120', label: '120 Sekunden' },
          { value: '300', label: '5 Minuten' }
        ],
        OPCACHE_VALIDATE_TIMESTAMPS: [
          { value: '0', label: '0 (keine Prüfung)' },
          { value: '1', label: '1 (prüfen)' }
        ],
        OPCACHE_HUGE_CODE_PAGES: [
          { value: '0', label: 'Deaktiviert' },
          { value: '1', label: 'Aktiviert' }
        ],
        OPCACHE_JIT_MODE: [
          { value: 'disable', label: 'disable (0)' },
          { value: 'tracing', label: 'tracing (Empfohlen)' },
          { value: 'function', label: 'function' }
        ],
        OPCACHE_JIT_BUFFER_SIZE: [
          { value: '0', label: '0 (deaktiviert)' },
          { value: '64M', label: '64 MB' },
          { value: '128M', label: '128 MB' },
          { value: '256M', label: '256 MB' }
        ]
      };

      const DB_TYPE_FIELDS = {
        mssql: [
          { key: 'host', label: 'Host *', type: 'text', placeholder: 'z.B. 10.0.1.82', required: true },
          { key: 'port', label: 'Port', type: 'number', placeholder: '1433', required: false, default: 1433 },
          { key: 'database', label: 'Datenbank *', type: 'text', placeholder: 'z.B. AFS_2018', required: true },
          { key: 'username', label: 'Benutzer *', type: 'text', placeholder: 'z.B. sa', required: true },
          { key: 'password', label: 'Passwort', type: 'password', placeholder: 'Leer lassen für unverändert', required: false },
          { key: 'encrypt', label: 'TLS-Verschlüsselung aktivieren', type: 'checkbox', default: true },
          { key: 'trust_server_certificate', label: 'Serverzertifikat vertrauen (DEV)', type: 'checkbox', default: false }
        ],
        mysql: [
          { key: 'host', label: 'Host *', type: 'text', placeholder: 'z.B. localhost', required: true },
          { key: 'port', label: 'Port', type: 'number', placeholder: '3306', required: false, default: 3306 },
          { key: 'database', label: 'Datenbank *', type: 'text', placeholder: 'z.B. xtcommerce', required: true },
          { key: 'username', label: 'Benutzer *', type: 'text', placeholder: 'z.B. xt_user', required: true },
          { key: 'password', label: 'Passwort', type: 'password', placeholder: 'Leer lassen für unverändert', required: false }
        ],
        sqlite: [
          { key: 'path', label: 'Dateipfad *', type: 'text', placeholder: 'z.B. db/evo.db', required: true }
        ],
        file: [
          { key: 'path', label: 'Verzeichnis *', type: 'text', placeholder: 'z.B. /mnt/share/data', required: true }
        ]
      };

      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';
        return div.innerHTML;
      }

      function showLoading(show) {
        loadingOverlay.classList.toggle('visible', show);
      }

      function showStatus(message, type) {
        statusMessage.textContent = message;
        statusMessage.className = 'status-message visible ' + type;
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
          statusMessage.classList.remove('visible');
        }, 5000);
      }

      async function fetchJson(endpoint, options = {}) {
        const response = await fetch(API_BASE + endpoint, {
          cache: 'no-store',
          ...options,
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...(options.headers || {})
          }
        });

        const payload = await response.json();
        if (!response.ok || payload.ok === false) {
          const detail = payload.error || response.statusText || 'Unbekannter Fehler';
          throw new Error(detail);
        }
        return payload;
      }

      function renderSettings(settings, categoriesData) {
        currentSettings = settings;
        categories = categoriesData;

        let html = '';

        for (const [categoryKey, categoryData] of Object.entries(categoriesData)) {
          html += `<div class="category-section">`;
          html += `<div class="category-header">`;
          html += escapeHtml(categoryData.label);
          if (categoryData.description) {
            html += `<div class="category-description">${escapeHtml(categoryData.description)}</div>`;
          }
          html += `</div>`;

          for (const key of categoryData.keys) {
            const rawValue = settings[key] ?? '';
            const value = String(rawValue);
            const lowerValue = value.toLowerCase();
            const isApiKey = key === 'DATA_TRANSFER_API_KEY';
            const isPassword = key.endsWith('_PASS') || key.endsWith('_PASSWORD') || isApiKey || 
                               key === 'AFS_MSSQL_PASS' || key === 'XT_MYSQL_PASS';
            const isBoolean = BOOLEAN_KEYS.has(key) || lowerValue === 'true' || lowerValue === 'false';
            const selectOptions = SELECT_OPTIONS[key] || null;
            const datalistId = selectOptions ? `options-${key}` : null;

            html += `<div class="setting-row${isBoolean ? ' setting-row-toggle' : ''}">`;
            html += `<label class="setting-label" for="setting-${key}">${escapeHtml(key)}</label>`;

            if (isBoolean) {
              const checked = lowerValue === 'true';
              const toggleId = `setting-${key}`;
              html += `
                <div class="toggle-wrapper">
                  <label class="toggle-switch">
                    <input type="checkbox" id="${toggleId}" class="setting-input setting-toggle-input"
                           data-key="${escapeHtml(key)}" ${checked ? 'checked' : ''}>
                    <span class="toggle-slider"></span>
                  </label>
                  <span class="toggle-text" data-toggle-label="${escapeHtml(key)}">${checked ? 'Aktiviert' : 'Deaktiviert'}</span>
                </div>
              `;
              html += `<div></div>`;
            } else {
              const inputId = `setting-${key}`;
              const inputType = isPassword ? 'password' : 'text';
              const listAttr = datalistId ? ` list="${datalistId}"` : '';
              html += `<div>`;
              html += `<input type="${inputType}" id="${inputId}" class="setting-input" 
                            data-key="${escapeHtml(key)}" value="${escapeHtml(value)}" 
                            placeholder="(leer)"${listAttr}>`;
              if (selectOptions) {
                html += `<datalist id="${datalistId}">`;
                let hasCurrent = false;
                selectOptions.forEach(option => {
                  const optionValue = String(option.value);
                  if (optionValue === value) {
                    hasCurrent = true;
                  }
                  const optionLabel = option.label ? ` label="${escapeHtml(option.label)}"` : '';
                  html += `<option value="${escapeHtml(optionValue)}"${optionLabel}></option>`;
                });
                if (value && !hasCurrent) {
                  html += `<option value="${escapeHtml(value)}" label="(aktueller Wert)"></option>`;
                }
                html += `</datalist>`;
              }
              html += `</div>`;
              
              if (isApiKey) {
                html += `<button class="btn-generate" data-target="${inputId}" title="Neuen API-Key generieren">🔑 Generieren</button>`;
              } else {
                html += `<div></div>`;
              }
            }
            
            html += `</div>`;
          }

          html += `</div>`;
        }

        settingsContainer.innerHTML = html;
        
        const toggleInputs = settingsContainer.querySelectorAll('.setting-toggle-input');
        toggleInputs.forEach(input => {
          const label = input.closest('.toggle-wrapper')?.querySelector('.toggle-text');
          const updateLabel = () => {
            if (label) {
              label.textContent = input.checked ? 'Aktiviert' : 'Deaktiviert';
            }
          };
          updateLabel();
          input.addEventListener('change', updateLabel);
        });
        
        // Attach event listeners to generate buttons
        const generateButtons = settingsContainer.querySelectorAll('.btn-generate');
        generateButtons.forEach(btn => {
          btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const targetId = btn.dataset.target;
            await generateApiKey(targetId);
          });
        });
      }

      function getUpdatedSettings() {
        const inputs = settingsContainer.querySelectorAll('.setting-input');
        const updated = {};

        inputs.forEach(input => {
          const key = input.dataset.key;
          let value;
          if (input.type === 'checkbox') {
            value = input.checked ? 'true' : 'false';
          } else {
            value = input.value;
          }
          const currentValue = String(currentSettings[key] ?? '');
          
          // Only include changed values
          if (value !== currentValue) {
            updated[key] = value;
          }
        });

        return updated;
      }

      async function loadSettings() {
        try {
          showLoading(true);
          
          let response;
          
          // Load from remote server if selected
          if (currentServerIndex >= 0) {
            // Validate server index
            if (!Number.isInteger(currentServerIndex) || currentServerIndex >= remoteServers.length) {
              throw new Error('Ungültiger Server-Index');
            }
            response = await fetchJson(`settings_remote.php?server_index=${encodeURIComponent(currentServerIndex)}`);
          } else {
            // Load from local server
            response = await fetchJson('settings_read.php');
          }
          
          const data = response.data;

          if (!data.env_file_exists) {
            showNoEnvMessage();
            return;
          }

          if (!data.env_file_writable) {
            showStatus('Warnung: .env Datei ist nicht beschreibbar', 'error');
          }

          renderSettings(data.settings, data.categories);
        } catch (error) {
          const message = error?.message || '';
          const isLocal = currentServerIndex < 0;
          const looksLikeMissingEnv = /(\.env|env datei|config)/i.test(message);
          if (isLocal && looksLikeMissingEnv) {
            showNoEnvMessage();
          } else {
            showStatus('Fehler beim Laden der Einstellungen: ' + message, 'error');
            settingsContainer.innerHTML = '<p style="color: var(--error);">Fehler beim Laden der Einstellungen.</p>';
          }
        } finally {
          showLoading(false);
        }
      }
      
      function showNoEnvMessage() {
        const isRemote = currentServerIndex >= 0;
        const serverName = isRemote ? remoteServers[currentServerIndex]?.name : 'Lokaler Server';
        
        settingsContainer.innerHTML = `
          <div class="no-env-message">
            <h3>⚠️ Keine .env Datei gefunden</h3>
            <p>Die Konfigurationsdatei <code>.env</code> wurde auf ${escapeHtml(serverName)} nicht gefunden.</p>
            ${isRemote ? 
              `<p>Sie können automatisch eine .env Datei auf dem Remote-Server mit dem lokalen API-Key erstellen.</p>
               <button class="btn-create-env" id="btn-create-remote-env">📝 Remote .env Datei erstellen</button>` :
              `<p>Die Datei wird auf Basis von <code>.env.example</code> erstellt und enthält alle notwendigen Einstellungen.</p>
               <button class="btn-create-env" id="btn-create-env">📝 .env Datei erstellen</button>`
            }
          </div>
        `;
        
        if (isRemote) {
          const btnCreateRemoteEnv = document.getElementById('btn-create-remote-env');
          if (btnCreateRemoteEnv) {
            btnCreateRemoteEnv.addEventListener('click', async () => {
              await createRemoteEnv(currentServerIndex);
              await loadSettings();
            });
          }
        } else {
          const btnCreateEnv = document.getElementById('btn-create-env');
          if (btnCreateEnv) {
            btnCreateEnv.addEventListener('click', async () => {
              await createEnvFile();
            });
          }
        }
      }
      
      async function createEnvFile() {
        try {
          showLoading(true);
          
          // Generate a secure API key for initial setup
          const apiKeyResponse = await fetchJson('generate_api_key.php', { method: 'POST' });
          if (!apiKeyResponse.data || !apiKeyResponse.data.api_key) {
            throw new Error('API-Key konnte nicht generiert werden');
          }
          const apiKey = apiKeyResponse.data.api_key;
          
          // Create .env file using initial_setup endpoint
          const response = await fetchJson('initial_setup.php', {
            method: 'POST',
            body: JSON.stringify({
              settings: {
                DATA_TRANSFER_API_KEY: apiKey
              }
            })
          });
          
          showStatus('.env Datei erfolgreich erstellt. API-Key wurde generiert.', 'success');
          
          // Reload settings to show the new configuration
          await loadSettings();
        } catch (error) {
          showStatus('Fehler beim Erstellen der .env Datei: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }
      
      async function generateApiKey(targetInputId) {
        try {
          showLoading(true);
          const response = await fetchJson('generate_api_key.php', { method: 'POST' });
          if (!response.data || !response.data.api_key) {
            throw new Error('API-Key konnte nicht generiert werden');
          }
          const apiKey = response.data.api_key;
          
          // Update the input field
          const input = document.getElementById(targetInputId);
          if (input) {
            input.value = apiKey;
            input.type = 'text'; // Temporarily show the generated key
            
            // Show success message
            showStatus('Neuer API-Key generiert. Bitte speichern Sie die Änderungen.', 'success');
            
            // Switch back to password type after 3 seconds
            setTimeout(() => {
              input.type = 'password';
            }, 3000);
          }
        } catch (error) {
          showStatus('Fehler beim Generieren des API-Keys: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }

      async function saveSettings() {
        const updated = getUpdatedSettings();

        if (Object.keys(updated).length === 0) {
          showStatus('Keine Änderungen vorhanden', 'error');
          return;
        }

        try {
          showLoading(true);
          
          let response;
          
          // Save to remote server if selected
          if (currentServerIndex >= 0) {
            // Validate server index
            if (!Number.isInteger(currentServerIndex) || currentServerIndex >= remoteServers.length) {
              throw new Error('Ungültiger Server-Index');
            }
            response = await fetchJson('settings_remote.php', {
              method: 'POST',
              body: JSON.stringify({ 
                server_index: currentServerIndex,
                settings: updated 
              })
            });
          } else {
            // Save to local server
            response = await fetchJson('settings_write.php', {
              method: 'POST',
              body: JSON.stringify({ settings: updated })
            });
          }

          showStatus(response.data.message + ' (' + response.data.updated_count + ' Einstellungen)', 'success');
          
          // Reload settings to reflect changes
          await loadSettings();
        } catch (error) {
          showStatus('Fehler beim Speichern: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }

      // =========================================================================
      // Database Management
      // =========================================================================

      const dbList = document.getElementById('database-list');
      const dbEmptyState = document.getElementById('databases-empty');
      const dbEmptyDefaultText = dbEmptyState ? dbEmptyState.textContent : '';
      const btnDbAdd = document.getElementById('btn-db-add');
      const btnDbRefresh = document.getElementById('btn-db-refresh');
      const dbModal = document.getElementById('database-modal');
      const dbModalTitle = document.getElementById('db-modal-title');
      const dbModalClose = document.getElementById('db-modal-close');
      const dbTitleInput = document.getElementById('db-title');
      const dbTypeSelect = document.getElementById('db-type');
      const dbRolesContainer = document.getElementById('db-form-roles');
      const dbFieldsContainer = document.getElementById('db-form-fields');
      const dbStatusBox = document.getElementById('db-form-status');
      const dbBtnTest = document.getElementById('db-btn-test');
      const dbBtnSave = document.getElementById('db-btn-save');
      const dbBtnCancel = document.getElementById('db-btn-cancel');

      let databaseConnections = [];
      let databaseRoles = {};
      let databaseTypes = {};
      let editingDatabase = null;

      function setDbStatus(message, type) {
        if (!dbStatusBox) {
          return;
        }
        dbStatusBox.textContent = message;
        dbStatusBox.className = 'status-message visible ' + type;
      }

      function clearDbStatus() {
        if (!dbStatusBox) {
          return;
        }
        dbStatusBox.textContent = '';
        dbStatusBox.className = 'status-message';
      }

      function populateDbTypeOptions(selected) {
        if (!dbTypeSelect) {
          return;
        }
        dbTypeSelect.innerHTML = '';
        const entries = Object.entries(databaseTypes || {});
        if (!entries.length) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'Keine Typen verfügbar';
          dbTypeSelect.appendChild(option);
          dbTypeSelect.disabled = true;
          return;
        }
        entries.forEach(([value, label]) => {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = label;
          dbTypeSelect.appendChild(option);
        });
        dbTypeSelect.disabled = false;
        if (selected && databaseTypes[selected]) {
          dbTypeSelect.value = selected;
        } else {
          dbTypeSelect.selectedIndex = 0;
        }
      }

      function renderDbRoles(type, selectedRoles = []) {
        if (!dbRolesContainer) {
          return;
        }
        dbRolesContainer.innerHTML = '';
        const entries = Object.entries(databaseRoles || {});
        const relevant = entries.filter(([, meta]) => Array.isArray(meta.types) && meta.types.includes(type));
        if (!relevant.length) {
          const info = document.createElement('div');
          info.style.color = 'rgba(226, 232, 240, 0.6)';
          info.style.fontSize = '0.85rem';
          info.textContent = 'Für diesen Typ sind keine Rollen verfügbar.';
          dbRolesContainer.appendChild(info);
          return;
        }
        relevant.forEach(([role, meta]) => {
          const label = document.createElement('label');
          label.className = 'db-role-option';
          const input = document.createElement('input');
          input.type = 'checkbox';
          input.value = role;
          input.name = 'db-role';
          input.checked = selectedRoles.includes(role);
          label.appendChild(input);
          const span = document.createElement('span');
          span.textContent = meta.label || role;
          label.appendChild(span);
          dbRolesContainer.appendChild(label);
        });
      }

      function renderDbFields(type, settings = {}, options = {}) {
        if (!dbFieldsContainer) {
          return;
        }
        dbFieldsContainer.innerHTML = '';
        const fields = DB_TYPE_FIELDS[type] || [];
        fields.forEach(field => {
          const group = document.createElement('div');
          group.className = 'form-group';

          if (field.type === 'checkbox') {
            const label = document.createElement('label');
            label.className = 'db-role-option';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'db-field';
            input.dataset.key = field.key;
            const current = settings[field.key];
            input.checked = current !== undefined ? Boolean(current) : Boolean(field.default);
            label.appendChild(input);
            const span = document.createElement('span');
            span.textContent = field.label;
            label.appendChild(span);
            group.appendChild(label);
          } else {
            const label = document.createElement('label');
            label.className = 'form-label';
            label.textContent = field.label;
            const input = document.createElement('input');
            input.className = 'form-input db-field';
            input.dataset.key = field.key;
            const isNumber = field.type === 'number';
            const isPassword = field.type === 'password';
            input.type = isNumber ? 'number' : (isPassword ? 'password' : 'text');
            if (field.placeholder) {
              input.placeholder = field.placeholder;
            }
            if (isNumber) {
              const current = settings[field.key];
              if (current === undefined || current === null || current === '') {
                input.value = field.default !== undefined ? field.default : '';
              } else {
                input.value = current;
              }
            } else if (isPassword) {
              const protectedFlag = options.passwordProtected === true;
              input.dataset.protected = protectedFlag ? '1' : '0';
              input.value = '';
            } else {
              const current = settings[field.key];
              input.value = current !== undefined ? current : (field.default ?? '');
            }
            group.appendChild(label);
            group.appendChild(input);
          }

          dbFieldsContainer.appendChild(group);
        });
      }

      function collectDatabaseFormData() {
        if (!dbTitleInput || !dbTypeSelect) {
          throw new Error('Formular nicht verfügbar.');
        }
        const title = dbTitleInput.value.trim();
        const type = dbTypeSelect.value;
        if (!title) {
          throw new Error('Bitte einen Titel vergeben.');
        }
        if (!type) {
          throw new Error('Bitte einen Typ auswählen.');
        }

        const roles = [];
        if (dbRolesContainer) {
          dbRolesContainer.querySelectorAll('input[name="db-role"]').forEach(input => {
            if (input.checked) {
              roles.push(input.value);
            }
          });
        }

        const settings = {};
        const fields = DB_TYPE_FIELDS[type] || [];
        fields.forEach(field => {
          const input = dbFieldsContainer
            ? dbFieldsContainer.querySelector(`.db-field[data-key="${field.key}"]`)
            : null;
          if (!input) {
            return;
          }

          let value;
          if (field.type === 'checkbox') {
            value = input.checked;
          } else if (field.type === 'number') {
            value = input.value.trim();
            if (value === '') {
              value = field.default !== undefined ? field.default : '';
            } else {
              value = Number(value);
              if (Number.isNaN(value)) {
                throw new Error(`Bitte eine gültige Zahl für "${field.label}" angeben.`);
              }
            }
          } else if (field.type === 'password') {
            value = input.value;
            if (!value) {
              if (input.dataset.protected === '1' && editingDatabase) {
                value = '__PROTECTED__';
              }
            }
          } else {
            value = input.value.trim();
          }

          if (field.required && (value === '' || value === null || value === undefined)) {
            throw new Error(`Bitte "${field.label.replace('*', '').trim()}" ausfüllen.`);
          }

          settings[field.key] = value;
        });

        return { title, type, roles, settings };
      }

      function openDatabaseModal(connection = null) {
        if (!dbModal) {
          return;
        }
        editingDatabase = connection;
        dbFormReset();
        dbModal.classList.add('visible');
        clearDbStatus();

        const type = connection?.type || Object.keys(databaseTypes || {})[0] || 'mssql';
        populateDbTypeOptions(type);
        dbTitleInput.value = connection?.title || '';
        dbTypeSelect.value = type;
        renderDbRoles(type, connection?.roles || []);
        const options = { passwordProtected: connection?.password_protected === true };
        renderDbFields(type, connection?.settings || {}, options);

        dbModalTitle.textContent = connection ? 'Verbindung bearbeiten' : 'Verbindung hinzufügen';
      }

      function closeDatabaseModal() {
        if (!dbModal) {
          return;
        }
        dbModal.classList.remove('visible');
        editingDatabase = null;
        clearDbStatus();
        dbFormReset();
      }

      function dbFormReset() {
        if (dbTitleInput) dbTitleInput.value = '';
        if (dbTypeSelect) dbTypeSelect.selectedIndex = 0;
        if (dbRolesContainer) dbRolesContainer.innerHTML = '';
        if (dbFieldsContainer) dbFieldsContainer.innerHTML = '';
      }

      function renderDatabaseList() {
        if (!dbList || !dbEmptyState) {
          return;
        }
        if (!databaseConnections.length) {
          dbList.hidden = true;
          dbEmptyState.textContent = currentServerIndex >= 0
            ? 'Noch keine Verbindungen auf diesem Remote-Server.'
            : dbEmptyDefaultText;
          dbEmptyState.hidden = false;
          dbList.innerHTML = '';
          return;
        }
        dbEmptyState.hidden = true;
        dbList.hidden = false;
        dbList.innerHTML = '';

        databaseConnections.forEach(connection => {
          const item = document.createElement('div');
          item.className = 'database-item';
          item.dataset.id = connection.id;

          const info = document.createElement('div');
          const header = document.createElement('header');
          header.textContent = connection.title || connection.id;
          const badge = document.createElement('span');
          badge.className = 'db-type-badge';
          badge.textContent = databaseTypes[connection.type] || connection.type;
          header.appendChild(badge);
          info.appendChild(header);

          const meta = document.createElement('div');
          meta.className = 'database-meta';
          const typeLine = document.createElement('div');
          typeLine.textContent = `Typ: ${databaseTypes[connection.type] || connection.type}`;
          meta.appendChild(typeLine);

          if (connection.settings && connection.type === 'sqlite') {
            const pathLine = document.createElement('div');
            pathLine.textContent = `Pfad: ${connection.settings.path || ''}`;
            meta.appendChild(pathLine);
          }
          if (connection.settings && connection.type === 'file') {
            const pathLine = document.createElement('div');
            pathLine.textContent = `Pfad: ${connection.settings.path || ''}`;
            meta.appendChild(pathLine);
          }
          if (connection.settings && ['mssql', 'mysql'].includes(connection.type)) {
            const hostLine = document.createElement('div');
            const host = connection.settings.host || '';
            const port = connection.settings.port || '';
            hostLine.textContent = `Host: ${host}${port ? ':' + port : ''}`;
            meta.appendChild(hostLine);
            const dbLine = document.createElement('div');
            dbLine.textContent = `Datenbank: ${connection.settings.database || ''}`;
            meta.appendChild(dbLine);
          }

          const rolesPills = document.createElement('div');
          rolesPills.className = 'database-roles';
          (connection.roles || []).forEach(role => {
            const pill = document.createElement('span');
            pill.className = 'database-role-pill';
            pill.textContent = (databaseRoles[role] && databaseRoles[role].label) ? databaseRoles[role].label : role;
            rolesPills.appendChild(pill);
          });
          if (rolesPills.childElementCount > 0) {
            meta.appendChild(rolesPills);
          }

          info.appendChild(meta);

          const actions = document.createElement('div');
          actions.className = 'database-actions';

          const statusBadge = document.createElement('span');
          const status = connection.status || {};
          const statusClass = status.ok === true ? 'ok' : (status.ok === false ? 'error' : 'warning');
          statusBadge.className = 'database-status ' + statusClass;
          statusBadge.textContent = status.ok === true ? 'Online' : (status.ok === false ? 'Offline' : 'Unbekannt');
          actions.appendChild(statusBadge);

          if (status.message) {
            const statusMessage = document.createElement('small');
            statusMessage.style.fontSize = '0.75rem';
            statusMessage.style.color = 'rgba(226, 232, 240, 0.6)';
            statusMessage.textContent = status.message;
            actions.appendChild(statusMessage);
          }

          const editBtn = document.createElement('button');
          editBtn.type = 'button';
          editBtn.className = 'btn-small';
          editBtn.textContent = '✏️ Bearbeiten';
          editBtn.addEventListener('click', () => openDatabaseModal(connection));
          actions.appendChild(editBtn);

          const testBtn = document.createElement('button');
          testBtn.type = 'button';
          testBtn.className = 'btn-small';
          testBtn.textContent = '🔍 Testen';
          testBtn.addEventListener('click', () => testDatabase(connection.id));
          actions.appendChild(testBtn);

          const deleteBtn = document.createElement('button');
          deleteBtn.type = 'button';
          deleteBtn.className = 'btn-small';
          deleteBtn.style.background = 'rgba(239, 68, 68, 0.18)';
          deleteBtn.style.borderColor = 'rgba(239, 68, 68, 0.3)';
          deleteBtn.style.color = 'rgb(252, 165, 165)';
          deleteBtn.textContent = '🗑️ Löschen';
          deleteBtn.addEventListener('click', () => deleteDatabase(connection.id));
          actions.appendChild(deleteBtn);

          item.appendChild(info);
          item.appendChild(actions);
          dbList.appendChild(item);
        });
      }

      async function loadDatabases() {
        const isRemote = currentServerIndex >= 0;
        try {
          if (dbEmptyState) {
            dbEmptyState.hidden = false;
            dbEmptyState.textContent = isRemote
              ? 'Verbindungen werden vom Remote-Server geladen...'
              : dbEmptyDefaultText;
          }
          const endpoint = isRemote
            ? `databases_remote.php?server_index=${encodeURIComponent(currentServerIndex)}`
            : 'databases_manage.php';
          const response = await fetchJson(endpoint);
          const data = response.data || {};
          databaseConnections = data.connections || [];
          databaseRoles = data.roles || {};
          databaseTypes = data.types || {};
          renderDatabaseList();
        } catch (error) {
          showStatus('Fehler beim Laden der Datenbanken: ' + error.message, 'error');
          if (dbEmptyState) {
            dbEmptyState.hidden = false;
            dbEmptyState.textContent = error.message;
          }
        }
      }

      async function saveDatabase() {
        try {
          clearDbStatus();
          showLoading(true);
          const formData = collectDatabaseFormData();
          const payload = {
            action: editingDatabase ? 'update' : 'add',
            connection: {
              id: editingDatabase ? editingDatabase.id : '',
              ...formData
            }
          };
          if (currentServerIndex >= 0) {
            payload.server_index = currentServerIndex;
          }
          const endpoint = currentServerIndex >= 0 ? 'databases_remote.php' : 'databases_manage.php';
          const response = await fetchJson(endpoint, {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          const message = response.data?.message || 'Verbindung gespeichert.';
          showStatus(message, 'success');
          closeDatabaseModal();
          await loadDatabases();
        } catch (error) {
          showLoading(false);
          setDbStatus(error.message, 'error');
        } finally {
          showLoading(false);
        }
      }

      async function deleteDatabase(id) {
        if (!id) {
          return;
        }
        const confirmed = window.confirm('Verbindung wirklich löschen?');
        if (!confirmed) {
          return;
        }
        try {
          showLoading(true);
          const payload = { id };
          if (currentServerIndex >= 0) {
            payload.server_index = currentServerIndex;
          }
          const endpoint = currentServerIndex >= 0 ? 'databases_remote.php' : 'databases_manage.php';
          await fetchJson(endpoint, {
            method: 'DELETE',
            body: JSON.stringify(payload)
          });
          showStatus('Verbindung gelöscht.', 'success');
          await loadDatabases();
        } catch (error) {
          showStatus('Fehler beim Löschen: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }

      async function testDatabase(id) {
        if (!id) {
          return;
        }
        try {
          showLoading(true);
          const payload = { id };
          if (currentServerIndex >= 0) {
            payload.server_index = currentServerIndex;
          }
          const endpoint = currentServerIndex >= 0 ? 'databases_test_remote.php' : 'databases_test.php';
          const response = await fetchJson(endpoint, {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          const status = response.data?.status || {};
          const type = status.ok ? 'success' : 'error';
          showStatus(status.message || 'Test abgeschlossen.', type);
          await loadDatabases();
        } catch (error) {
          showStatus('Fehler beim Testen: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }

      async function testDatabaseForm() {
        try {
          clearDbStatus();
          const formData = collectDatabaseFormData();
          const payload = {
            connection: {
              id: editingDatabase ? editingDatabase.id : '',
              ...formData
            }
          };
          if (currentServerIndex >= 0) {
            payload.server_index = currentServerIndex;
          }
          const endpoint = currentServerIndex >= 0 ? 'databases_test_remote.php' : 'databases_test.php';
          const response = await fetchJson(endpoint, {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          const status = response.data?.status || {};
          const type = status.ok ? 'success' : 'error';
          setDbStatus(status.message || 'Test abgeschlossen.', type);
        } catch (error) {
          setDbStatus(error.message, 'error');
        }
      }

      function updateDatabaseCardState() {
        if (!dbEmptyState) {
          return;
        }
        dbEmptyState.textContent = currentServerIndex >= 0
          ? 'Verbindungen werden vom Remote-Server geladen...'
          : dbEmptyDefaultText;
      }

      function updateCurrentServerDisplay() {
        if (currentServerIndex === -1) {
          currentServerName.textContent = 'Lokaler Server';
          currentServerBadge.textContent = 'LOKAL';
          currentServerBadge.className = 'server-badge local';
          if (currentServerDatabase) {
            currentServerDatabase.textContent = '';
          }
        } else {
          const server = remoteServers[currentServerIndex];
          currentServerName.textContent = server ? server.name : 'Unbekannt';
          currentServerBadge.textContent = 'REMOTE';
          currentServerBadge.className = 'server-badge remote';
          if (currentServerDatabase) {
            currentServerDatabase.textContent = server && server.database
              ? `Datenbank: ${server.database}`
              : '';
          }
        }
        updateDatabaseCardState();
      }
      
      // Render server list in modal
      function renderServerList() {
        if (remoteServers.length === 0) {
          serverList.innerHTML = '<p style="color: rgba(226, 232, 240, 0.6); text-align: center; padding: 1rem;">Keine Remote-Server konfiguriert</p>';
          return;
        }
        
        let html = '';
        remoteServers.forEach((server, index) => {
          html += `
            <div class="server-list-item">
              <div class="server-list-item-info">
                <div class="server-list-item-name">${escapeHtml(server.name)}</div>
                <div class="server-list-item-url">${escapeHtml(server.url)}</div>
                ${server.database ? `<div style="font-size:0.8rem;margin-top:0.25rem;color:rgba(226,232,240,0.6);">Datenbank: ${escapeHtml(server.database)}</div>` : ''}
              </div>
              <div class="server-list-item-actions">
                <button class="btn-small btn-edit" data-index="${index}">✏️ Bearbeiten</button>
                <button class="btn-small btn-delete" data-index="${index}">🗑️ Löschen</button>
              </div>
            </div>
          `;
        });
        
        serverList.innerHTML = html;
        
        // Attach event listeners
        serverList.querySelectorAll('.btn-edit').forEach(btn => {
          btn.addEventListener('click', (e) => {
            const index = parseInt(btn.dataset.index);
            editServer(index);
          });
        });
        
        serverList.querySelectorAll('.btn-delete').forEach(btn => {
          btn.addEventListener('click', (e) => {
            const index = parseInt(btn.dataset.index);
            deleteServer(index);
          });
        });
      }
      
      // Show/hide server form
      function showServerForm(editing = false, index = -1) {
        editingServerIndex = index;
        serverForm.style.display = 'block';
        btnAddServer.style.display = 'none';
        
        if (editing && index >= 0) {
          formTitle.textContent = 'Server bearbeiten';
          const server = remoteServers[index];
          serverNameInput.value = server.name;
          serverUrlInput.value = server.url;
          serverApiKeyInput.value = server.api_key || '';
          serverDbInput.value = server.database || '';
        } else {
          formTitle.textContent = 'Server hinzufügen';
          serverNameInput.value = '';
          serverUrlInput.value = '';
          serverApiKeyInput.value = '';
          serverDbInput.value = '';
        }
      }
      
      function hideServerForm() {
        serverForm.style.display = 'none';
        btnAddServer.style.display = 'block';
        editingServerIndex = -1;
        serverDbInput.value = '';
      }
      
      // Edit server
      function editServer(index) {
        showServerForm(true, index);
      }
      
      // Delete server
      async function deleteServer(index) {
        if (!confirm(`Server "${remoteServers[index].name}" wirklich löschen?`)) {
          return;
        }
        
        try {
          showLoading(true);
          await fetchJson('remote_servers_manage.php', {
            method: 'DELETE',
            body: JSON.stringify({ index })
          });
          
          await loadRemoteServers();
          renderServerList();
          showStatus('Server erfolgreich gelöscht', 'success');
          
          // If deleted server was selected, switch to local
          if (currentServerIndex === index) {
            currentServerIndex = -1;
            serverSelect.value = 'local';
            updateCurrentServerDisplay();
            await loadSettings();
          } else if (currentServerIndex > index) {
            currentServerIndex--;
          }
        } catch (error) {
          showStatus('Fehler beim Löschen: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }
      
      // Save server (add or update)
      async function saveServer() {
        const name = serverNameInput.value.trim();
        const url = serverUrlInput.value.trim();
        const apiKey = serverApiKeyInput.value.trim();
        const database = serverDbInput.value.trim();
        
        if (!name || !url) {
          showStatus('Name und URL sind erforderlich', 'error');
          return;
        }
        
        try {
          showLoading(true);
          
          const action = editingServerIndex >= 0 ? 'update' : 'add';
          const payload = {
            action,
            server: { name, url, api_key: apiKey, database }
          };
          
          
          if (action === 'update') {
            payload.index = editingServerIndex;
          }
          
          const response = await fetchJson('remote_servers_manage.php', {
            method: 'POST',
            body: JSON.stringify(payload)
          });
          
          await loadRemoteServers();
          renderServerList();
          hideServerForm();
          showStatus(response.data.message, 'success');
          
          // If we added a new server and it has no API key, offer to create .env
          if (action === 'add' && !apiKey) {
            const createEnv = confirm(
              `Server "${name}" wurde hinzugefügt. Möchten Sie automatisch eine .env Datei auf dem Remote-Server mit dem lokalen API-Key erstellen?`
            );
            
            if (createEnv) {
              await createRemoteEnv(remoteServers.length - 1);
            }
          }
        } catch (error) {
          showStatus('Fehler beim Speichern: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }
      
      // Create .env on remote server
      async function createRemoteEnv(serverIndex) {
        try {
          showLoading(true);
          
          // Get local API key first
          const localSettings = await fetchJson('settings_read.php');
          const localApiKey = localSettings.data.settings.DATA_TRANSFER_API_KEY;
          
          if (!localApiKey) {
            throw new Error('Lokaler API-Key nicht gefunden');
          }
          
          await fetchJson('settings_remote.php', {
            method: 'PUT',
            body: JSON.stringify({
              server_index: serverIndex,
              initial_api_key: localApiKey
            })
          });
          
          showStatus('Remote .env erfolgreich erstellt', 'success');
        } catch (error) {
          showStatus('Fehler beim Erstellen der Remote .env: ' + error.message, 'error');
        } finally {
          showLoading(false);
        }
      }
      
      // Handle server selection change
      serverSelect.addEventListener('change', async () => {
        const value = serverSelect.value;
        
        if (value === 'local') {
          currentServerIndex = -1;
        } else if (value.startsWith('remote-')) {
          currentServerIndex = parseInt(value.substring(7), 10);
        }
        
        updateCurrentServerDisplay();
        updateDatabaseCardState();
        await loadSettings();
        await loadDatabases();
      });
      
      // Modal controls
      btnManageServers.addEventListener('click', async () => {
        serverModal.classList.add('visible');
        await loadRemoteServers();
        renderServerList();
        hideServerForm();
      });
      
      modalClose.addEventListener('click', () => {
        serverModal.classList.remove('visible');
      });
      
      serverModal.addEventListener('click', (e) => {
        if (e.target === serverModal) {
          serverModal.classList.remove('visible');
        }
      });
      
      btnAddServer.addEventListener('click', () => {
        showServerForm(false);
      });
      
      btnFormCancel.addEventListener('click', () => {
        hideServerForm();
      });
      
      btnFormSave.addEventListener('click', () => {
        saveServer();
      });

      // Initial load
      async function initializePage() {
        try {
          await loadRemoteServers();
        } catch (error) {
          console.error('Remote-Server konnten nicht geladen werden:', error);
          showStatus('Remote-Server konnten nicht geladen werden: ' + error.message, 'error');
        }
        await loadSettings();
        await loadDatabases();
      }

      initializePage();
    })(window.APP_CONFIG);
  </script>
</body>
</html>
