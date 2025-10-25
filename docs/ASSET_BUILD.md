# Asset Build System

## Übersicht

Das Projekt verwendet ein modernes Asset-Build-System zur Minimierung und Bündelung von CSS und JavaScript-Dateien. Dies verbessert die Performance der Web-Oberfläche durch reduzierte Dateigrößen und schnellere Ladezeiten.

## Dateistruktur

```
assets/
├── css/
│   ├── main.css          # Quell-CSS (unkomprimiert, für Entwicklung)
│   └── main.min.css      # Minifiziertes CSS (für Produktion, automatisch generiert)
└── js/
    ├── main.js           # Quell-JavaScript (unkomprimiert, für Entwicklung)
    └── main.min.js       # Minifiziertes JavaScript (für Produktion, automatisch generiert)
```

## Voraussetzungen

- **Node.js** ≥ 14.x und **npm** ≥ 6.x
- Installiert automatisch bei `npm install`:
  - `clean-css-cli` - CSS-Minifizierung
  - `terser` - JavaScript-Minifizierung und -Optimierung

## Installation

```bash
# Dependencies installieren
npm install
```

## Build-Befehle

### Assets bauen (alle)
```bash
npm run build
# oder
make build
```

Dieser Befehl:
1. Minifiziert `assets/css/main.css` → `assets/css/main.min.css` (~31% Reduktion)
2. Minifiziert `assets/js/main.js` → `assets/js/main.min.js` (~52% Reduktion)

### Nur CSS bauen
```bash
npm run build:css
```

### Nur JavaScript bauen
```bash
npm run build:js
```

### Built Assets löschen
```bash
npm run clean
# oder
make clean-assets
```

## Entwicklungs-Workflow

### Änderungen an CSS/JS vornehmen

1. **CSS bearbeiten**: Änderungen in `assets/css/main.css` vornehmen
2. **JavaScript bearbeiten**: Änderungen in `assets/js/main.js` vornehmen
3. **Assets neu bauen**: `make build` ausführen
4. **Testen**: Browser neu laden (Strg+F5 für Hard-Refresh)

### JavaScript-Konfiguration

Das JavaScript erhält seine Konfiguration von PHP über das globale `window.APP_CONFIG` Objekt in `index.php`:

```javascript
window.APP_CONFIG = {
  apiBase: '/api/',
  debugTables: { /* ... */ }
};
```

Die Haupt-JavaScript-Datei ist als IIFE (Immediately Invoked Function Expression) strukturiert, die diese Konfiguration als Parameter erhält:

```javascript
(function(config) {
  'use strict';
  const API_BASE = config.apiBase;
  // ... rest of the application
})(window.APP_CONFIG);
```

## CI/CD Integration

Die minimierten Assets werden **nicht** ins Git-Repository committed (siehe `.gitignore`). Stattdessen müssen sie im Deployment-Prozess generiert werden:

### Deployment-Schritte

```bash
# 1. Dependencies installieren
npm install

# 2. Assets bauen
npm run build

# 3. Anwendung deployen
# ... (dein Deployment-Prozess)
```

### Docker Integration

Für Docker-Deployments sollte der Build-Schritt im Dockerfile enthalten sein:

```dockerfile
# Node.js für Asset-Build
FROM node:18 AS asset-builder
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY assets/ ./assets/
RUN npm run build

# PHP Application
FROM php:8.1-fpm
# ... PHP setup ...
COPY --from=asset-builder /app/assets/css/*.min.css /var/www/html/assets/css/
COPY --from=asset-builder /app/assets/js/*.min.js /var/www/html/assets/js/
```

## Performance-Vorteile

### Dateigrößen-Vergleich

| Datei | Original | Minifiziert | Reduktion |
|-------|----------|-------------|-----------|
| CSS   | 9.9 KB   | 6.8 KB      | ~31%      |
| JS    | 17 KB    | 8.1 KB      | ~52%      |
| **Gesamt** | **26.9 KB** | **14.9 KB** | **~45%** |

### Weitere Vorteile

- ✅ **Schnellere Ladezeiten**: Reduzierte Dateigröße = schnellere Downloads
- ✅ **Weniger Bandbreite**: Geringerer Datenverbrauch für Nutzer
- ✅ **Bessere Wartbarkeit**: Quell-Dateien bleiben lesbar und gut strukturiert
- ✅ **Moderne Best Practices**: Trennung von Entwicklung und Produktion
- ✅ **Cache-Friendly**: Separate Dateien ermöglichen besseres Caching

## Fehlerbehebung

### Build schlägt fehl

```bash
# Dependencies neu installieren
rm -rf node_modules package-lock.json
npm install
npm run build
```

### Assets werden nicht geladen

1. Prüfen, ob minifizierte Dateien existieren:
   ```bash
   ls -lh assets/css/main.min.css assets/js/main.min.js
   ```

2. Falls nicht vorhanden, Assets bauen:
   ```bash
   make build
   ```

3. Browser-Cache leeren (Strg+F5)

### JavaScript-Fehler in der Konsole

1. Prüfen, ob `window.APP_CONFIG` definiert ist
2. Prüfen, ob minifizierte JS-Datei korrekt geladen wird
3. Original `assets/js/main.js` auf Syntax-Fehler prüfen

## Zukünftige Erweiterungen

Mögliche Verbesserungen für das Asset-Build-System:

- [ ] **Source Maps** für einfacheres Debugging
- [ ] **Autoprefixer** für bessere Browser-Kompatibilität
- [ ] **Sass/SCSS** für erweiterte CSS-Features
- [ ] **Bundle Analyzer** zur Analyse der Bundle-Größe
- [ ] **Watch Mode** für automatisches Neu-Bauen bei Änderungen
- [ ] **Cache-Busting** mit Datei-Hashes in Dateinamen

## Siehe auch

- [README.md](../README.md) - Haupt-Dokumentation
- [CODE_STYLE.md](CODE_STYLE.md) - Code-Style-Richtlinien
- [Makefile](../Makefile) - Build-Befehle
