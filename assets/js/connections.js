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
    const settings = (connection && connection.settings) ? connection.settings : {};
    if (connection && connection.type === 'sqlite' && settings.path) {
      return settings.path;
    }
    if (connection && (connection.type === 'mssql' || connection.type === 'mysql')) {
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
      const hasDb = (server && server.database);
      const fallback = hasDb ? `Konfigurierte Datenbank: ${server.database}` : 'Keine Verbindungen vorhanden.';
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

      // Build header row with title chip and status badge
      const headerRow = document.createElement('div');
      headerRow.style.display = 'flex';
      headerRow.style.alignItems = 'center';
      headerRow.style.gap = '0.4rem';
      headerRow.appendChild(chip);

      const state = document.createElement('span');
      state.className = 'state';
      const st = (connection && connection.status) ? connection.status : null;
      state.textContent = st && st.ok === true ? 'OK' : (st && st.ok === false ? 'Fehler' : 'Unbekannt');
      headerRow.appendChild(state);
      wrapper.appendChild(headerRow);

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
      const data = (payload && payload.data) ? payload.data : {};
      const connections = data.connections || [];
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
      const data = (payload && payload.data) ? payload.data : {};
      const connections = data.connections || [];
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
    // Defensive: ensure index is integer in case of string keys
    const safeIndex = Number.isInteger(index) ? index : parseInt(String(index), 10) || 0;
    loadRemoteConnections(item, badge, listContainer, server, safeIndex);
  }

  async function loadRemoteServers() {
    if (!remoteList) {
      return;
    }
    // Show loading placeholder
    remoteList.innerHTML = '<div class="health-item" data-status="warning"><div><strong>Remote</strong><small>Lade Serverliste...</small></div><span class="state">...</span></div>';
    try {
      const payload = await requestJson(`${apiBase}remote_servers_manage.php`);
      const data = (payload && payload.data) ? payload.data : {};
      const servers = Array.isArray(data.servers) ? data.servers : [];
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

  async function refreshAll() {
    await Promise.all([loadLocalConnections(), loadRemoteServers()]);
  }

  // Expose manual refresh hook
  window.CONNECTIONS_REFRESH = refreshAll;

  // Initial load
  refreshAll();
})(window.APP_CONFIG || {});
