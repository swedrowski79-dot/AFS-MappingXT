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
    
    .setting-row {
      display: grid;
      grid-template-columns: 1fr 2fr;
      gap: 1rem;
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
          html += `<div class="category-header">${escapeHtml(categoryData.label)}</div>`;

          for (const key of categoryData.keys) {
            const value = settings[key] || '';
            const isPassword = key.includes('PASS') || key.includes('KEY');
            const inputType = isPassword ? 'password' : 'text';

            html += `<div class="setting-row">`;
            html += `<label class="setting-label" for="setting-${key}">${escapeHtml(key)}</label>`;
            html += `<input type="${inputType}" id="setting-${key}" class="setting-input" 
                            data-key="${escapeHtml(key)}" value="${escapeHtml(value)}" 
                            placeholder="(leer)">`;
            html += `</div>`;
          }

          html += `</div>`;
        }

        settingsContainer.innerHTML = html;
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
          const response = await fetchJson('settings_read.php');
          const data = response.data;

          if (!data.env_file_exists) {
            showStatus('Warnung: .env Datei wurde nicht gefunden', 'error');
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

      async function saveSettings() {
        const updated = getUpdatedSettings();

        if (Object.keys(updated).length === 0) {
          showStatus('Keine Änderungen vorhanden', 'error');
          return;
        }

        try {
          showLoading(true);
          const response = await fetchJson('settings_write.php', {
            method: 'POST',
            body: JSON.stringify({ settings: updated })
          });

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

      // Initial load
      loadSettings();

    })(window.APP_CONFIG);
  </script>
</body>
</html>
