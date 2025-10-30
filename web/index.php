<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap_web.php';

$paths = $config['paths'] ?? [];
$evoPath = $paths['data_db'] ?? ($rootDir . '/db/evo.db');
$statusPath = $paths['status_db'] ?? ($rootDir . '/db/status.db');

$checks = [
    'evo' => [ 'label' => 'SQLite · evo.db',    'path' => $evoPath,    'ok' => is_file($evoPath) ],
    'status' => [ 'label' => 'SQLite · status.db', 'path' => $statusPath, 'ok' => is_file($statusPath) ],
];
$maxErrors = (int)($config['status']['max_errors'] ?? 200);
$debugTables = [
    'main' => ['Artikel','Artikel_Bilder','Artikel_Dokumente','Attrib_Artikel','Attribute','Bilder','Dokumente','category'],
    'delta' => ['Artikel','Artikel_Bilder','Artikel_Dokumente','Attrib_Artikel','Attribute','Bilder','Dokumente','category'],
    'status' => ['sync_status','sync_log'],
];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <link rel="stylesheet" href="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>css/main.css">
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Überblick &amp; Steuerung der AFS-Daten-Synchronisation</p>
      </div>
      <div class="tag">
        <a href="<?= htmlspecialchars($ROOT_BASE, ENT_QUOTES, 'UTF-8') ?>web/settings.php" class="back-link">⚙️ Einstellungen</a>
        API-Basis · <code><?= htmlspecialchars($API_BASE, ENT_QUOTES, 'UTF-8') ?></code>
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
        <div class="health-list" id="remote-servers-list"></div>
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
            <button type="button" class="btn-secondary" onclick="location.href='<?= htmlspecialchars($ROOT_BASE, ENT_QUOTES, 'UTF-8') ?>web/debug.php'">🔎 Debugging öffnen</button>
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
    window.APP_CONFIG = {
      apiBase: <?= json_encode($API_BASE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      debugTables: <?= json_encode($debugTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      remoteServersEnabled: <?= ($config['remote_servers']['enabled'] ?? false) ? 'true' : 'false' ?>
    };
  </script>
  <script src="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>js/main.js"></script>
  <script src="<?= htmlspecialchars($ASSET_BASE, ENT_QUOTES, 'UTF-8') ?>js/connections.js"></script>
</body>
</html>
