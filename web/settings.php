<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap_web.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Einstellungen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <link rel="stylesheet" href="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>css/main.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>css/settings.css">
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Einstellungen</h1>
        <p>Konfiguration der lokalen und Remote-Umgebungsvariablen</p>
      </div>
      <div class="tag">
        <a href="<?= htmlspecialchars($WEB_BASE, ENT_QUOTES, 'UTF-8') ?>" class="back-link">← Zurück zur Übersicht</a>
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
        <div id="settings-container"><p style="color: var(--muted);">Einstellungen werden geladen...</p></div>

        <div class="settings-footer">
          <strong>Hinweis:</strong> Änderungen an den Einstellungen werden in der <code>.env</code> Datei gespeichert.
          Eine Sicherungskopie wird automatisch erstellt.
        </div>
      </section>

      <section class="card" id="databases-card" style="grid-column: 1 / -1;">
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

  <script>
    window.APP_CONFIG = { apiBase: <?= json_encode($API_BASE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> };
  </script>
  <script src="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>js/settings.js"></script>
</body>
</html>
