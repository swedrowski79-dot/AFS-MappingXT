<?php
// index.php
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
SecurityValidator::validateAccess($config, 'index.php');

$paths = $config['paths'] ?? [];
$evoPath = $paths['data_db'] ?? (__DIR__ . '/db/evo.db');
$statusPath = $paths['status_db'] ?? (__DIR__ . '/db/status.db');

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl   = $scriptDir === '' ? '/' : $scriptDir . '/';
$apiBase   = $baseUrl . 'api/';

$title = (string)($config['ui']['title'] ?? 'AFS-Schnittstelle');

$checks = [
    'evo' => [
        'label' => 'SQLite · evo.db',
        'path'  => $evoPath,
        'ok'    => is_file($evoPath),
    ],
    'status' => [
        'label' => 'SQLite · status.db',
        'path'  => $statusPath,
        'ok'    => is_file($statusPath),
    ],
];

$maxErrors = (int)($config['status']['max_errors'] ?? 200);
$debugTables = [
    'main' => [
        'Artikel',
        'Artikel_Bilder',
        'Artikel_Dokumente',
        'Attrib_Artikel',
        'Attribute',
        'Bilder',
        'Dokumente',
        'category',
    ],
    'delta' => [
        'Artikel',
        'Artikel_Bilder',
        'Artikel_Dokumente',
        'Attrib_Artikel',
        'Attribute',
        'Bilder',
        'Dokumente',
        'category',
    ],
    'status' => [
        'sync_status',
        'sync_log',
    ],
];

$remoteConfig = $config['remote_servers'] ?? [];
$remoteEnabled = $remoteConfig['enabled'] ?? false;
$remoteServers = $remoteConfig['servers'] ?? [];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <style>
<?php echo file_get_contents(__DIR__ . '/assets/css/main.css'); ?>
  </style>
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Überblick &amp; Steuerung der AFS-Daten-Synchronisation</p>
      </div>
      <div class="tag">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>settings.php" style="color: inherit; text-decoration: none; margin-right: 1rem;">⚙️ Einstellungen</a>
        API-Basis · <code><?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?></code>
      </div>
    </header>

    <div class="grid">
      <section class="card status">
        <h2>Status</h2>
        <div class="status-pill" id="status-state" data-state="idle">Status: idle</div>
        <div class="stage-tag" id="status-stage" hidden></div>
        <div class="status-bar"><span id="status-progress"></span></div>
        <div class="status-meta">
          <div id="status-message">Noch keine Synchronisation gestartet.</div>
          <div>Fortschritt: <span id="status-numbers">0 / 0 (0%)</span></div>
          <div>Laufzeit: <span id="status-duration">–</span></div>
          <div>Gestartet: <span id="status-started">–</span></div>
          <div>Aktualisiert: <span id="status-updated">–</span></div>
        </div>
      </section>

      <section class="card health">
        <h2>Verbindungen</h2>
        <h3 class="health-subtitle">Lokaler Server</h3>
        <div class="health-list" id="database-status-list">
          <div class="health-item" data-status="loading">
            <div>
              <strong>Datenbanken</strong>
              <small>Wird geladen...</small>
            </div>
            <span class="state">...</span>
          </div>
        </div>
        <h3 class="health-subtitle">Remote Server</h3>
        <div class="health-list" id="remote-servers-list">
<?php if (!$remoteEnabled): ?>
          <div class="health-item" data-status="warning">
            <div>
              <strong>Remote-Monitoring deaktiviert</strong>
              <small>In den Einstellungen aktivieren, um Remote-Server zu überwachen.</small>
            </div>
            <span class="state">Hinweis</span>
          </div>
<?php elseif (empty($remoteServers)): ?>
          <div class="health-item" data-status="warning">
            <div>
              <strong>Keine Remote-Server konfiguriert</strong>
              <small>In den Einstellungen hinzufügen, um den Status zu überwachen.</small>
            </div>
            <span class="state">Hinweis</span>
          </div>
<?php endif; ?>
        </div>
      </section>

      <section class="card controls">
        <h2>Aktionen</h2>
        <button id="btn-start">🔁 Synchronisation starten</button>
        <button id="btn-refresh" class="btn-secondary">🔄 Status aktualisieren</button>
        <button id="btn-clear" class="btn-secondary">🧹 Protokoll leeren</button>
        <small style="color: var(--muted);">Maximale Protokollgröße: <?= $maxErrors ?></small>
        <div class="debug-controls">
          <strong class="debug-title">Debugging</strong>
          <div class="debug-row">
            <label class="debug-field">
              <span>Datenbank</span>
              <select id="debug-db">
                <option value="main">Hauptdatenbank</option>
                <option value="delta">Delta-Datenbank</option>
                <option value="status">Status-Datenbank</option>
              </select>
            </label>
            <label class="debug-field">
              <span>Tabelle</span>
              <select id="debug-table">
                <?php foreach ($debugTables['main'] as $tableName): ?>
                  <option value="<?= htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="debug-field">
              <span>Limit</span>
              <select id="debug-limit" title="Anzahl Datensätze">
                <option value="100">100</option>
                <option value="250">250</option>
                <option value="500">500</option>
                <option value="all">Alle</option>
              </select>
            </label>
            <button id="btn-debug-view" class="btn-secondary debug-table-btn">🧾 Tabelle anzeigen</button>
          </div>
          <div class="debug-actions">
            <button id="btn-setup" class="btn-secondary">📦 Datenbanken initialisieren</button>
            <button id="btn-migrate" class="btn-secondary">🛠️ Schema-Migration ausführen</button>
            <button id="btn-reset-evo" class="btn-danger">🗑️ EVO-Datenbank leeren</button>
            <button id="btn-status-reset" class="btn-secondary">♻️ Status zurücksetzen</button>
          </div>
          <small style="color:rgba(226,232,240,0.7);">Werkzeuge für Wartung &amp; Analyse.</small>
        </div>
      </section>

      <section class="card logs">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <h2>Protokoll</h2>
          <small id="logs-count"></small>
        </div>
        <div class="log-list" id="log-list"></div>
        <div class="log-empty" id="log-empty" hidden>Keine Einträge vorhanden.</div>
      </section>
    </div>
  </div>


  <script>
    // Application configuration
    window.APP_CONFIG = {
      apiBase: <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      debugTables: <?= json_encode($debugTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      remoteServersEnabled: <?= json_encode($remoteEnabled, JSON_UNESCAPED_UNICODE) ?>
    };
  </script>
  <script>
<?php
$mainJsPath = __DIR__ . '/assets/js/main.js';
if (is_readable($mainJsPath)) {
    echo file_get_contents($mainJsPath);
} else {
    // Optionally, log the error here
    echo "console.error('main.js konnte nicht geladen werden.');";
}
?>
  </script>
  <script>
    (function(APP) {
      'use strict';

      const apiBase = APP.apiBase || '';
      const remoteEnabled = APP.remoteServersEnabled === true;
      const localList = document.getElementById('database-status-list');
      const remoteList = document.getElementById('remote-servers-list');

      function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
      }

      async function requestJson(url, options = {}) {
        const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
        if (options.body && !Object.keys(headers).some((key) => key.toLowerCase() === 'content-type')) {
          headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, { cache: 'no-store', ...options, headers });
        const text = await response.text();
        let payload = {};
        try {
          payload = text ? JSON.parse(text) : {};
        } catch (error) {
          throw new Error('Antwort konnte nicht gelesen werden.');
        }
        if (!response.ok || payload.ok === false) {
          throw new Error(payload.error || response.statusText || 'Unbekannter Fehler');
        }
        return payload;
      }

      function connectionStatusClass(status) {
        if (status && status.ok === true) {
          return 'ok';
        }
        if (status && status.ok === false) {
          return 'error';
        }
        return 'warning';
      }

      function formatConnectionDetails(connection) {
        const settings = connection?.settings || {};
        if (connection?.type === 'sqlite' && settings.path) {
          return settings.path;
        }
        if (connection?.type === 'mssql' || connection?.type === 'mysql') {
          const host = settings.host || '';
          const port = settings.port ? `:${settings.port}` : '';
          const database = settings.database ? ` · ${settings.database}` : '';
          return `${host}${port}${database}`.trim();
        }
        return settings.path || '';
      }

      function renderLocalConnections(connections) {
        if (!localList) {
          return;
        }
        localList.innerHTML = '';
        if (!connections.length) {
          const item = document.createElement('div');
          item.className = 'health-item';
          item.dataset.status = 'warning';
          item.innerHTML = '<div><strong>Keine Verbindungen</strong><small>Auf diesem Server sind keine Datenbanken konfiguriert.</small></div><span class="state">Hinweis</span>';
          localList.appendChild(item);
          return;
        }

        const fragment = document.createDocumentFragment();
        connections.forEach((connection) => {
          const item = document.createElement('div');
          item.className = 'health-item';
          item.dataset.status = connectionStatusClass(connection.status);

          const info = document.createElement('div');
          const title = document.createElement('strong');
          title.innerHTML = escapeHtml(connection.title || connection.id || 'Unbenannte Verbindung');
          info.appendChild(title);

          const subtitle = document.createElement('small');
          const detail = formatConnectionDetails(connection);
          subtitle.innerHTML = escapeHtml(detail || connection.type || '');
          info.appendChild(subtitle);

          if (Array.isArray(connection.roles) && connection.roles.length) {
            const rolesLine = document.createElement('small');
            rolesLine.innerHTML = escapeHtml('Rollen: ' + connection.roles.join(', '));
            rolesLine.style.display = 'block';
            info.appendChild(rolesLine);
          }

          const state = document.createElement('span');
          state.className = 'state';
          state.textContent = connection.status && connection.status.ok === true ? 'OK' : (connection.status && connection.status.ok === false ? 'Fehler' : 'Unbekannt');

          item.appendChild(info);
          item.appendChild(state);
          fragment.appendChild(item);
        });
        localList.appendChild(fragment);
      }

      function renderRemoteListError(message) {
        if (!remoteList) {
          return;
        }
        remoteList.innerHTML = '';
        const item = document.createElement('div');
        item.className = 'health-item';
        item.dataset.status = 'error';
        item.innerHTML = `<div><strong>Remote-Status fehlgeschlagen</strong><small>${escapeHtml(message)}</small></div><span class="state">Fehler</span>`;
        remoteList.appendChild(item);
      }

      function renderRemoteConnections(container, server, connections) {
        container.innerHTML = '';
        if (!connections.length) {
          const fallback = server?.database ? `Konfigurierte Datenbank: ${server.database}` : 'Keine Verbindungen vorhanden.';
          container.innerHTML = `<small>${escapeHtml(fallback)}</small>`;
          return;
        }
        const fragment = document.createDocumentFragment();
        connections.forEach((connection) => {
          const wrapper = document.createElement('div');
          wrapper.style.display = 'flex';
          wrapper.style.flexDirection = 'column';
          wrapper.style.gap = '0.2rem';

          const chip = document.createElement('span');
          chip.className = 'database-role-pill';
          chip.textContent = connection.title || connection.id || 'Unbenannt';
          wrapper.appendChild(chip);

          const detail = formatConnectionDetails(connection);
          if (detail) {
            const detailLine = document.createElement('small');
            detailLine.style.color = 'rgba(226, 232, 240, 0.7)';
            detailLine.textContent = detail;
            wrapper.appendChild(detailLine);
          }

          fragment.appendChild(wrapper);
        });
        container.appendChild(fragment);
      }

      async function loadLocalConnections() {
        try {
          const payload = await requestJson(`${apiBase}databases_manage.php`);
          const connections = payload?.data?.connections || [];
          renderLocalConnections(connections);
        } catch (error) {
          if (!localList) {
            return;
          }
          localList.innerHTML = `<div class="health-item" data-status="error"><div><strong>Laden fehlgeschlagen</strong><small>${escapeHtml(error.message)}</small></div><span class="state">Fehler</span></div>`;
        }
      }

      async function loadRemoteConnections(item, badge, listContainer, server, index) {
        try {
          const payload = await requestJson(`${apiBase}databases_remote.php?server_index=${index}`);
          const connections = payload?.data?.connections || [];
          item.dataset.status = 'ok';
          badge.textContent = 'API OK';
          renderRemoteConnections(listContainer, server, connections);
        } catch (error) {
          item.dataset.status = 'error';
          badge.textContent = 'API Fehler';
          listContainer.innerHTML = `<small>${escapeHtml(error.message)}</small>`;
        }
      }

      function renderRemoteServer(server, index) {
        const item = document.createElement('div');
        item.className = 'health-item';
        item.dataset.status = 'loading';

        const info = document.createElement('div');

        const title = document.createElement('strong');
        title.innerHTML = escapeHtml(server.name || `Remote-Server ${index + 1}`);
        info.appendChild(title);

        const subtitle = document.createElement('small');
        subtitle.innerHTML = escapeHtml(server.url || '');
        info.appendChild(subtitle);

        const listContainer = document.createElement('div');
        listContainer.style.display = 'flex';
        listContainer.style.flexDirection = 'column';
        listContainer.style.gap = '0.35rem';
        listContainer.style.marginTop = '0.35rem';
        listContainer.innerHTML = '<small>Verbindungen werden geladen...</small>';
        info.appendChild(listContainer);

        const badge = document.createElement('span');
        badge.className = 'state';
        badge.textContent = 'Prüfung';
        item.appendChild(info);
        item.appendChild(badge);

        remoteList.appendChild(item);
        loadRemoteConnections(item, badge, listContainer, server, index);
      }

      async function loadRemoteServers() {
        if (!remoteEnabled || !remoteList) {
          return;
        }
        try {
          const payload = await requestJson(`${apiBase}remote_servers_manage.php`);
          const servers = Array.isArray(payload?.data?.servers) ? payload.data.servers : [];
          remoteList.innerHTML = '';
          if (!servers.length) {
            const item = document.createElement('div');
            item.className = 'health-item';
            item.dataset.status = 'warning';
            item.innerHTML = '<div><strong>Keine Remote-Server konfiguriert</strong><small>Remote-Server können in den Einstellungen gepflegt werden.</small></div><span class="state">Hinweis</span>';
            remoteList.appendChild(item);
            return;
          }
          servers.forEach((server, index) => renderRemoteServer(server, index));
        } catch (error) {
          renderRemoteListError(error.message || 'Remote-Status konnte nicht geladen werden.');
        }
      }

      loadLocalConnections();
      loadRemoteServers();
    })(window.APP_CONFIG || {});
  </script>
</body>
</html>
