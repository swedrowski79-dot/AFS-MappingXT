<?php
// Common bootstrap for web/* pages
declare(strict_types=1);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header_remove('X-Powered-By');
header_remove('Server');

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.php';
$autoloadPath = $rootDir . '/autoload.php';
try {
    if (!is_file($configPath) || !is_file($autoloadPath)) {
        throw new RuntimeException('Konfiguration oder Autoloader nicht gefunden.');
    }
    $config = require $configPath;
    require $autoloadPath;
} catch (Throwable $e) {
    error_log('[bootstrap_web] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    $detail = $e->getMessage() . ' in ' . ($e->getFile() ?? 'n/a') . ':' . ($e->getLine() ?? 0);
    echo '<h1>Fehler: Konfiguration kann nicht geladen werden.</h1>';
    echo '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// Optional security gate
if (class_exists('SecurityValidator')) {
    SecurityValidator::validateAccess($config, basename($_SERVER['SCRIPT_NAME'] ?? ''));
}

// URL bases
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$webDirUrl  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$rootDirUrl = rtrim(str_replace('\\', '/', dirname($webDirUrl)), '/');
$WEB_BASE   = $webDirUrl === '' ? '/' : ($webDirUrl . '/');
$ROOT_BASE  = $rootDirUrl === '' ? '/' : ($rootDirUrl . '/');
$API_BASE   = $ROOT_BASE . 'api/';
$ASSET_BASE = $ROOT_BASE . 'assets/';
$title      = (string)($config['ui']['title'] ?? 'AFS-Schnittstelle');
