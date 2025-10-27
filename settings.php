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
        
        <div class="form-actions">
          <button id="btn-form-cancel" class="btn-secondary">Abbrechen</button>
          <button id="btn-form-save" class="btn-primary">Speichern</button>
        </div>
      </div>
      
      <button id="btn-add-server" class="btn-primary" style="width: 100%; margin-top: 1rem;">+ Neuen Server hinzufügen</button>
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
            const value = settings[key] || '';
            // More specific password field detection
            const isPassword = key.endsWith('_PASS') || key.endsWith('_PASSWORD') || 
                              key === 'DATA_TRANSFER_API_KEY' || key === 'AFS_MSSQL_PASS' || 
                              key === 'XT_MYSQL_PASS';
            const inputType = isPassword ? 'password' : 'text';
            const isApiKey = key === 'DATA_TRANSFER_API_KEY';

            html += `<div class="setting-row">`;
            html += `<label class="setting-label" for="setting-${key}">${escapeHtml(key)}</label>`;
            html += `<input type="${inputType}" id="setting-${key}" class="setting-input" 
                            data-key="${escapeHtml(key)}" value="${escapeHtml(value)}" 
                            placeholder="(leer)">`;
            
            // Add generate button for API key field
            if (isApiKey) {
              html += `<button class="btn-generate" data-target="setting-${key}" title="Neuen API-Key generieren">🔑 Generieren</button>`;
            } else {
              html += `<div></div>`; // Empty div for grid layout
            }
            
            html += `</div>`;
          }

          html += `</div>`;
        }

        settingsContainer.innerHTML = html;
        
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

      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }

      function getUpdatedSettings() {
        const inputs = settingsContainer.querySelectorAll('.setting-input');
        const updated = {};

        inputs.forEach(input => {
          const key = input.dataset.key;
          const value = input.value;
          
          // Only include changed values
          if (value !== (currentSettings[key] || '')) {
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
          showStatus('Fehler beim Laden der Einstellungen: ' + error.message, 'error');
          settingsContainer.innerHTML = '<p style="color: var(--error);">Fehler beim Laden der Einstellungen.</p>';
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

      // Event listeners
      btnSave.addEventListener('click', () => saveSettings());
      btnReload.addEventListener('click', () => loadSettings());

      // =========================================================================
      // Server Management
      // =========================================================================
      
      const serverSelect = document.getElementById('server-select');
      const btnManageServers = document.getElementById('btn-manage-servers');
      const serverModal = document.getElementById('server-modal');
      const modalClose = document.getElementById('modal-close');
      const serverList = document.getElementById('server-list');
      const serverForm = document.getElementById('server-form');
      const btnAddServer = document.getElementById('btn-add-server');
      const btnFormCancel = document.getElementById('btn-form-cancel');
      const btnFormSave = document.getElementById('btn-form-save');
      const serverNameInput = document.getElementById('server-name');
      const serverUrlInput = document.getElementById('server-url');
      const serverApiKeyInput = document.getElementById('server-api-key');
      const formTitle = document.getElementById('form-title');
      const currentServerName = document.getElementById('current-server-name');
      const currentServerBadge = document.getElementById('current-server-badge');
      
      let remoteServers = [];
      let editingServerIndex = -1;
      let currentServerIndex = -1; // -1 = local, 0+ = remote server index
      
      // Load remote servers
      async function loadRemoteServers() {
        try {
          const response = await fetchJson('remote_servers_manage.php');
          remoteServers = response.data.servers || [];
          updateServerSelect();
          return remoteServers;
        } catch (error) {
          console.error('Error loading remote servers:', error);
          remoteServers = [];
          return [];
        }
      }
      
      // Update server select dropdown
      function updateServerSelect() {
        const currentValue = serverSelect.value;
        serverSelect.innerHTML = '<option value="local">Lokaler Server</option>';
        
        remoteServers.forEach((server, index) => {
          const option = document.createElement('option');
          option.value = 'remote-' + index;
          option.textContent = server.name;
          serverSelect.appendChild(option);
        });
        
        // Restore selection if possible
        if (currentValue && document.querySelector(`option[value="${currentValue}"]`)) {
          serverSelect.value = currentValue;
        }
      }
      
      // Update current server display
      function updateCurrentServerDisplay() {
        if (currentServerIndex === -1) {
          currentServerName.textContent = 'Lokaler Server';
          currentServerBadge.textContent = 'LOKAL';
          currentServerBadge.className = 'server-badge local';
        } else {
          const server = remoteServers[currentServerIndex];
          currentServerName.textContent = server ? server.name : 'Unbekannt';
          currentServerBadge.textContent = 'REMOTE';
          currentServerBadge.className = 'server-badge remote';
        }
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
        } else {
          formTitle.textContent = 'Server hinzufügen';
          serverNameInput.value = '';
          serverUrlInput.value = '';
          serverApiKeyInput.value = '';
        }
      }
      
      function hideServerForm() {
        serverForm.style.display = 'none';
        btnAddServer.style.display = 'block';
        editingServerIndex = -1;
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
        
        if (!name || !url) {
          showStatus('Name und URL sind erforderlich', 'error');
          return;
        }
        
        try {
          showLoading(true);
          
          const action = editingServerIndex >= 0 ? 'update' : 'add';
          const payload = {
            action,
            server: { name, url, api_key: apiKey }
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
          currentServerIndex = parseInt(value.substring(7));
        }
        
        updateCurrentServerDisplay();
        await loadSettings();
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
      loadRemoteServers().then(() => {
        loadSettings();
      });

    })(window.APP_CONFIG);
  </script>
</body>
</html>
