(() => {
  'use strict';

  const config = window.APP_CONFIG || {};
  const API_BASE = config.apiBase || '';
  const DEBUG_TABLES = config.debugTables || {};

  const serverSelect = document.getElementById('dbg-server');
  const dbSelect = document.getElementById('dbg-db');
  const tableSelect = document.getElementById('dbg-table');
  const limitSelect = document.getElementById('dbg-limit');
  const btnView = document.getElementById('dbg-view');
  const statusBox = document.getElementById('debug-status');
  let remoteServers = [];
  const connMap = {}; // id -> { type }

  function setStatus(msg, type = 'info') {
    if (!statusBox) return;
    statusBox.textContent = msg || '';
    statusBox.className = 'status-message visible ' + type;
    if (msg) setTimeout(() => statusBox.classList.remove('visible'), 5000);
  }

  async function fetchJson(endpoint, options = {}) {
    const res = await fetch(API_BASE + endpoint, {
      cache: 'no-store',
      ...options,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) }
    });
    const payload = await res.json().catch(() => ({}));
    if (!res.ok || payload.ok === false) {
      throw new Error(payload.error || res.statusText || 'Unbekannter Fehler');
    }
    return payload;
  }

  async function loadServers() {
    try {
      const payload = await fetchJson('remote_servers_manage.php');
      remoteServers = (payload && payload.data && Array.isArray(payload.data.servers)) ? payload.data.servers : [];
      remoteServers.forEach((srv, idx) => {
        const opt = document.createElement('option');
        opt.value = 'remote-' + idx;
        opt.textContent = srv.name || ('Remote ' + (idx + 1));
        serverSelect.appendChild(opt);
      });
    } catch (e) {
      remoteServers = [];
    }
  }

  async function rebuildTables() {
    tableSelect.innerHTML = '';
    const sel = serverSelect.value || 'local';
    const dbValue = dbSelect.value || '';
    try {
      let payload;
      if (dbValue.startsWith('id:')) {
        const id = dbValue.substring(3);
        if (sel === 'local') {
          payload = await fetchJson('db_list_tables_server.php?conn_id=' + encodeURIComponent(id));
        } else {
          const idx = parseInt(sel.substring(7), 10) || 0;
          try {
            payload = await fetchJson('db_list_tables_server_remote.php?server_index=' + idx + '&conn_id=' + encodeURIComponent(id));
          } catch (e) {
            // Fallback for older remote: if SQLite, try via sqlite_path
            const info = connMap[id] || {};
            if ((info.type || '') === 'sqlite' && info.settings && info.settings.path) {
              payload = await fetchJson('db_list_tables_remote.php?server_index=' + idx + '&sqlite_path=' + encodeURIComponent(info.settings.path));
            } else {
              throw e;
            }
          }
        }
      } else if (dbValue.startsWith('path:')) {
        const path = dbValue.substring(5);
        if (sel === 'local') {
          payload = await fetchJson('db_list_tables.php?sqlite_path=' + encodeURIComponent(path));
        } else {
          const idx = parseInt(sel.substring(7), 10) || 0;
          payload = await fetchJson('db_list_tables_remote.php?server_index=' + idx + '&sqlite_path=' + encodeURIComponent(path));
        }
      } else {
        // Fallback: nothing selected
        payload = { data: { tables: [] } };
      }
      const list = (payload && payload.data && Array.isArray(payload.data.tables)) ? payload.data.tables : [];
      if (list.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '(keine Tabellen)';
        tableSelect.appendChild(opt);
        return;
      }
      list.forEach((name, i) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        if (i === 0) opt.selected = true;
        tableSelect.appendChild(opt);
      });
    } catch (e) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Fehler beim Laden';
      tableSelect.appendChild(opt);
    }
  }

  function openView() {
    const db = dbSelect.value || '';
    const table = tableSelect.value || '';
    const rawLimit = (limitSelect.value || '').toLowerCase();
    const limit = rawLimit === 'all' ? 'all' : String(Math.min(Math.max(parseInt(rawLimit, 10) || 100, 1), 1000));
    if (!table) { setStatus('Bitte Tabelle wählen', 'error'); return; }
    const sel = serverSelect.value || 'local';
    const isPath = db.startsWith('path:');
    const isConn = db.startsWith('id:');

    let url = '';
    if (isConn) {
      const id = db.substring(3);
      const info = connMap[id] || {};
      if ((info.type || '') === 'file') {
        url = sel === 'local'
          ? `${API_BASE}db_table_view_file.php?conn_id=${encodeURIComponent(id)}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`
          : `${API_BASE}db_table_view_file_remote.php?server_index=${parseInt(serverSelect.value.substring(7), 10) || 0}&conn_id=${encodeURIComponent(id)}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`;
      } else if ((info.type || '') === 'sqlite' || (info.type || '') === 'mysql' || (info.type || '') === 'mssql') {
        if (sel === 'local') {
          url = `${API_BASE}db_table_view_server.php?conn_id=${encodeURIComponent(id)}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`;
        } else {
          const idx = parseInt(serverSelect.value.substring(7), 10) || 0;
          // Try new endpoint, fallback to legacy for sqlite
          url = `${API_BASE}db_table_view_server_remote.php?server_index=${idx}&conn_id=${encodeURIComponent(id)}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`;
          if ((info.type || '') === 'sqlite' && connMap[id] && connMap[id].settings && connMap[id].settings.path) {
            // Keep URL; fallback will be handled if frame load fails is non-trivial, so prefer server endpoint
          }
        }
      } else {
        setStatus('Tabellenansicht für diesen Verbindungstyp (noch) nicht unterstützt.', 'error');
        return;
      }
    } else if (isPath) {
      url = sel === 'local'
        ? `${API_BASE}db_table_view_sqlite.php?path=${encodeURIComponent(db.substring(5))}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`
        : `${API_BASE}db_table_view_sqlite_remote.php?server_index=${parseInt(serverSelect.value.substring(7), 10) || 0}&path=${encodeURIComponent(db.substring(5))}&table=${encodeURIComponent(table)}&limit=${encodeURIComponent(limit)}`;
    } else {
      // Fallback: unsupported
      setStatus('Bitte eine gültige Verbindung wählen.', 'error');
      return;
    }

    const frame = document.getElementById('dbg-frame');
    if (frame) {
      frame.src = url;
      frame.onload = () => frame.contentWindow && frame.contentWindow.scrollTo(0,0);
      frame.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      window.open(url, '_blank', 'noopener');
    }
  }

  if (dbSelect) dbSelect.addEventListener('change', rebuildTables);
  if (serverSelect) serverSelect.addEventListener('change', async () => {
    await populateDbConnections();
    await rebuildTables();
  });
  if (btnView) btnView.addEventListener('click', openView);

  async function populateDbConnections() {
    // Fill dbSelect with ALL connections that are online
    const sel = serverSelect.value || 'local';
    dbSelect.innerHTML = '';
    const addOption = (value, label) => { const o=document.createElement('option'); o.value=value; o.textContent=label; dbSelect.appendChild(o); };
    try {
      let payload;
      if (sel === 'local') {
        payload = await fetchJson('databases_manage.php');
      } else {
        const idx = parseInt(sel.substring(7), 10) || 0;
        payload = await fetchJson('databases_remote.php?server_index=' + idx);
      }
      const connections = (payload && payload.data && Array.isArray(payload.data.connections)) ? payload.data.connections : [];
      const online = connections.filter(c => c && c.status && c.status.ok === true);
      if (online.length === 0) {
        addOption('', '(keine Verbindungen online)');
        return;
      }
      online.forEach(c => {
        connMap[c.id] = { type: c.type, settings: c.settings || {} };
        const type = (c.type || '').toUpperCase();
        let label = (c.title || c.id || 'Verbindung') + ' · ' + type;
        if (c.type === 'sqlite' && c.settings && c.settings.path) {
          label += ' · ' + c.settings.path;
        } else if ((c.type === 'mssql' || c.type === 'mysql') && c.settings) {
          const host = c.settings.host || ''; const dbn = c.settings.database || '';
          if (host || dbn) label += ' · ' + [host, dbn].filter(Boolean).join(' · ');
        } else if ((c.type === 'file' || c.type === 'filedb') && c.settings && c.settings.path) {
          label += ' · ' + c.settings.path;
        }
        addOption('id:' + c.id, label);
      });
    } catch (e) {
      addOption('', 'Fehler beim Laden');
    }
  }

  // init
  (async () => {
    await loadServers();
    await populateDbConnections();
    await rebuildTables();
  })();
})();
