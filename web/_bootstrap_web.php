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
if (!is_file($configPath) || !is_file($autoloadPath)) {
    http_response_code(500);
    echo '<h1>Fehler: System nicht korrekt eingerichtet.</h1>';
    exit;
}
$config = require $configPath;
require $autoloadPath;

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
