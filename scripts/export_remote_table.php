<?php
declare(strict_types=1);

/**
 * CLI Helfer: Introspektiert eine Tabelle auf einem Remote-Server und gibt YAML-Felder aus.
 *
 * Verwendung:
 *   php scripts/export_remote_table.php --server=0 --connection=xt --table=xt_media --schema=schemas/xt-remote.yml
 */

require_once __DIR__ . '/../api/_bootstrap.php';
require_once __DIR__ . '/../api/_database_utils.php';
require_once __DIR__ . '/../classes/config/RemoteDatabaseConfig.php';

function cli_error(string $message, int $exitCode = 1): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

$options = getopt('', ['server::', 'connection::', 'table::', 'schema::']);
$serverIndex = isset($options['server']) ? (int)$options['server'] : 0;
$connectionId = $options['connection'] ?? '';
$table = $options['table'] ?? '';
$schemaPath = $options['schema'] ?? '';

if ($connectionId === '' || $table === '') {
    cli_error('Parameter --connection und --table sind erforderlich.');
}

$servers = $config['remote_servers']['servers'] ?? [];
$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $content = file_get_contents($envPath) ?: '';
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if (preg_match('/^REMOTE_SERVERS\s*=\\s*(.*)$/', $line, $m)) {
            $value = trim($m[1], "\"' ");
            if ($value !== '') {
                $servers = [];
                foreach (array_filter(array_map('trim', explode(',', $value))) as $cfg) {
                    $parts = array_map('trim', explode('|', $cfg));
                    if (count($parts) >= 2) {
                        $servers[] = [
                            'name' => $parts[0],
                            'url' => rtrim($parts[1], '/'),
                            'api_key' => $parts[2] ?? '',
                            'database' => $parts[3] ?? '',
                        ];
                    }
                }
            }
            break;
        }
    }
}

if (!isset($servers[$serverIndex])) {
    cli_error('Remote-Server nicht gefunden (Index ' . $serverIndex . ').');
}
$remote = $servers[$serverIndex];

$remoteConfig = RemoteDatabaseConfig::load($remote);
$connection = null;
foreach ($remoteConfig['connections'] as $candidate) {
    if (($candidate['id'] ?? '') === $connectionId) {
        $connection = $candidate;
        break;
    }
}
if (!$connection) {
    cli_error('Verbindung "' . $connectionId . '" nicht gefunden.');
}

$payload = [
    'table' => $table,
    'connection' => dbm_prepare_remote_payload($connection),
];
$targetUrl = rtrim((string)$remote['url'], '/') . '/api/databases_introspect.php';
$headers = ['Accept: application/json', 'Content-Type: application/json'];
if (!empty($remote['api_key'])) {
    $headers[] = 'X-API-Key: ' . $remote['api_key'];
}
$remoteConfigSettings = $config['remote_servers'] ?? [];
$timeout = max(3, (int)($remoteConfigSettings['timeout'] ?? 10));
$allowInsecure = (bool)($remoteConfigSettings['allow_insecure'] ?? false);

$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout,
]);
if ($allowInsecure) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}
$response = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($response === false) {
    cli_error('Remote-Anfrage fehlgeschlagen: ' . ($error ?: 'Unbekannter Fehler'));
}
$decoded = json_decode($response, true);
if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
    $msg = is_array($decoded) ? ($decoded['error'] ?? 'Unbekannte Antwort') : 'Ungültige Antwort';
    cli_error('Remote-Introspektion fehlgeschlagen (HTTP ' . $status . '): ' . $msg);
}
$columns = $decoded['data']['columns'] ?? [];
if (!is_array($columns) || !$columns) {
    cli_error('Keine Spalten erhalten.');
}

$yaml = "  {$table}:\n";
$yaml .= "    source:\n";
$yaml .= "      table: {$table}\n";
$yaml .= "    fields:\n";
foreach ($columns as $col) {
    $name = $col['name'] ?? ($col['Field'] ?? '');
    if ($name === '') {
        continue;
    }
    $yaml .= "      - {$name}\n";
}

echo "YAML-Ausschnitt:\n";
echo $yaml;

if ($schemaPath !== '') {
    if (!is_file($schemaPath)) {
        cli_error('Schema-Datei nicht gefunden: ' . $schemaPath);
    }
    file_put_contents($schemaPath, "# Aktualisiert am " . date('Y-m-d H:i:s') . "\n" . $yaml);
    echo "\n→ Abschnitt wurde in {$schemaPath} geschrieben.\n";
}
