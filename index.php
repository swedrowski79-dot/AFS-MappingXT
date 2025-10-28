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
$envExists = is_file(__DIR__ . '/.env');
$envExampleExists = is_file(__DIR__ . '/.env.example');
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
            <button type="button" class="btn-secondary" onclick="location.href='<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>debug.php'">🔎 Debugging öffnen</button>
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
      debugTables: <?= json_encode($debugTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
  </script>
  <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>assets/js/main.js"></script>
  <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>assets/js/connections.js"></script>
</body>
</html>
