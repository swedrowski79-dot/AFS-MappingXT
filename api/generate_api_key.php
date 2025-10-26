<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

/**
 * Generate a cryptographically secure API key
 * Uses random_bytes for maximum security
 */
function generateSecureApiKey(int $length = 32): string
{
    // Generate random bytes and convert to hexadecimal string
    return bin2hex(random_bytes($length));
}

try {
    // Generate a new API key (64 characters hex string)
    $apiKey = generateSecureApiKey(32);
    
    api_ok([
        'api_key' => $apiKey,
        'length' => strlen($apiKey),
        'generated_at' => date('c'),
    ]);
} catch (\Throwable $e) {
    api_error('Fehler beim Generieren des API-Keys: ' . $e->getMessage());
}
