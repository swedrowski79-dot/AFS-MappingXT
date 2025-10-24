<?php
// index.php
declare(strict_types=1);

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
  <style>
    :root {
      color-scheme: dark light;
      --bg: #0a1120;
      --surface: rgba(13, 23, 42, 0.92);
      --panel: rgba(255, 255, 255, 0.05);
      --border: rgba(148, 163, 184, 0.18);
      --text: #f8fafc;
      --muted: #cbd5f5;
      --accent: #3b82f6;
      --accent-soft: rgba(59, 130, 246, 0.12);
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #f97316;
      font-family: "Inter", system-ui, -apple-system, "Segoe UI", sans-serif;
    }

    body {
      margin: 0;
      min-height: 100vh;
      background: radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.25), transparent 55%),
                  radial-gradient(circle at 80% 10%, rgba(45, 212, 191, 0.18), transparent 45%),
                  radial-gradient(circle at 50% 80%, rgba(249, 115, 22, 0.15), transparent 40%),
                  var(--bg);
      color: var(--text);
      display: flex;
      justify-content: center;
      padding: 48px 20px 72px;
    }

    .shell {
      width: min(1100px, 100%);
      backdrop-filter: blur(18px);
      background: var(--surface);
      border-radius: 28px;
      padding: 40px 44px 48px;
      border: 1px solid var(--border);
      box-shadow: 0 32px 90px rgba(7, 12, 24, 0.55);
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
      color: rgba(248, 250, 252, 0.7);
      font-size: 0.95rem;
    }

    .tag {
      padding: 6px 12px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.35);
      font-size: 0.85rem;
      color: var(--muted);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255, 255, 255, 0.05);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 24px;
    }

    .card {
      background: rgba(12, 20, 37, 0.7);
      border-radius: 20px;
      padding: 24px;
      border: 1px solid var(--border);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
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
      background: rgba(148, 163, 184, 0.18);
      overflow: hidden;
      margin: 16px 0 12px;
    }

    .status-bar span {
      display: block;
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #38bdf8, #6366f1);
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
      border: 1px solid rgba(148, 163, 184, 0.3);
      text-transform: uppercase;
    }

    .status-pill[data-state="running"] {
      background: rgba(56, 189, 248, 0.16);
      border-color: rgba(56, 189, 248, 0.35);
      color: #bae6fd;
    }

    .status-pill[data-state="idle"] {
      background: rgba(148, 163, 184, 0.12);
      color: var(--muted);
    }

    .status-pill[data-state="done"] {
      background: rgba(16, 185, 129, 0.18);
      border-color: rgba(16, 185, 129, 0.35);
      color: #bbf7d0;
    }

    .status-pill[data-state="ready"] {
      background: rgba(16, 185, 129, 0.18);
      border-color: rgba(16, 185, 129, 0.35);
      color: #bbf7d0;
    }

    .status-pill[data-state="error"] {
      background: rgba(249, 115, 22, 0.18);
      border-color: rgba(249, 115, 22, 0.35);
      color: #fed7aa;
    }

    .stage-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      background: rgba(99, 102, 241, 0.18);
      border: 1px solid rgba(99, 102, 241, 0.35);
      color: #ede9fe;
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
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(148, 163, 184, 0.2);
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
      color: rgba(241, 245, 249, 0.65);
    }

    .health-item[data-status="ok"] {
      border-color: rgba(16, 185, 129, 0.4);
      background: rgba(16, 185, 129, 0.12);
    }

    .health-item[data-status="error"] {
      border-color: rgba(249, 115, 22, 0.45);
      background: rgba(249, 115, 22, 0.12);
    }

    .health-item[data-status="warning"] {
      border-color: rgba(245, 158, 11, 0.45);
      background: rgba(245, 158, 11, 0.12);
    }

    .controls {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    button {
      padding: 12px 16px;
      border-radius: 12px;
      border: none;
      font: inherit;
      cursor: pointer;
      color: var(--text);
      background: var(--accent-soft);
      border: 1px solid rgba(59, 130, 246, 0.32);
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    select,
    input[type="number"] {
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      background: rgba(15, 23, 42, 0.6);
      color: inherit;
      font: inherit;
    }

    .debug-controls {
      display: grid;
      gap: 12px;
      background: rgba(15, 23, 42, 0.55);
      padding: 16px;
      border-radius: 14px;
      border: 1px solid rgba(148, 163, 184, 0.2);
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

    button:hover:not(:disabled) {
      transform: translateY(-1px);
      box-shadow: 0 16px 35px rgba(59, 130, 246, 0.25);
      background: rgba(59, 130, 246, 0.2);
    }

    button:disabled {
      cursor: not-allowed;
      opacity: 0.6;
    }

    .btn-secondary {
      background: rgba(148, 163, 184, 0.15);
      border-color: rgba(148, 163, 184, 0.3);
    }

    .btn-danger {
      background: rgba(239, 68, 68, 0.18);
      border-color: rgba(248, 113, 113, 0.45);
      color: #fecaca;
    }

    .btn-danger:hover:not(:disabled) {
      background: rgba(239, 68, 68, 0.24);
      box-shadow: 0 16px 38px rgba(239, 68, 68, 0.25);
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
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(148, 163, 184, 0.2);
      display: grid;
      gap: 6px;
    }
    .log-entry.collapsed .log-details {
      display: none;
    }

    .log-entry[data-level="info"] {
      background: rgba(59, 130, 246, 0.12);
      border-color: rgba(59, 130, 246, 0.3);
    }

    .log-entry[data-level="warning"] {
      background: rgba(245, 158, 11, 0.12);
      border-color: rgba(245, 158, 11, 0.3);
    }

    .log-entry[data-level="error"] {
      background: rgba(249, 115, 22, 0.13);
      border-color: rgba(249, 115, 22, 0.35);
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
      display: block;
    }

    .log-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .log-meta time {
      font-size: 0.78rem;
      color: rgba(241, 245, 249, 0.65);
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
    }

    .level-pill.info {
      background: rgba(59, 130, 246, 0.15);
      color: #bfdbfe;
    }

    .level-pill.warning {
      background: rgba(245, 158, 11, 0.15);
      color: #fde68a;
    }

    .level-pill.error {
      background: rgba(249, 115, 22, 0.18);
      color: #fcd9b6;
    }

    .log-toggle {
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(148, 163, 184, 0.16);
      border: 1px solid rgba(148, 163, 184, 0.32);
      color: rgba(226, 232, 240, 0.85);
      font-size: 0.75rem;
    }

    .log-toggle:hover:not(:disabled) {
      transform: none;
      box-shadow: none;
      background: rgba(148, 163, 184, 0.24);
    }

    .log-entry pre {
      margin: 0;
      font-size: 0.78rem;
      line-height: 1.5;
      background: rgba(15, 23, 42, 0.5);
      padding: 10px 12px;
      border-radius: 10px;
      overflow-x: auto;
    }

    .log-empty {
      text-align: center;
      padding: 24px;
      border-radius: 16px;
      border: 1px dashed rgba(148, 163, 184, 0.35);
      color: var(--muted);
      font-size: 0.95rem;
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
