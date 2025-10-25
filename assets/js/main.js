// Application initialization with configuration
(function(config) {
    'use strict';

    const API_BASE = config.apiBase;
    const DEBUG_TABLES = config.debugTables;

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

    // Initialize app
    (async () => {
      await Promise.all([refreshStatus(), refreshLog(), refreshHealth()]);
      startPolling();
    })();

})(window.APP_CONFIG);
