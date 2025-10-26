<?php
declare(strict_types=1);

/**
 * SecurityValidator
 * 
 * Provides security validation for restricting access to entry points.
 * When security is enabled, validates that access comes from authorized sources.
 */
class SecurityValidator
{
    /**
     * Check if security should be enforced based on configuration
     */
    public static function isSecurityEnabled(array $config): bool
    {
        return $config['security']['enabled'] ?? false;
    }

    /**
     * Check if the current request originates from the API directory
     * 
     * This function checks the call stack (backtrace) to determine if
     * the request was initiated from within the api/ directory.
     * 
     * @return bool True if call comes from API, false otherwise
     */
    public static function isCalledFromApi(): bool
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        foreach ($backtrace as $trace) {
            if (isset($trace['file'])) {
                $normalizedFile = str_replace('\\', '/', $trace['file']);
                // Check if the file is in the api/ directory
                if (preg_match('#/api/[^/]+\.php$#', $normalizedFile)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Validate access to entry point
     * 
     * @param array $config Configuration array
     * @param string $entryPoint Name of the entry point (for error messages)
     * @return void Exits with 403 if access is denied
     */
    public static function validateAccess(array $config, string $entryPoint): void
    {
        if (!self::isSecurityEnabled($config)) {
            // Security is disabled, allow access
            return;
        }

        if (!self::isCalledFromApi()) {
            // Security is enabled and call doesn't come from API - deny access
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html>';
            echo '<html lang="de">';
            echo '<head>';
            echo '<meta charset="utf-8">';
            echo '<title>Zugriff verweigert</title>';
            echo '<style>';
            echo 'body { font-family: sans-serif; margin: 40px; background: #f5f5f5; }';
            echo '.error { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; }';
            echo 'h1 { color: #d32f2f; margin-top: 0; }';
            echo 'p { color: #666; line-height: 1.6; }';
            echo '</style>';
            echo '</head>';
            echo '<body>';
            echo '<div class="error">';
            echo '<h1>🔒 Zugriff verweigert</h1>';
            echo '<p><strong>Direkter Zugriff auf ' . htmlspecialchars($entryPoint, ENT_QUOTES, 'UTF-8') . ' ist nicht erlaubt.</strong></p>';
            echo '<p>Der Sicherheitsmodus ist aktiviert. Zugriff ist nur über die API-Schnittstelle erlaubt.</p>';
            echo '<p>Bitte verwenden Sie die API-Endpunkte im <code>api/</code> Verzeichnis für den Zugriff auf diese Funktionalität.</p>';
            echo '</div>';
            echo '</body>';
            echo '</html>';
            exit;
        }
    }
}
