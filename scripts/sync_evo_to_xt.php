<?php
declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Skript kann nur auf der Kommandozeile ausgeführt werden.\n");
    exit(1);
}

/**
 * @param array<string,mixed> $schema
 */
function ensureXtTables(PDO $pdo, array $schema): void
{
    foreach ($schema as $tableName => $definition) {
        if (!is_array($definition)) {
            continue;
        }
        $fields = $definition['fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            continue;
        }

        $columnSql = [];
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }
            $columnSql[] = sprintf('"%s" TEXT', str_replace('"', '""', $field));
        }

        $primary = $definition['primary_key'] ?? [];
        if (is_string($primary) && $primary !== '') {
            $primary = [$primary];
        }
        if (is_array($primary) && $primary !== []) {
            $quoted = array_map(static fn(string $col): string => '"' . str_replace('"', '""', $col) . '"', $primary);
            $columnSql[] = sprintf('PRIMARY KEY (%s)', implode(', ', $quoted));
        }

        $unique = $definition['unique_constraint'] ?? [];
        if (is_string($unique) && $unique !== '') {
            $unique = [$unique];
        }
        if (is_array($unique) && $unique !== []) {
            $quoted = array_map(static fn(string $col): string => '"' . str_replace('"', '""', $col) . '"', $unique);
            $columnSql[] = sprintf('UNIQUE (%s)', implode(', ', $quoted));
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS "%s" (%s)',
            str_replace('"', '""', (string)$tableName),
            implode(', ', $columnSql)
        );
        $pdo->exec($sql);
    }
}

try {
    $manifestPath = afs_prefer_path('evo_xt.yml', 'mapping');
    $sourceSchemaPath = afs_prefer_path('evo-source.yml', 'schemas');
    $targetSchemaPath = afs_prefer_path('xt-remote.yml', 'schemas');

    if (!is_file($manifestPath)) {
        throw new RuntimeException('Mapping-Datei nicht gefunden: ' . $manifestPath);
    }
    if (!is_file($sourceSchemaPath)) {
        throw new RuntimeException('Quell-Schema nicht gefunden: ' . $sourceSchemaPath);
    }
    if (!is_file($targetSchemaPath)) {
        throw new RuntimeException('Ziel-Schema nicht gefunden: ' . $targetSchemaPath);
    }

    $config = $GLOBALS['config'] ?? null;
    if (!is_array($config)) {
        throw new RuntimeException('Konfiguration konnte nicht geladen werden.');
    }

    $sourceDbPath = $config['paths']['data_db'] ?? (__DIR__ . '/../db/evo.db');
    if (!is_file($sourceDbPath)) {
        throw new RuntimeException('Quell-Datenbank nicht gefunden: ' . $sourceDbPath);
    }

    $targetDbPath = $config['paths']['delta_db'] ?? (__DIR__ . '/../db/evo_delta.db');

    $sourceConnection = new SQLite_Connection($sourceDbPath);
    $targetPdo = new PDO('sqlite:' . $targetDbPath);
    $targetPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $targetPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $targetPdo->exec('PRAGMA foreign_keys = ON');

    $targetSchema = YamlMappingLoader::load($targetSchemaPath);
    ensureXtTables($targetPdo, $targetSchema['tables'] ?? []);

    $manifest = YamlMappingLoader::load($manifestPath);
    $sourceMapper = SourceMapper::fromFile($sourceSchemaPath);
    $targetMapper = TargetMapper::fromFile($targetSchemaPath);

    $sources = [
        'evo' => [
            'type' => 'mapper',
            'mapper' => $sourceMapper,
            'connection' => $sourceConnection,
        ],
    ];

    $engine = new MappingSyncEngine($sources, $targetMapper, $manifest);

    $entities = $manifest['entities'] ?? [];
    if (!is_array($entities) || $entities === []) {
        throw new RuntimeException('Keine Entities im Mapping definiert.');
    }

    $totalStats = [];
    foreach (array_keys($entities) as $entity) {
        if (!is_string($entity)) {
            continue;
        }
        $stats = $engine->syncEntity($entity, $targetPdo);
        $totalStats[$entity] = $stats;
        printf(
            "%s: verarbeitet=%d, Fehler=%d, Waisen=%d\n",
            $entity,
            (int)($stats['processed'] ?? 0),
            (int)($stats['errors'] ?? 0),
            (int)($stats['orphans'] ?? 0)
        );
    }

    $sourceConnection->close();
    echo "XT-Deltasynchronisation abgeschlossen. Ergebnis in {$targetDbPath}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
