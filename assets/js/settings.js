(() => {
  'use strict';

  const config = window.APP_CONFIG || {};
  const API_BASE = config.apiBase || '';

  const settingsContainer = document.getElementById('settings-container');
  if (!settingsContainer) {
    return;
  }

  const statusMessage = document.getElementById('status-message');
  const btnSave = document.getElementById('btn-save');
  const btnReload = document.getElementById('btn-reload');
  const loadingOverlay = document.getElementById('loading-overlay');

  const serverSelect = document.getElementById('server-select');
  const btnManageServers = document.getElementById('btn-manage-servers');
  const currentServerName = document.getElementById('current-server-name');
  const currentServerBadge = document.getElementById('current-server-badge');
  const currentServerDatabase = document.getElementById('current-server-database');

  const dbList = document.getElementById('database-list');
  const dbEmptyState = document.getElementById('databases-empty');
  const btnDbAdd = document.getElementById('btn-db-add');
  const btnDbRefresh = document.getElementById('btn-db-refresh');

  const dbModal = document.getElementById('database-modal');
  const dbModalTitle = document.getElementById('db-modal-title');
  const dbModalClose = document.getElementById('db-modal-close');
  const dbTitleInput = document.getElementById('db-title');
  const dbTypeSelect = document.getElementById('db-type');
  const dbRolesContainer = document.getElementById('db-form-roles');
  const dbFieldsContainer = document.getElementById('db-form-fields');
  const dbStatusBox = document.getElementById('db-form-status');
  const dbBtnTest = document.getElementById('db-btn-test');
  const dbBtnSave = document.getElementById('db-btn-save');
  const dbBtnCancel = document.getElementById('db-btn-cancel');

  const serverModal = document.getElementById('server-modal');
  const serverList = document.getElementById('server-list');
  const serverForm = document.getElementById('server-form');
  const formTitle = document.getElementById('form-title');
  const btnAddServer = document.getElementById('btn-add-server');
  const modalClose = document.getElementById('modal-close');
  const btnFormCancel = document.getElementById('btn-form-cancel');
  const btnFormSave = document.getElementById('btn-form-save');
  const serverNameInput = document.getElementById('server-name');
  const serverUrlInput = document.getElementById('server-url');
  const serverApiKeyInput = document.getElementById('server-api-key');
  const serverDbInput = document.getElementById('server-database');

  let currentSettings = {};
  let categories = {};
  let currentServerIndex = -1;
  let remoteServers = [];
  let editingServerIndex = -1;
  let databaseConnections = [];
  let databaseRoles = {};
  let databaseTypes = {};
  let editingDatabase = null;

  const BOOLEAN_KEYS = new Set([
    'AFS_SECURITY_ENABLED',
    'AFS_ENABLE_FILE_LOGGING',
    'AFS_GITHUB_AUTO_UPDATE',
    'DATA_TRANSFER_ENABLE_DB',
    'DATA_TRANSFER_ENABLE_IMAGES',
    'DATA_TRANSFER_ENABLE_DOCUMENTS',
    'DATA_TRANSFER_LOG_TRANSFERS',
    'REMOTE_SERVERS_ENABLED',
    'SYNC_BIDIRECTIONAL'
  ]);

  const SELECT_OPTIONS = {
    PHP_MEMORY_LIMIT: [
      { value: '128M', label: '128 MB' },
      { value: '256M', label: '256 MB' },
      { value: '512M', label: '512 MB' },
      { value: '1G', label: '1 GB' },
      { value: '2G', label: '2 GB' }
    ],
    PHP_MAX_EXECUTION_TIME: [
      { value: '60', label: '60 Sekunden' },
      { value: '120', label: '2 Minuten' },
      { value: '300', label: '5 Minuten' },
      { value: '600', label: '10 Minuten' },
      { value: '1200', label: '20 Minuten' }
    ],
    TZ: [
      { value: 'Europe/Berlin', label: 'Europe/Berlin (Empfohlen)' },
      { value: 'UTC', label: 'UTC' },
      { value: 'Europe/Zurich', label: 'Europe/Zurich' },
      { value: 'America/New_York', label: 'America/New_York' },
      { value: 'Asia/Dubai', label: 'Asia/Dubai' }
    ],
    OPCACHE_MEMORY_CONSUMPTION: [
      { value: '128', label: '128 MB' },
      { value: '256', label: '256 MB' },
      { value: '512', label: '512 MB' }
    ],
    OPCACHE_INTERNED_STRINGS_BUFFER: [
      { value: '8', label: '8 MB' },
      { value: '16', label: '16 MB' },
      { value: '32', label: '32 MB' }
    ],
    OPCACHE_MAX_ACCELERATED_FILES: [
      { value: '4000', label: '4.000 Dateien' },
      { value: '10000', label: '10.000 Dateien' },
      { value: '20000', label: '20.000 Dateien' }
    ],
    OPCACHE_REVALIDATE_FREQ: [
      { value: '0', label: '0 Sekunden (Entwicklung)' },
      { value: '2', label: '2 Sekunden' },
      { value: '60', label: '60 Sekunden' },
      { value: '120', label: '120 Sekunden' },
      { value: '300', label: '5 Minuten' }
    ],
    OPCACHE_VALIDATE_TIMESTAMPS: [
      { value: '0', label: '0 (keine Prüfung)' },
      { value: '1', label: '1 (prüfen)' }
    ],
    OPCACHE_HUGE_CODE_PAGES: [
      { value: '0', label: 'Deaktiviert' },
      { value: '1', label: 'Aktiviert' }
    ],
    OPCACHE_JIT_MODE: [
      { value: 'disable', label: 'disable (0)' },
      { value: 'tracing', label: 'tracing (Empfohlen)' },
      { value: 'function', label: 'function' }
    ],
    OPCACHE_JIT_BUFFER_SIZE: [
      { value: '0', label: '0 (deaktiviert)' },
      { value: '64M', label: '64 MB' },
      { value: '128M', label: '128 MB' },
      { value: '256M', label: '256 MB' }
    ]
  };

  function canonicalType(value) {
    if (!value) return '';
    const v = String(value).trim().toLowerCase();
    if (DB_TYPE_FIELDS[v]) return v;
    if (v.includes('mysql') || v.includes('maria')) return 'mysql';
    if (v.includes('mssql') || v.includes('sql server') || v.includes('sqlserver')) return 'mssql';
    if (v.includes('sqlite')) return 'sqlite';
    if (v.includes('filedb')) return 'filedb';
    if (v.includes('file')) return 'file';
    return v;
  }

  const DB_TYPE_FIELDS = {
    mssql: [
      { key: 'host', label: 'Host *', type: 'text', placeholder: 'z.B. 10.0.1.82', required: true },
      { key: 'port', label: 'Port', type: 'number', placeholder: '1433', required: false, default: 1433 },
      { key: 'database', label: 'Datenbank *', type: 'text', placeholder: 'z.B. AFS_2018', required: true },
      { key: 'username', label: 'Benutzer *', type: 'text', placeholder: 'z.B. sa', required: true },
      { key: 'password', label: 'Passwort', type: 'password', placeholder: 'Leer lassen für unverändert', required: false },
      { key: 'encrypt', label: 'TLS-Verschlüsselung aktivieren', type: 'checkbox', default: true },
      { key: 'trust_server_certificate', label: 'Serverzertifikat vertrauen (DEV)', type: 'checkbox', default: false }
    ],
    mysql: [
      { key: 'host', label: 'Host *', type: 'text', placeholder: 'z.B. localhost', required: true },
      { key: 'port', label: 'Port', type: 'number', placeholder: '3306', required: false, default: 3306 },
      { key: 'database', label: 'Datenbank *', type: 'text', placeholder: 'z.B. xtcommerce', required: true },
      { key: 'username', label: 'Benutzer *', type: 'text', placeholder: 'z.B. xt_user', required: true },
      { key: 'password', label: 'Passwort', type: 'password', placeholder: 'Leer lassen für unverändert', required: false }
    ],
    sqlite: [
      { key: 'path', label: 'Dateipfad *', type: 'text', placeholder: 'z.B. db/evo.db', required: true }
    ],
    filedb: [
      { key: 'path', label: 'Verzeichnis *', type: 'text', placeholder: 'z.B. /mnt/share/data', required: true }
    ],
    file: [
      { key: 'path', label: 'Verzeichnis *', type: 'text', placeholder: 'z.B. /mnt/share/data', required: true }
    ]
  };

  function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function showLoading(show) {
    if (loadingOverlay) {
      loadingOverlay.classList.toggle('visible', show);
    }
  }

  function showStatus(message, type) {
    if (!statusMessage) {
      return;
    }
    statusMessage.textContent = message;
    statusMessage.className = 'status-message visible ' + type;
    setTimeout(() => statusMessage.classList.remove('visible'), 5000);
  }

  async function fetchJson(endpoint, options = {}) {
    const response = await fetch(API_BASE + endpoint, {
      cache: 'no-store',
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {})
      }
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      const detail = payload.error || response.statusText || 'Unbekannter Fehler';
      throw new Error(detail);
    }
    return payload;
  }

  function renderSettings(settings, categoriesData) {
    currentSettings = settings;
    categories = categoriesData;
    let html = '';

    Object.entries(categoriesData).forEach(([categoryKey, categoryData]) => {
      html += `<div class="category-section">`;
      html += `<div class="category-header">${escapeHtml(categoryData.label)}`;
      if (categoryData.description) {
        html += `<div class="category-description">${escapeHtml(categoryData.description)}</div>`;
      }
      html += `</div>`;

      categoryData.keys.forEach((key) => {
        const rawValue = settings[key] ?? '';
        const value = String(rawValue);
        const lowerValue = value.toLowerCase();
        const isApiKey = key === 'DATA_TRANSFER_API_KEY';
        const isPassword = key.endsWith('_PASS') || key.endsWith('_PASSWORD') || isApiKey ||
          key === 'AFS_MSSQL_PASS' || key === 'XT_MYSQL_PASS';
        const isBoolean = BOOLEAN_KEYS.has(key) || lowerValue === 'true' || lowerValue === 'false';
        const selectOptions = SELECT_OPTIONS[key] || null;
        const datalistId = selectOptions ? `options-${key}` : null;

        html += `<div class="setting-row${isBoolean ? ' setting-row-toggle' : ''}">`;
        html += `<label class="setting-label" for="setting-${escapeHtml(key)}">${escapeHtml(key)}</label>`;

        if (isBoolean) {
          const checked = lowerValue === 'true';
          const toggleId = `setting-${key}`;
          html += `
            <div class="toggle-wrapper">
              <label class="toggle-switch">
                <input type="checkbox" id="${toggleId}" class="setting-input setting-toggle-input"
                       data-key="${escapeHtml(key)}" ${checked ? 'checked' : ''}>
                <span class="toggle-slider"></span>
              </label>
              <span class="toggle-text" data-toggle-label="${escapeHtml(key)}">${checked ? 'Aktiviert' : 'Deaktiviert'}</span>
            </div>
          `;
          html += `<div></div>`;
        } else {
          const inputId = `setting-${key}`;
          const inputType = isPassword ? 'password' : 'text';
          const listAttr = datalistId ? ` list="${datalistId}"` : '';
          html += `<div>`;
          html += `<input type="${inputType}" id="${inputId}" class="setting-input"
                         data-key="${escapeHtml(key)}" value="${escapeHtml(value)}"
                         placeholder="(leer)"${listAttr}>`;
          if (selectOptions) {
            html += `<datalist id="${datalistId}">`;
            let hasCurrent = false;
            selectOptions.forEach((option) => {
              const optionValue = String(option.value);
              if (optionValue === value) {
                hasCurrent = true;
              }
              const optionLabel = option.label ? ` label="${escapeHtml(option.label)}"` : '';
              html += `<option value="${escapeHtml(optionValue)}"${optionLabel}></option>`;
            });
            if (value && !hasCurrent) {
              html += `<option value="${escapeHtml(value)}" label="(aktueller Wert)"></option>`;
            }
            html += `</datalist>`;
          }
          html += `</div>`;
          if (isApiKey) {
            html += `<button class="btn-generate" data-target="${inputId}" title="Neuen API-Key generieren">🔑 Generieren</button>`;
          } else {
            html += `<div></div>`;
          }
        }

        html += `</div>`;
      });

      html += `</div>`;
    });

    settingsContainer.innerHTML = html;

    settingsContainer.querySelectorAll('.setting-toggle-input').forEach((input) => {
      const label = input.closest('.toggle-wrapper')?.querySelector('.toggle-text');
      const updateLabel = () => {
        if (label) {
          label.textContent = input.checked ? 'Aktiviert' : 'Deaktiviert';
        }
      };
      updateLabel();
      input.addEventListener('change', updateLabel);
    });

    settingsContainer.querySelectorAll('.btn-generate').forEach((btn) => {
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        await generateApiKey(btn.dataset.target);
      });
    });
  }

  function getUpdatedSettings() {
    const inputs = settingsContainer.querySelectorAll('.setting-input');
    const updated = {};
    inputs.forEach((input) => {
      const key = input.dataset.key;
      if (!key) {
        return;
      }
      let value;
      if (input.type === 'checkbox') {
        value = input.checked ? 'true' : 'false';
      } else {
        value = input.value;
      }
      const currentValue = String(currentSettings[key] ?? '');
      if (value !== currentValue) {
        updated[key] = value;
      }
    });
    return updated;
  }

  async function loadSettings() {
    try {
      showLoading(true);
      let response;
      if (currentServerIndex >= 0) {
        if (!Number.isInteger(currentServerIndex) || currentServerIndex >= remoteServers.length) {
          throw new Error('Ungültiger Server-Index');
        }
        response = await fetchJson(`settings_remote.php?server_index=${encodeURIComponent(currentServerIndex)}`);
      } else {
        response = await fetchJson('settings_read.php');
      }
      const data = response.data;
      if (!data.env_file_exists) {
        showNoEnvMessage();
        return;
      }
      if (!data.env_file_writable) {
        showStatus('Warnung: .env Datei ist nicht beschreibbar', 'error');
      }
      renderSettings(data.settings, data.categories);
    } catch (error) {
      const message = error?.message || '';
      const isLocal = currentServerIndex < 0;
      const looksLikeMissingEnv = /(\.env|env datei|config)/i.test(message);
      if (isLocal && looksLikeMissingEnv) {
        showNoEnvMessage();
      } else {
        showStatus('Fehler beim Laden der Einstellungen: ' + message, 'error');
        settingsContainer.innerHTML = '<p style="color: var(--error);">Fehler beim Laden der Einstellungen.</p>';
      }
    } finally {
      showLoading(false);
    }
  }

  function showNoEnvMessage() {
    const isRemote = currentServerIndex >= 0;
    const serverName = isRemote ? remoteServers[currentServerIndex]?.name : 'Lokaler Server';
    settingsContainer.innerHTML = `
      <div class="no-env-message">
        <h3>⚠️ Keine .env Datei gefunden</h3>
        <p>Die Konfigurationsdatei <code>.env</code> wurde auf ${escapeHtml(serverName)} nicht gefunden.</p>
        ${
          isRemote
            ? `<p>Sie können automatisch eine .env Datei auf dem Remote-Server mit dem lokalen API-Key erstellen.</p>
               <button class="btn-create-env" id="btn-create-remote-env">📝 Remote .env Datei erstellen</button>`
            : `<p>Die Datei wird auf Basis von <code>.env.example</code> erstellt und enthält alle notwendigen Einstellungen.</p>
               <button class="btn-create-env" id="btn-create-env">📝 .env Datei erstellen</button>`
        }
      </div>
    `;
    if (isRemote) {
      const btn = document.getElementById('btn-create-remote-env');
      if (btn) {
        btn.addEventListener('click', async () => {
          await createRemoteEnv(currentServerIndex);
          await loadSettings();
        });
      }
    } else {
      const btn = document.getElementById('btn-create-env');
      if (btn) {
        btn.addEventListener('click', createEnvFile);
      }
    }
  }

  async function createEnvFile() {
    try {
      showLoading(true);
      const apiKeyResponse = await fetchJson('generate_api_key.php', { method: 'POST' });
      if (!apiKeyResponse.data?.api_key) {
        throw new Error('API-Key konnte nicht generiert werden');
      }
      const apiKey = apiKeyResponse.data.api_key;
      const response = await fetchJson('initial_setup.php', {
        method: 'POST',
        body: JSON.stringify({ settings: { DATA_TRANSFER_API_KEY: apiKey } })
      });
      showStatus(response.data?.message || '.env Datei erfolgreich erstellt. API-Key wurde generiert.', 'success');
      await loadSettings();
    } catch (error) {
      showStatus('Fehler beim Erstellen der .env Datei: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function generateApiKey(targetInputId) {
    if (!targetInputId) {
      return;
    }
    try {
      showLoading(true);
      const response = await fetchJson('generate_api_key.php', { method: 'POST' });
      const apiKey = response.data?.api_key;
      if (!apiKey) {
        throw new Error('API-Key konnte nicht generiert werden');
      }
      const input = document.getElementById(targetInputId);
      if (input) {
        input.value = apiKey;
        input.type = 'text';
        setTimeout(() => {
          input.type = 'password';
        }, 3000);
      }
      showStatus('Neuer API-Key generiert. Bitte speichern Sie die Änderungen.', 'success');
    } catch (error) {
      showStatus('Fehler beim Generieren des API-Keys: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function saveSettings() {
    const updated = getUpdatedSettings();
    if (Object.keys(updated).length === 0) {
      showStatus('Keine Änderungen vorhanden', 'error');
      return;
    }
    try {
      showLoading(true);
      let response;
      if (currentServerIndex >= 0) {
        if (!Number.isInteger(currentServerIndex) || currentServerIndex >= remoteServers.length) {
          throw new Error('Ungültiger Server-Index');
        }
        response = await fetchJson('settings_remote.php', {
          method: 'POST',
          body: JSON.stringify({ server_index: currentServerIndex, settings: updated })
        });
      } else {
        response = await fetchJson('settings_write.php', {
          method: 'POST',
          body: JSON.stringify({ settings: updated })
        });
      }
      const message = response.data?.message || 'Gespeichert';
      const count = response.data?.updated_count ?? Object.keys(updated).length;
      showStatus(`${message} (${count} Einstellungen)`, 'success');
      await loadSettings();
    } catch (error) {
      showStatus('Fehler beim Speichern: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  function setDbStatus(message, type) {
    if (!dbStatusBox) {
      return;
    }
    dbStatusBox.textContent = message;
    dbStatusBox.className = 'status-message visible ' + type;
  }

  function clearDbStatus() {
    if (!dbStatusBox) {
      return;
    }
    dbStatusBox.textContent = '';
    dbStatusBox.className = 'status-message';
  }

  function populateDbTypeOptions(selected) {
    if (!dbTypeSelect) {
      return;
    }
    dbTypeSelect.innerHTML = '';
    let entries = Object.entries(databaseTypes || {});
    if (!entries.length) {
      // Fallback: use local DB_TYPE_FIELDS keys
      entries = Object.keys(DB_TYPE_FIELDS).map((k) => [k, (databaseTypes && databaseTypes[k]) || k.toUpperCase()]);
    }
    entries.forEach(([value, label]) => {
      const option = document.createElement('option');
      option.value = canonicalType(value);
      option.textContent = label;
      dbTypeSelect.appendChild(option);
    });
    dbTypeSelect.disabled = entries.length === 0;
    if (selected && entries.some(([v]) => v === selected)) {
      dbTypeSelect.value = selected;
    } else {
      dbTypeSelect.selectedIndex = 0;
    }
  }

  function renderDbRoles(type, selectedRoles = []) {
    if (!dbRolesContainer) {
      return;
    }
    dbRolesContainer.innerHTML = '';
    const entries = Object.entries(databaseRoles || {});
    const relevant = entries.filter(([, meta]) => Array.isArray(meta.types) && meta.types.includes(type));
    if (!relevant.length) {
      const info = document.createElement('div');
      info.style.color = 'rgba(226, 232, 240, 0.6)';
      info.style.fontSize = '0.85rem';
      info.textContent = 'Für diesen Typ sind keine Rollen verfügbar.';
      dbRolesContainer.appendChild(info);
      return;
    }
    relevant.forEach(([role, meta]) => {
      const label = document.createElement('label');
      label.className = 'db-role-option';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.value = role;
      input.name = 'db-role';
      input.checked = selectedRoles.includes(role);
      label.appendChild(input);
      const span = document.createElement('span');
      span.textContent = meta.label || role;
      label.appendChild(span);
      dbRolesContainer.appendChild(label);
    });
  }

  function renderDbFields(type, settings = {}, options = {}) {
    if (!dbFieldsContainer) {
      return;
    }
    dbFieldsContainer.innerHTML = '';
    const fields = DB_TYPE_FIELDS[type] || [];
    fields.forEach((field) => {
      const group = document.createElement('div');
      group.className = 'form-group';

      if (field.type === 'checkbox') {
        const label = document.createElement('label');
        label.className = 'db-role-option';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'db-field';
        input.dataset.key = field.key;
        const current = settings[field.key];
        input.checked = current !== undefined ? Boolean(current) : Boolean(field.default);
        label.appendChild(input);
        const span = document.createElement('span');
        span.textContent = field.label;
        label.appendChild(span);
        group.appendChild(label);
      } else {
        const label = document.createElement('label');
        label.className = 'form-label';
        label.textContent = field.label;
        const input = document.createElement('input');
        input.className = 'form-input db-field';
        input.dataset.key = field.key;
        const isNumber = field.type === 'number';
        const isPassword = field.type === 'password';
        input.type = isNumber ? 'number' : (isPassword ? 'password' : 'text');
        if (field.placeholder) {
          input.placeholder = field.placeholder;
        }
        if (isNumber) {
          const current = settings[field.key];
          if (current === undefined || current === null || current === '') {
            input.value = field.default !== undefined ? field.default : '';
          } else {
            input.value = current;
          }
        } else if (isPassword) {
          const protectedFlag = options.passwordProtected === true;
          input.dataset.protected = protectedFlag ? '1' : '0';
          input.value = '';
        } else {
          const current = settings[field.key];
          input.value = current !== undefined ? current : (field.default ?? '');
        }
        group.appendChild(label);
        group.appendChild(input);
      }

      dbFieldsContainer.appendChild(group);
    });
  }

  function collectDatabaseFormData() {
    if (!dbTitleInput || !dbTypeSelect) {
      throw new Error('Formular nicht verfügbar.');
    }
    const title = dbTitleInput.value.trim();
    const type = dbTypeSelect.value;
    if (!title) {
      throw new Error('Bitte einen Titel vergeben.');
    }
    if (!type) {
      throw new Error('Bitte einen Typ auswählen.');
    }
    const roles = [];
    if (dbRolesContainer) {
      dbRolesContainer.querySelectorAll('input[name="db-role"]').forEach((input) => {
        if (input.checked) {
          roles.push(input.value);
        }
      });
    }
    const settings = {};
    const fields = DB_TYPE_FIELDS[type] || [];
    fields.forEach((field) => {
      const input = dbFieldsContainer
        ? dbFieldsContainer.querySelector(`.db-field[data-key="${field.key}"]`)
        : null;
      if (!input) {
        return;
      }
      let value;
      if (field.type === 'checkbox') {
        value = input.checked;
      } else if (field.type === 'number') {
        value = input.value.trim();
        if (value === '') {
          value = field.default !== undefined ? field.default : '';
        } else {
          value = Number(value);
          if (Number.isNaN(value)) {
            throw new Error(`Bitte eine gültige Zahl für "${field.label}" angeben.`);
          }
        }
      } else if (field.type === 'password') {
        value = input.value;
        if (!value) {
          if (input.dataset.protected === '1' && editingDatabase) {
            value = '__PROTECTED__';
          }
        }
      } else {
        value = input.value.trim();
      }
      if (field.required && (value === '' || value === null || value === undefined)) {
        throw new Error(`Bitte "${field.label.replace('*', '').trim()}" ausfüllen.`);
      }
      settings[field.key] = value;
    });
    return { title, type, roles, settings };
  }

  function dbFormReset() {
    if (dbTitleInput) dbTitleInput.value = '';
    if (dbTypeSelect) dbTypeSelect.selectedIndex = 0;
    if (dbRolesContainer) dbRolesContainer.innerHTML = '';
    if (dbFieldsContainer) dbFieldsContainer.innerHTML = '';
  }

  function openDatabaseModal(connection = null) {
    if (!dbModal) {
      return;
    }
    editingDatabase = connection;
    dbFormReset();
    dbModal.classList.add('visible');
    clearDbStatus();

    const fallbackType = Object.keys(databaseTypes || {})[0] || Object.keys(DB_TYPE_FIELDS)[0] || 'mssql';
    const initialType = canonicalType((connection && connection.type) ? connection.type : fallbackType);
    populateDbTypeOptions(initialType);
    if (dbTitleInput) dbTitleInput.value = (connection && connection.title) ? connection.title : '';
    if (dbTypeSelect) dbTypeSelect.value = initialType;
    renderDbRoles(initialType, (connection && connection.roles) ? connection.roles : []);
    const options = { passwordProtected: !!(connection && connection.password_protected === true) };
    renderDbFields(initialType, (connection && connection.settings) ? connection.settings : {}, options);

    if (dbTypeSelect) {
      const onTypeChange = () => {
        const t = canonicalType(dbTypeSelect.value);
        renderDbRoles(t, []);
        renderDbFields(t, {}, {});
      };
      dbTypeSelect.onchange = onTypeChange;
      // Also react immediately on input (some browsers only fire change on blur)
      dbTypeSelect.addEventListener('input', onTypeChange);
    }

    // Also attach a global change listener once (in case modal reopened)
    if (dbTypeSelect && !dbTypeSelect.dataset.changeBound) {
      const onGlobalTypeChange = () => {
        const t = canonicalType(dbTypeSelect.value);
        renderDbRoles(t, []);
        renderDbFields(t, {}, {});
      };
      dbTypeSelect.addEventListener('change', onGlobalTypeChange);
      dbTypeSelect.addEventListener('input', onGlobalTypeChange);
      dbTypeSelect.dataset.changeBound = '1';
    }

    dbModalTitle.textContent = connection ? 'Verbindung bearbeiten' : 'Verbindung hinzufügen';
  }

  function closeDatabaseModal() {
    if (!dbModal) {
      return;
    }
    dbModal.classList.remove('visible');
    editingDatabase = null;
    clearDbStatus();
    dbFormReset();
  }

  function renderDatabaseList() {
    if (!dbList || !dbEmptyState) {
      return;
    }
    const defaultText = dbEmptyState.dataset.defaultText || dbEmptyState.textContent || '';
    if (!dbEmptyState.dataset.defaultText) {
      dbEmptyState.dataset.defaultText = defaultText;
    }
    if (!databaseConnections.length) {
      dbList.hidden = true;
      dbEmptyState.textContent = currentServerIndex >= 0
        ? 'Noch keine Verbindungen auf diesem Remote-Server.'
        : dbEmptyState.dataset.defaultText;
      dbEmptyState.hidden = false;
      dbList.innerHTML = '';
      return;
    }
    dbEmptyState.hidden = true;
    dbList.hidden = false;
    dbList.innerHTML = '';

    databaseConnections.forEach((connection) => {
      const item = document.createElement('div');
      item.className = 'database-item';
      item.dataset.id = connection.id;

      const info = document.createElement('div');
      const header = document.createElement('header');
      header.textContent = connection.title || connection.id;
      const badge = document.createElement('span');
      badge.className = 'db-type-badge';
      badge.textContent = databaseTypes[connection.type] || connection.type;
      header.appendChild(badge);
      info.appendChild(header);

      const meta = document.createElement('div');
      meta.className = 'database-meta';

      const typeLine = document.createElement('div');
      typeLine.textContent = `Typ: ${databaseTypes[connection.type] || connection.type}`;
      meta.appendChild(typeLine);

      if (connection.settings && connection.type === 'sqlite') {
        const pathLine = document.createElement('div');
        pathLine.textContent = `Pfad: ${connection.settings.path || ''}`;
        meta.appendChild(pathLine);
      }
      if (connection.settings && (connection.type === 'file' || connection.type === 'filedb')) {
        const pathLine = document.createElement('div');
        pathLine.textContent = `Pfad: ${connection.settings.path || ''}`;
        meta.appendChild(pathLine);
      }
      if (connection.settings && ['mssql', 'mysql'].includes(connection.type)) {
        const hostLine = document.createElement('div');
        const host = connection.settings.host || '';
        const port = connection.settings.port || '';
        hostLine.textContent = `Host: ${host}${port ? ':' + port : ''}`;
        meta.appendChild(hostLine);
        const dbLine = document.createElement('div');
        dbLine.textContent = `Datenbank: ${connection.settings.database || ''}`;
        meta.appendChild(dbLine);
      }

      const rolesPills = document.createElement('div');
      rolesPills.className = 'database-roles';
      (connection.roles || []).forEach((role) => {
        const pill = document.createElement('span');
        pill.className = 'database-role-pill';
        pill.textContent = databaseRoles[role]?.label || role;
        rolesPills.appendChild(pill);
      });
      if (rolesPills.childElementCount > 0) {
        meta.appendChild(rolesPills);
      }

      info.appendChild(meta);

      const actions = document.createElement('div');
      actions.className = 'database-actions';

      const statusBadge = document.createElement('span');
      const status = connection.status || {};
      const statusClass = status.ok === true ? 'ok' : (status.ok === false ? 'error' : 'warning');
      statusBadge.className = 'database-status ' + statusClass;
      statusBadge.textContent = status.ok === true ? 'Online' : (status.ok === false ? 'Offline' : 'Unbekannt');
      actions.appendChild(statusBadge);

      if (status.message) {
        const statusMessage = document.createElement('small');
        statusMessage.style.fontSize = '0.75rem';
        statusMessage.style.color = 'rgba(226, 232, 240, 0.6)';
        statusMessage.textContent = status.message;
        actions.appendChild(statusMessage);
      }

      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn-small';
      editBtn.textContent = '✏️ Bearbeiten';
      editBtn.addEventListener('click', () => openDatabaseModal(connection));
      actions.appendChild(editBtn);

      const testBtn = document.createElement('button');
      testBtn.type = 'button';
      testBtn.className = 'btn-small';
      testBtn.textContent = '🔍 Testen';
      testBtn.addEventListener('click', () => testDatabase(connection.id));
      actions.appendChild(testBtn);

      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn-small';
      deleteBtn.style.background = 'rgba(239, 68, 68, 0.18)';
      deleteBtn.style.borderColor = 'rgba(239, 68, 68, 0.3)';
      deleteBtn.style.color = 'rgb(252, 165, 165)';
      deleteBtn.textContent = '🗑️ Löschen';
      deleteBtn.addEventListener('click', () => deleteDatabase(connection.id));
      actions.appendChild(deleteBtn);

      item.appendChild(info);
      item.appendChild(actions);
      dbList.appendChild(item);
    });
  }

  async function loadDatabases() {
    const isRemote = currentServerIndex >= 0;
    // Reset UI immediately to prevent stale local entries when switching
    if (dbList) {
      dbList.innerHTML = '';
      dbList.hidden = true;
    }
    try {
      if (dbEmptyState) {
        const defaultText = dbEmptyState.dataset.defaultText || dbEmptyState.textContent || '';
        if (!dbEmptyState.dataset.defaultText) {
          dbEmptyState.dataset.defaultText = defaultText;
        }
        dbEmptyState.hidden = false;
        dbEmptyState.textContent = isRemote
          ? 'Verbindungen werden vom Remote-Server geladen...'
          : dbEmptyState.dataset.defaultText;
      }
      const endpoint = isRemote
        ? `databases_remote.php?server_index=${encodeURIComponent(currentServerIndex)}`
        : 'databases_manage.php';
      const response = await fetchJson(endpoint);
      const data = response.data || {};
      databaseConnections = (data.connections || []).filter((conn) => conn?.status?.ok === true);
      databaseRoles = data.roles || {};
      databaseTypes = data.types || {};
      renderDatabaseList();
    } catch (error) {
      showStatus('Fehler beim Laden der Datenbanken: ' + (error.message || error), 'error');
      if (dbEmptyState) {
        dbEmptyState.hidden = false;
        dbEmptyState.textContent = error.message || String(error);
      }
      if (dbList) {
        dbList.innerHTML = '';
        dbList.hidden = true;
      }
    }
  }

  async function saveDatabase() {
    try {
      clearDbStatus();
      showLoading(true);
      const formData = collectDatabaseFormData();
      const payload = {
        action: editingDatabase ? 'update' : 'add',
        connection: {
          id: editingDatabase ? editingDatabase.id : '',
          ...formData
        }
      };
      if (currentServerIndex >= 0) {
        payload.server_index = currentServerIndex;
      }
      const endpoint = currentServerIndex >= 0 ? 'databases_remote.php' : 'databases_manage.php';
      const response = await fetchJson(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const message = response.data?.message || 'Verbindung gespeichert.';
      showStatus(message, 'success');
      closeDatabaseModal();
      await loadDatabases();
    } catch (error) {
      showLoading(false);
      setDbStatus(error.message || String(error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function deleteDatabase(id) {
    if (!id) {
      return;
    }
    const confirmed = window.confirm('Verbindung wirklich löschen?');
    if (!confirmed) {
      return;
    }
    try {
      showLoading(true);
      const payload = { id };
      if (currentServerIndex >= 0) {
        payload.server_index = currentServerIndex;
      }
      const endpoint = currentServerIndex >= 0 ? 'databases_remote.php' : 'databases_manage.php';
      await fetchJson(endpoint, {
        method: 'DELETE',
        body: JSON.stringify(payload)
      });
      showStatus('Verbindung gelöscht.', 'success');
      await loadDatabases();
    } catch (error) {
      showStatus('Fehler beim Löschen: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function testDatabase(id) {
    if (!id) {
      return;
    }
    try {
      showLoading(true);
      const payload = { id };
      if (currentServerIndex >= 0) {
        payload.server_index = currentServerIndex;
      }
      const endpoint = currentServerIndex >= 0 ? 'databases_test_remote.php' : 'databases_test.php';
      const response = await fetchJson(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const status = response.data?.status || {};
      const type = status.ok ? 'success' : 'error';
      showStatus(status.message || 'Test abgeschlossen.', type);
      await loadDatabases();
    } catch (error) {
      showStatus('Fehler beim Testen: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function testDatabaseForm() {
    try {
      clearDbStatus();
      const formData = collectDatabaseFormData();
      const payload = {
        connection: {
          id: editingDatabase ? editingDatabase.id : '',
          ...formData
        }
      };
      if (currentServerIndex >= 0) {
        payload.server_index = currentServerIndex;
      }
      const endpoint = currentServerIndex >= 0 ? 'databases_test_remote.php' : 'databases_test.php';
      const response = await fetchJson(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const status = response.data?.status || {};
      const type = status.ok ? 'success' : 'error';
      setDbStatus(status.message || 'Test abgeschlossen.', type);
    } catch (error) {
      setDbStatus(error.message || String(error), 'error');
    }
  }

  function setEnvStatus(message, type = 'info') {
    const envStatus = document.getElementById('env-status-message');
    if (!envStatus) {
      return;
    }
    envStatus.style.color = type === 'error' ? 'rgb(252,165,165)' : 'rgba(74,222,128,0.9)';
    envStatus.textContent = message;
  }

  async function createRemoteEnv(serverIndex, options = {}) {
    const { onSuccess } = options;
    try {
      showLoading(true);
      const localSettings = await fetchJson('settings_read.php');
      const localApiKey = localSettings.data?.settings?.DATA_TRANSFER_API_KEY;
      if (!localApiKey) {
        throw new Error('Lokaler API-Key nicht gefunden');
      }
      await fetchJson('settings_remote.php', {
        method: 'PUT',
        body: JSON.stringify({
          server_index: serverIndex,
          initial_api_key: localApiKey
        })
      });
      showStatus('Remote .env erfolgreich erstellt', 'success');
      if (typeof onSuccess === 'function') {
        await onSuccess();
      }
    } catch (error) {
      showStatus('Fehler beim Erstellen der Remote .env: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  function updateDatabaseCardState() {
    if (!dbEmptyState) {
      return;
    }
    const defaultText = dbEmptyState.dataset.defaultText || dbEmptyState.textContent || '';
    if (!dbEmptyState.dataset.defaultText) {
      dbEmptyState.dataset.defaultText = defaultText;
    }
    dbEmptyState.textContent = currentServerIndex >= 0
      ? 'Verbindungen werden vom Remote-Server geladen...'
      : dbEmptyState.dataset.defaultText;
  }

  function updateCurrentServerDisplay() {
    if (!currentServerName || !currentServerBadge) {
      return;
    }
    if (currentServerIndex === -1) {
      currentServerName.textContent = 'Lokaler Server';
      currentServerBadge.textContent = 'LOKAL';
      currentServerBadge.className = 'server-badge local';
      if (currentServerDatabase) {
        currentServerDatabase.textContent = '';
      }
    } else {
      const server = remoteServers[currentServerIndex];
      currentServerName.textContent = server ? server.name : 'Unbekannt';
      currentServerBadge.textContent = 'REMOTE';
      currentServerBadge.className = 'server-badge remote';
      if (currentServerDatabase) {
        currentServerDatabase.textContent = server && server.database
          ? `Datenbank: ${server.database}`
          : '';
      }
    }
    updateDatabaseCardState();
  }

  function rebuildServerSelectOptions() {
    if (!serverSelect) {
      return;
    }
    const previousValue = serverSelect.value;
    serverSelect.innerHTML = '<option value="local">Lokaler Server</option>';
    remoteServers.forEach((server, index) => {
      const option = document.createElement('option');
      option.value = `remote-${index}`;
      option.textContent = server.name || `Remote ${index + 1}`;
      serverSelect.appendChild(option);
    });
    if (currentServerIndex >= 0) {
      serverSelect.value = `remote-${currentServerIndex}`;
    } else {
      serverSelect.value = 'local';
    }
    if (serverSelect.value !== previousValue) {
      const event = new Event('change');
      serverSelect.dispatchEvent(event);
    }
  }

  function renderServerList() {
    if (!serverList) {
      return;
    }
    if (!remoteServers.length) {
      serverList.innerHTML = '<p style="color: rgba(226, 232, 240, 0.6); text-align: center; padding: 1rem;">Keine Remote-Server konfiguriert</p>';
      return;
    }
    let html = '';
    remoteServers.forEach((server, index) => {
      html += `
        <div class="server-list-item">
          <div class="server-list-item-info">
            <div class="server-list-item-name">${escapeHtml(server.name)}</div>
            <div class="server-list-item-url">${escapeHtml(server.url)}</div>
            ${
              server.database
                ? `<div style="font-size:0.8rem;margin-top:0.25rem;color:rgba(226,232,240,0.6);">Datenbank: ${escapeHtml(server.database)}</div>`
                : ''
            }
          </div>
          <div class="server-list-item-actions">
            <button class="btn-small btn-edit" data-index="${index}">✏️ Bearbeiten</button>
            <button class="btn-small btn-delete" data-index="${index}">🗑️ Löschen</button>
          </div>
        </div>
      `;
    });
    serverList.innerHTML = html;

    serverList.querySelectorAll('.btn-edit').forEach((btn) => {
      btn.addEventListener('click', () => {
        const index = parseInt(btn.dataset.index, 10);
        editServer(index);
      });
    });
    serverList.querySelectorAll('.btn-delete').forEach((btn) => {
      btn.addEventListener('click', () => {
        const index = parseInt(btn.dataset.index, 10);
        deleteServer(index);
      });
    });
  }

  function showServerForm(editing = false, index = -1) {
    editingServerIndex = index;
    if (!serverForm || !btnAddServer) {
      return;
    }
    serverForm.style.display = 'block';
    btnAddServer.style.display = 'none';

    if (editing && index >= 0) {
      if (formTitle) formTitle.textContent = 'Server bearbeiten';
      const server = remoteServers[index];
      serverNameInput.value = server.name || '';
      serverUrlInput.value = server.url || '';
      serverApiKeyInput.value = server.api_key || '';
      serverDbInput.value = server.database || '';
    } else {
      if (formTitle) formTitle.textContent = 'Server hinzufügen';
      serverNameInput.value = '';
      serverUrlInput.value = '';
      serverApiKeyInput.value = '';
      serverDbInput.value = '';
    }
  }

  function hideServerForm() {
    if (!serverForm || !btnAddServer) {
      return;
    }
    serverForm.style.display = 'none';
    btnAddServer.style.display = 'block';
    editingServerIndex = -1;
    serverNameInput.value = '';
    serverUrlInput.value = '';
    serverApiKeyInput.value = '';
    serverDbInput.value = '';
  }

  function editServer(index) {
    showServerForm(true, index);
  }

  async function deleteServer(index) {
    if (index < 0 || index >= remoteServers.length) {
      return;
    }
    if (!window.confirm(`Server "${remoteServers[index].name}" wirklich löschen?`)) {
      return;
    }
    try {
      showLoading(true);
      await fetchJson('remote_servers_manage.php', {
        method: 'DELETE',
        body: JSON.stringify({ index })
      });
      await loadRemoteServers();
      renderServerList();
      rebuildServerSelectOptions();
      showStatus('Server erfolgreich gelöscht', 'success');
      if (currentServerIndex === index) {
        currentServerIndex = -1;
        if (serverSelect) serverSelect.value = 'local';
        updateCurrentServerDisplay();
        await loadSettings();
      } else if (currentServerIndex > index) {
        currentServerIndex -= 1;
      }
    } catch (error) {
      showStatus('Fehler beim Löschen: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function saveServer() {
    const name = serverNameInput.value.trim();
    const url = serverUrlInput.value.trim();
    const apiKey = serverApiKeyInput.value.trim();
    const database = serverDbInput.value.trim();

    if (!name || !url) {
      showStatus('Name und URL sind erforderlich', 'error');
      return;
    }

    try {
      showLoading(true);
      const action = editingServerIndex >= 0 ? 'update' : 'add';
      const payload = {
        action,
        server: { name, url, api_key: apiKey, database }
      };
      if (action === 'update') {
        payload.index = editingServerIndex;
      }
      const response = await fetchJson('remote_servers_manage.php', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      await loadRemoteServers();
      renderServerList();
      rebuildServerSelectOptions();
      hideServerForm();
      showStatus(response.data?.message || 'Server gespeichert', 'success');

      if (action === 'add' && !apiKey) {
        const createEnv = window.confirm(
          `Server "${name}" wurde hinzugefügt. Möchten Sie automatisch eine .env Datei auf dem Remote-Server mit dem lokalen API-Key erstellen?`
        );
        if (createEnv) {
          await createRemoteEnv(remoteServers.length - 1);
        }
      }
    } catch (error) {
      showStatus('Fehler beim Speichern: ' + (error.message || error), 'error');
    } finally {
      showLoading(false);
    }
  }

  async function loadRemoteServers() {
    try {
      const payload = await fetchJson('remote_servers_manage.php');
      const servers = Array.isArray(payload?.data?.servers) ? payload.data.servers : [];
      remoteServers = servers;
      renderServerList();
      rebuildServerSelectOptions();
    } catch (error) {
      showStatus('Remote-Server konnten nicht geladen werden: ' + (error.message || error), 'error');
      remoteServers = [];
      renderServerList();
      rebuildServerSelectOptions();
    }
  }

  async function initializePage() {
    try {
      await loadRemoteServers();
    } catch (error) {
      console.error('Remote-Server konnten nicht geladen werden:', error);
    }
    await loadSettings();
    await loadDatabases();
  }

  if (btnSave) {
    btnSave.addEventListener('click', saveSettings);
  }
  if (btnReload) {
    btnReload.addEventListener('click', loadSettings);
  }
  if (btnDbAdd) {
    btnDbAdd.addEventListener('click', () => openDatabaseModal());
  }
  if (btnDbRefresh) {
    btnDbRefresh.addEventListener('click', loadDatabases);
  }
  if (dbModalClose) {
    dbModalClose.addEventListener('click', closeDatabaseModal);
  }
  if (dbModal) {
    dbModal.addEventListener('click', (event) => {
      if (event.target === dbModal) {
        closeDatabaseModal();
      }
    });
  }
  if (dbBtnCancel) {
    dbBtnCancel.addEventListener('click', closeDatabaseModal);
  }
  if (dbBtnSave) {
    dbBtnSave.addEventListener('click', saveDatabase);
  }
  if (dbBtnTest) {
    dbBtnTest.addEventListener('click', testDatabaseForm);
  }

  if (serverSelect) {
    serverSelect.addEventListener('change', async () => {
      const value = serverSelect.value;
      if (value === 'local') {
        currentServerIndex = -1;
      } else if (value.startsWith('remote-')) {
        currentServerIndex = parseInt(value.substring(7), 10);
      }
      // Clear UI to prevent stale local data
      if (dbList) {
        dbList.innerHTML = '';
        dbList.hidden = true;
      }
      updateCurrentServerDisplay();
      await loadSettings();
      await loadDatabases();
    });
  }

  if (btnManageServers && serverModal) {
    btnManageServers.addEventListener('click', async () => {
      serverModal.classList.add('visible');
      await loadRemoteServers();
      renderServerList();
      hideServerForm();
    });
  }

  if (modalClose && serverModal) {
    modalClose.addEventListener('click', () => {
      serverModal.classList.remove('visible');
    });
  }
  if (serverModal) {
    serverModal.addEventListener('click', (event) => {
      if (event.target === serverModal) {
        serverModal.classList.remove('visible');
      }
    });
  }

  if (btnAddServer) {
    btnAddServer.addEventListener('click', () => showServerForm(false));
  }
  if (btnFormCancel) {
    btnFormCancel.addEventListener('click', hideServerForm);
  }
  if (btnFormSave) {
    btnFormSave.addEventListener('click', saveServer);
  }

  initializePage();
})();
