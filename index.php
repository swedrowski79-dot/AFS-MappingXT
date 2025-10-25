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
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'">
  <style>
    :root {
      color-scheme: dark;
      --bg: #111;
      --surface: #181818;
      --panel: #1f1f1f;
      --panel-soft: rgba(255, 255, 255, 0.04);
      --panel-strong: rgba(255, 255, 255, 0.08);
      --border: #2a2a2a;
      --border-subtle: rgba(255, 255, 255, 0.12);
      --text: #f5f5f5;
      --muted: #b7b7b7;
      --accent: #3ba676;
      --accent-soft: rgba(59, 166, 118, 0.2);
      --error: #c86666;
      --warning: #d0aa63;
      --shadow: 0 32px 80px rgba(0, 0, 0, 0.55);
      font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: linear-gradient(180deg, #151515 0%, #0f0f0f 100%);
      color: var(--text);
      display: flex;
      justify-content: center;
      padding: 48px 20px 72px;
    }

    .shell {
      width: min(1100px, 100%);
      background: var(--surface);
      border-radius: 28px;
      padding: 40px 44px 48px;
      border: 1px solid var(--border);
      box-shadow: var(--shadow);
    }

    header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 24px;
      margin-bottom: 32px;
    }

    header h1 {
      margin: 0;
      font-size: 2.35rem;
      letter-spacing: -0.02em;
    }

    header p {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .tag {
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid var(--border-subtle);
      font-size: 0.85rem;
      color: var(--muted);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--panel-soft);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 24px;
    }

    .card {
      background: var(--panel);
      border-radius: 20px;
      padding: 24px;
      border: 1px solid var(--border);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    }

    .card.logs {
      grid-column: 1 / -1;
    }

    .card h2 {
      margin: 0 0 18px;
      font-size: 1.25rem;
      letter-spacing: -0.01em;
    }

    .status-bar {
      height: 10px;
      width: 100%;
      border-radius: 999px;
      background: var(--panel-soft);
      overflow: hidden;
      margin: 16px 0 12px;
    }

    .status-bar span {
      display: block;
      height: 100%;
      width: 0%;
      background: var(--accent);
      transition: width 0.35s ease;
    }

    .status-meta {
      display: grid;
      gap: 6px;
      font-size: 0.95rem;
      color: var(--muted);
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 0.85rem;
      border: 1px solid var(--border-subtle);
      text-transform: uppercase;
      background: var(--panel-soft);
      color: var(--text);
    }

    .status-pill[data-state="running"] {
      background: var(--panel-strong);
      border-color: var(--border-subtle);
    }

    .status-pill[data-state="idle"] {
      color: var(--muted);
    }

    .status-pill[data-state="ready"],
    .status-pill[data-state="done"] {
      background: var(--accent-soft);
      border-color: rgba(59, 166, 118, 0.45);
      color: #def2e8;
    }

    .status-pill[data-state="error"] {
      background: rgba(200, 102, 102, 0.2);
      border-color: rgba(200, 102, 102, 0.45);
      color: #f4d7d7;
    }

    .stage-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      background: var(--panel-soft);
      border: 1px solid var(--border);
      color: var(--muted);
      margin-top: 6px;
    }

    .health-list {
      display: grid;
      gap: 12px;
      font-size: 0.95rem;
    }

    .health-item {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 14px;
      border-radius: 14px;
      background: var(--panel-soft);
      border: 1px solid var(--border);
    }

    .health-item strong {
      display: block;
      margin-bottom: 4px;
    }

    .health-item span.state {
      font-weight: 600;
    }

    .health-item small {
      display: block;
      font-size: 0.78rem;
      color: var(--muted);
    }

    .health-item[data-status="ok"] {
      border-color: rgba(59, 166, 118, 0.45);
      background: rgba(59, 166, 118, 0.12);
      color: #dbeee4;
    }

    .health-item[data-status="error"] {
      border-color: rgba(200, 102, 102, 0.45);
      background: rgba(200, 102, 102, 0.12);
      color: #f1d8d8;
    }

    .health-item[data-status="warning"] {
      border-color: rgba(208, 170, 99, 0.45);
      background: rgba(208, 170, 99, 0.12);
    }

    .controls {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    button {
      padding: 12px 16px;
      border-radius: 12px;
      font: inherit;
      cursor: pointer;
      color: var(--text);
      background: var(--panel-strong);
      border: 1px solid var(--border);
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border 0.2s ease;
    }

    #btn-start {
      background: var(--accent);
      color: #0c1912;
      border-color: rgba(59, 166, 118, 0.65);
    }

    button:hover:not(:disabled) {
      transform: translateY(-1px);
      box-shadow: 0 16px 30px rgba(0, 0, 0, 0.4);
      border-color: rgba(255, 255, 255, 0.12);
      background: var(--panel-strong);
    }

    #btn-start:hover:not(:disabled) {
      background: #47b57f;
      border-color: rgba(59, 166, 118, 0.8);
    }

    button:disabled {
      cursor: not-allowed;
      opacity: 0.6;
    }

    .btn-secondary {
      background: var(--panel-soft);
    }

    .btn-danger {
      background: rgba(200, 102, 102, 0.18);
      border-color: rgba(200, 102, 102, 0.4);
      color: #f5dada;
    }

    .btn-danger:hover:not(:disabled) {
      background: rgba(200, 102, 102, 0.25);
    }

    select,
    input[type="number"] {
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--panel);
      color: inherit;
      font: inherit;
    }

    .debug-controls {
      display: grid;
      gap: 12px;
      background: var(--panel-soft);
      padding: 16px;
      border-radius: 14px;
      border: 1px solid var(--border);
    }

    .debug-title {
      font-size: 0.95rem;
      color: var(--muted);
    }

    .debug-row {
      display: grid;
      gap: 12px;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      align-items: end;
    }

    .debug-field {
      display: flex;
      flex-direction: column;
      gap: 6px;
      font-size: 0.85rem;
      color: var(--muted);
    }

    .debug-table-btn {
      align-self: end;
      justify-self: start;
    }

    .debug-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .log-list {
      display: grid;
      gap: 12px;
      max-height: 480px;
      min-height: 240px;
      overflow-y: auto;
      padding-right: 4px;
    }

    .log-entry {
      border-radius: 16px;
      padding: 14px 16px;
      background: var(--panel-soft);
      border: 1px solid var(--border);
      display: grid;
      gap: 6px;
    }

    .log-entry.collapsed .log-details {
      display: none;
    }

    .log-entry[data-level="info"] {
      background: var(--accent-soft);
      border-color: rgba(59, 166, 118, 0.45);
      color: #e4f5ec;
    }

    .log-entry[data-level="warning"] {
      background: rgba(208, 170, 99, 0.18);
      border-color: rgba(208, 170, 99, 0.4);
    }

    .log-entry[data-level="error"] {
      background: rgba(200, 102, 102, 0.2);
      border-color: rgba(200, 102, 102, 0.45);
    }

    .log-entry header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin: 0;
    }

    .log-headline {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 600;
    }

    .log-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .log-meta time {
      font-size: 0.78rem;
      color: var(--muted);
    }

    .level-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      background: var(--panel-strong);
      border: 1px solid var(--border);
    }

    .level-pill.info {
      background: rgba(59, 166, 118, 0.22);
      border-color: rgba(59, 166, 118, 0.45);
      color: #e4f5ec;
    }

    .level-pill.warning {
      background: rgba(208, 170, 99, 0.22);
      border-color: rgba(208, 170, 99, 0.45);
      color: #f5e7cc;
    }

    .level-pill.error {
      background: rgba(200, 102, 102, 0.22);
      border-color: rgba(200, 102, 102, 0.45);
      color: #f3d7d7;
    }

    .log-toggle {
      padding: 6px 10px;
      border-radius: 999px;
      background: var(--panel-soft);
      border: 1px solid var(--border);
      color: var(--muted);
      font-size: 0.75rem;
    }

    .log-toggle:hover:not(:disabled) {
      transform: none;
      box-shadow: none;
      background: var(--panel-strong);
      color: var(--text);
    }

    .log-entry pre {
      margin: 0;
      font-size: 0.78rem;
      line-height: 1.5;
      background: #131313;
      padding: 10px 12px;
      border-radius: 10px;
      overflow-x: auto;
      border: 1px solid var(--border);
    }

    .log-empty {
      text-align: center;
      padding: 24px;
      border-radius: 16px;
      border: 1px dashed var(--border-subtle);
      color: var(--muted);
      font-size: 0.95rem;
      background: var(--panel-soft);
    }

    @media (max-width: 768px) {
      body {
        padding: 28px 16px 60px;
      }
      .shell {
        padding: 30px 24px 36px;
      }
      header {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <div class="shell">
    <header>
      <div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Überblick &amp; Steuerung der AFS-Daten-Synchronisation</p>
      </div>
      <div class="tag">API-Basis · <code><?= htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8') ?></code></div>
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
        <div class="health-list">
          <div class="health-item" id="health-evo" data-status="<?= $checks['evo']['ok'] ? 'ok' : 'error' ?>">
            <div>
              <strong><?= htmlspecialchars($checks['evo']['label'], ENT_QUOTES, 'UTF-8') ?></strong>
              <small><?= htmlspecialchars($checks['evo']['path'], ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <span class="state"><?= $checks['evo']['ok'] ? 'OK' : 'Datei fehlt' ?></span>
          </div>
          <div class="health-item" id="health-status" data-status="<?= $checks['status']['ok'] ? 'ok' : 'error' ?>">
            <div>
              <strong><?= htmlspecialchars($checks['status']['label'], ENT_QUOTES, 'UTF-8') ?></strong>
              <small><?= htmlspecialchars($checks['status']['path'], ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <span class="state"><?= $checks['status']['ok'] ? 'OK' : 'Datei fehlt' ?></span>
          </div>
          <div class="health-item" id="health-mssql" data-status="unknown">
            <div>
              <strong>MSSQL</strong>
              <small><?= htmlspecialchars(($config['mssql']['host'] ?? 'undefined') . ':' . ($config['mssql']['port'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <span class="state">?</span>
          </div>
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
    const API_BASE = <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const stateEl = document.getElementById('status-state');
    const stageEl = document.getElementById('status-stage');
    const progressEl = document.getElementById('status-progress');
    const messageEl = document.getElementById('status-message');
    const numbersEl = document.getElementById('status-numbers');
    const durationEl = document.getElementById('status-duration');
    const startedEl = document.getElementById('status-started');
    const updatedEl = document.getElementById('status-updated');
    const btnStart = document.getElementById('btn-start');
    const btnResetEvo = document.getElementById('btn-reset-evo');
    const btnStatusReset = document.getElementById('btn-status-reset');
    const btnRefresh = document.getElementById('btn-refresh');
    const btnClear = document.getElementById('btn-clear');
    const btnDebug = document.getElementById('btn-debug-view');
    const btnSetup = document.getElementById('btn-setup');
    const btnMigrate = document.getElementById('btn-migrate');
    const debugDb = document.getElementById('debug-db');
    const debugTable = document.getElementById('debug-table');
    const debugLimit = document.getElementById('debug-limit');
    const logList = document.getElementById('log-list');
    const logEmpty = document.getElementById('log-empty');
    const logsCount = document.getElementById('logs-count');
    const healthEvo = document.getElementById('health-evo');
    const healthStatus = document.getElementById('health-status');
    const healthMssql = document.getElementById('health-mssql');

    const DEBUG_TABLES = <?= json_encode($debugTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    btnStart.dataset.busy = '0';

    let pollingTimer = null;
    let healthTimer = null;

    function formatDate(value) {
      if (!value) return '–';
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString('de-DE', { hour12: false });
    }

    function formatDuration(seconds) {
      if (seconds === null || seconds === undefined) {
        return '–';
      }
      if (seconds >= 3600) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.round(seconds % 60);
        return `${h}h ${m.toString().padStart(2, '0')}m ${s.toString().padStart(2, '0')}s`;
      }
      if (seconds >= 60) {
        const m = Math.floor(seconds / 60);
        const s = Math.round(seconds % 60);
        return `${m}m ${s.toString().padStart(2, '0')}s`;
      }
      if (seconds >= 1) {
        return `${seconds.toFixed(2)}s`;
      }
      return `${Math.round(seconds * 1000)}ms`;
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

    function setState(state, message) {
      stateEl.dataset.state = state;
      stateEl.textContent = `Status: ${state}`;
      if (message) {
        messageEl.textContent = message;
      }
    }

    function renderStatus(status) {
      const { state = 'idle', stage, message, total = 0, processed = 0 } = status;
      const completeStates = ['done', 'ready'];
      const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : (completeStates.includes(state) ? 100 : 0);
      const displayMessage = message || (state === 'idle' ? 'Noch keine Synchronisation gestartet.' : '');

      setState(state, displayMessage);
      progressEl.style.width = percent + '%';
      numbersEl.textContent = `${processed} / ${total} (${percent}%)`;

      if (completeStates.includes(state) && status.started_at && status.finished_at) {
        const started = new Date(status.started_at).getTime();
        const finished = new Date(status.finished_at).getTime();
        if (!Number.isNaN(started) && !Number.isNaN(finished)) {
          durationEl.textContent = formatDuration((finished - started) / 1000);
        } else {
          durationEl.textContent = '–';
        }
      } else {
        durationEl.textContent = '–';
      }

      startedEl.textContent = formatDate(status.started_at ?? null);
      updatedEl.textContent = formatDate(status.updated_at ?? null);

      if (stage) {
        stageEl.hidden = false;
        const stageLabel = stage.replace(/_/g, ' ');
        stageEl.textContent = `Aktuelle Phase: ${stageLabel}`;
      } else {
        stageEl.hidden = true;
      }

      if (btnStart.dataset.busy !== '1') {
        btnStart.disabled = state === 'running';
      }
      btnStart.title = state === 'running' ? 'Synchronisation läuft bereits' : '';
    }

    function levelBadge(level) {
      const span = document.createElement('span');
      span.className = `level-pill ${level}`;
      span.textContent = level;
      return span;
    }

    function renderLog(entries) {
      logList.innerHTML = '';
      logsCount.textContent = entries.length ? `${entries.length} Einträge` : '';

      if (!entries.length) {
        logEmpty.hidden = false;
        return;
      }

      logEmpty.hidden = true;

      entries.forEach(entry => {
        const div = document.createElement('div');
        const level = (entry.level || 'info').toLowerCase();
        div.className = 'log-entry collapsed';
        div.dataset.level = level;

        const header = document.createElement('header');

        const headline = document.createElement('span');
        headline.className = 'log-headline';
        headline.textContent = entry.message || '';
        header.appendChild(headline);

        const meta = document.createElement('div');
        meta.className = 'log-meta';
        meta.appendChild(levelBadge(level));

        const time = document.createElement('time');
        time.textContent = formatDate(entry.created_at ?? null);
        meta.appendChild(time);

        const details = document.createElement('div');
        details.className = 'log-details';

        let hasDetails = false;

        if (entry.stage) {
          const stage = document.createElement('div');
          stage.style.fontSize = '0.82rem';
          stage.style.opacity = '0.85';
          stage.textContent = `Phase: ${entry.stage}`;
          details.appendChild(stage);
          hasDetails = true;
        }

        if (entry.context) {
          const pre = document.createElement('pre');
          pre.textContent = typeof entry.context === 'string'
            ? entry.context
            : JSON.stringify(entry.context, null, 2);
          details.appendChild(pre);
          hasDetails = true;
        }

        if (hasDetails) {
          const toggleBtn = document.createElement('button');
          toggleBtn.type = 'button';
          toggleBtn.className = 'log-toggle';
          const setExpanded = (expanded) => {
            if (expanded) {
              div.classList.add('expanded');
              div.classList.remove('collapsed');
              toggleBtn.textContent = 'Details verbergen';
            } else {
              div.classList.remove('expanded');
              div.classList.add('collapsed');
              toggleBtn.textContent = 'Details anzeigen';
            }
          };
          toggleBtn.addEventListener('click', () => {
            const expanded = !div.classList.contains('expanded');
            setExpanded(expanded);
          });
          setExpanded(false);
          meta.appendChild(toggleBtn);
        }

        header.appendChild(meta);
        div.appendChild(header);

        if (hasDetails) {
          div.appendChild(details);
        }

        logList.appendChild(div);
      });
    }

    function renderHealth(health) {
      const { sqlite = {}, mssql = {} } = health;
      updateHealthItem(healthEvo, sqlite.evo ?? {});
      updateHealthItem(healthStatus, sqlite.status ?? {});
      updateHealthItem(healthMssql, mssql ?? {});
    }

    function updateHealthItem(element, data) {
      const ok = data.ok === true;
      const status = data.ok === true ? 'ok' : (data.ok === false ? 'error' : 'warning');
      element.dataset.status = status;
      const stateSpan = element.querySelector('.state');
      if (stateSpan) {
        stateSpan.textContent = ok ? 'OK' : (data.message ? data.message : 'Prüfung fehlgeschlagen');
      }
    }

    async function refreshStatus() {
      const payload = await fetchJson('sync_status.php');
      renderStatus(payload.data?.status ?? {});
    }

    async function refreshLog() {
      const payload = await fetchJson('sync_errors.php?limit=100&level=all');
      renderLog(payload.data?.entries ?? []);
    }

    async function refreshHealth() {
      const payload = await fetchJson('sync_health.php');
      renderHealth(payload.data?.health ?? {});
    }

    function startPolling() {
      if (pollingTimer) {
        clearInterval(pollingTimer);
      }
      pollingTimer = setInterval(() => {
        Promise.all([refreshStatus(), refreshLog()]).catch(() => {});
      }, 5000);

      if (healthTimer) {
        clearInterval(healthTimer);
      }
      healthTimer = setInterval(() => {
        refreshHealth().catch(() => {});
      }, 30000);
    }

    btnStart.addEventListener('click', async () => {
      btnStart.dataset.busy = '1';
      btnStart.disabled = true;
      setState('running', 'Synchronisation gestartet...');

      try {
        await fetchJson('sync_start.php', { method: 'POST' });
        await Promise.all([refreshStatus(), refreshLog()]);
      } catch (err) {
        setState('error', err.message);
        await refreshLog();
      } finally {
        btnStart.dataset.busy = '0';
        btnStart.disabled = stateEl.dataset.state === 'running';
      }
    });

    btnRefresh.addEventListener('click', async () => {
      await Promise.all([refreshStatus(), refreshLog(), refreshHealth()]);
    });

    btnDebug.addEventListener('click', () => {
      const table = debugTable.value;
      if (!table) {
        return;
      }
      const rawLimit = (debugLimit.value || '').toLowerCase();
      const limitParam = rawLimit === 'all'
        ? 'all'
        : String(Math.min(Math.max(parseInt(rawLimit, 10) || 100, 1), 1000));
      const db = debugDb ? debugDb.value : 'main';
      const url = `${API_BASE}db_table_view.php?table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limitParam)}&db=${encodeURIComponent(db)}`;
      window.open(url, '_blank', 'noopener');
    });

    if (btnResetEvo) {
      btnResetEvo.addEventListener('click', async () => {
        const confirmed = window.confirm('Soll die EVO-Datenbank wirklich geleert werden? Alle synchronisierten Daten gehen verloren.');
        if (!confirmed) {
          return;
        }

        btnResetEvo.disabled = true;
        setState('running', 'Leere EVO-Datenbank ...');

        try {
          const payload = await fetchJson('db_clear.php', { method: 'POST' });
          const tables = payload.data?.tables ?? {};
          const totalRemoved = Object.values(tables).reduce((sum, value) => {
            return sum + (typeof value === 'number' ? value : 0);
          }, 0);
          setState('idle', `EVO-Datenbank geleert (${totalRemoved} Datensätze entfernt)`);
          await Promise.all([refreshStatus(), refreshLog()]);
        } catch (err) {
          setState('error', err.message);
          await refreshLog();
        } finally {
          btnResetEvo.disabled = false;
        }
      });
    }

    if (btnSetup) {
      btnSetup.addEventListener('click', async () => {
        const confirmed = window.confirm('Datenbanken jetzt initialisieren? (bestehende Tabellen werden bei Bedarf erneut angelegt)');
        if (!confirmed) {
          return;
        }
        btnSetup.disabled = true;
        setState('running', 'Führe Setup-Skript aus ...');
        try {
          const payload = await fetchJson('db_setup.php', { method: 'POST' });
          const databases = payload.data?.databases ?? {};
          const parts = [];
          if (databases && typeof databases === 'object') {
            Object.entries(databases).forEach(([key, info]) => {
              if (!info || typeof info !== 'object') {
                return;
              }
              const status = info.created ? 'neu erstellt' : 'aktualisiert';
              parts.push(`${key}: ${status}`);
            });
          }
          const summary = parts.length ? parts.join(', ') : 'Keine Änderungen erforderlich';
          setState('idle', `Setup abgeschlossen – ${summary}.`);
          await Promise.all([refreshHealth(), refreshStatus(), refreshLog()]);
        } catch (err) {
          setState('error', err.message);
          await refreshLog();
        } finally {
          btnSetup.disabled = false;
        }
      });
    }

    if (btnMigrate) {
      btnMigrate.addEventListener('click', async () => {
        const confirmed = window.confirm('Schema-Migration jetzt ausführen?');
        if (!confirmed) {
          return;
        }
        btnMigrate.disabled = true;
        setState('running', 'Führe Schema-Migration aus ...');
        try {
          const payload = await fetchJson('db_migrate.php', { method: 'POST' });
          const changes = payload.data?.changes ?? {};
          const addedUpdate = Array.isArray(changes.added_update_columns) ? changes.added_update_columns.length : 0;
          const addedMeta = Array.isArray(changes.added_meta_columns) ? changes.added_meta_columns.length : 0;
          const normalized = Boolean(changes.normalized_update_flags);
          const parts = [];
          if (addedUpdate > 0) {
            parts.push(`${addedUpdate} Update-Spalten ergänzt`);
          }
          if (addedMeta > 0) {
            parts.push(`${addedMeta} Meta-Spalten ergänzt`);
          }
          if (normalized) {
            parts.push('Update-Flags normalisiert');
          }
          const summary = parts.length ? parts.join(', ') : 'Keine Änderungen erforderlich';
          setState('idle', `Schema-Migration abgeschlossen – ${summary}.`);
          await Promise.all([refreshStatus(), refreshLog()]);
        } catch (err) {
          setState('error', err.message);
          await refreshLog();
        } finally {
          btnMigrate.disabled = false;
        }
      });
    }

    if (btnStatusReset) {
      btnStatusReset.addEventListener('click', async () => {
        btnStatusReset.disabled = true;
        try {
          await fetchJson('sync_status_reset.php', { method: 'POST' });
          await Promise.all([refreshStatus(), refreshLog()]);
        } catch (err) {
          setState('error', err.message);
          await refreshLog();
        } finally {
          btnStatusReset.disabled = false;
        }
      });
    }

    if (debugDb) {
      debugDb.addEventListener('change', () => {
        const dbKey = debugDb.value || 'main';
        const tables = DEBUG_TABLES[dbKey] || [];
        if (!debugTable) return;
        const current = debugTable.value;
        debugTable.innerHTML = '';
        tables.forEach((name, index) => {
          const option = document.createElement('option');
          option.value = name;
          option.textContent = name;
          if (name === current || (index === 0 && !tables.includes(current))) {
            option.selected = true;
          }
          debugTable.appendChild(option);
        });
      });
    }

    btnClear.addEventListener('click', async () => {
      btnClear.disabled = true;
      try {
        await fetchJson('sync_errors_clear.php', { method: 'POST' });
        await refreshLog();
      } catch (err) {
        setState('error', err.message);
      } finally {
        btnClear.disabled = false;
      }
    });

    (async () => {
      await Promise.all([refreshStatus(), refreshLog(), refreshHealth()]);
      startPolling();
    })();
  </script>
</body>
</html>
