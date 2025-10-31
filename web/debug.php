<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap_web.php';

$debugTables = [
  'main' => $config['debug_tables']['main'] ?? ['Artikel','Bilder','Dokumente','Attribute','category'],
  'delta' => $config['debug_tables']['delta'] ?? [],
  'status' => $config['debug_tables']['status'] ?? ['sync_status','sync_log'],
];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Debugging</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <link rel="stylesheet" href="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>css/main.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>css/settings.css">
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> · Debugging</h1>
        <p>Tabellen anzeigen (lokal und remote)</p>
      </div>
      <div class="tag">
        <a href="<?= htmlspecialchars($WEB_BASE, ENT_QUOTES, 'UTF-8') ?>" class="back-link">← Zurück</a>
      </div>
    </header>

    <section class="card" style="grid-column: 1 / -1;">
      <div class="settings-header"><h2>Tabellenansicht</h2></div>
      <div class="server-selector-section">
        <div class="server-selector-header">
          <div class="server-selector-label">🖥️ Server</div>
          <div class="server-selector-controls">
            <select id="dbg-server" class="server-select"><option value="local">Lokaler Server</option></select>
          </div>
        </div>
      </div>

      <div class="settings-grid" style="gap: 1rem;">
        <label class="setting-label">Datenbank</label>
        <select id="dbg-db" class="form-input" style="max-width: 36rem;"></select>
        <label class="setting-label">Tabelle</label>
        <select id="dbg-table" class="form-input" style="max-width: 20rem;"></select>
        <label class="setting-label">Limit</label>
        <select id="dbg-limit" class="form-input" style="max-width: 10rem;">
          <option value="100">100</option>
          <option value="250">250</option>
          <option value="500">500</option>
          <option value="all">Alle</option>
        </select>
        <button id="dbg-view" class="btn-primary" style="margin-left: 1rem;">🔎 Tabelle anzeigen</button>
      </div>

      <div id="debug-status" class="status-message" style="margin-top:1rem;"></div>
      <div id="debug-result" style="margin-top:1rem;">
        <iframe id="dbg-frame" title="Debug Ergebnis" style="width:100%;min-height:65vh;border:1px solid rgba(148,163,184,0.25);border-radius:12px;background:rgba(15,23,42,0.3)"></iframe>
      </div>
    </section>
  </div>

  <script>
    window.APP_CONFIG = {
      apiBase: <?= json_encode($API_BASE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      debugTables: <?= json_encode($debugTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>js/debug.js"></script>
</body>
</html>
