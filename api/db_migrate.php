<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Methode nicht erlaubt', 405);
}

global $config;

try {
    $service = new MigrateService();
    $changes = $service->run($config);

    try {
        $tracker = createStatusTracker($config, 'categories');
        $tracker->logInfo('Schema-Migration ausgeführt', $changes, 'maintenance');
    } catch (\Throwable $e) {
        // Wenn Status-Tracker fehlschlägt, Migration nicht abbrechen
    }

    api_ok(['changes' => $changes]);
} catch (\Throwable $e) {
    api_error($e->getMessage());
}

/* legacy code removed after refactor */
/* legacy */ function _legacy_runSchemaMigration(PDO $pdo): array
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $changes = [
        'added_update_columns' => [],
        'added_meta_columns' => [],
        'added_master_columns' => [],
        'normalized_update_flags' => false,
    ];

    $updateTargets = [
        'Artikel_Bilder' => 'ALTER TABLE "Artikel_Bilder" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
        'Artikel_Dokumente' => 'ALTER TABLE "Artikel_Dokumente" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
        'Attrib_Artikel' => 'ALTER TABLE "Attrib_Artikel" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
        'category' => 'ALTER TABLE "category" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
    ];

    foreach ($updateTargets as $table => $statement) {
        if (!columnExists($pdo, $table, 'update')) {
            $pdo->exec($statement);
            $changes['added_update_columns'][] = $table;
        }
    }

    foreach (array_keys($updateTargets) as $table) {
        if (columnExists($pdo, $table, 'update')) {
            $pdo->exec("UPDATE \"{$table}\" SET \"update\" = COALESCE(\"update\", 0)");
            $changes['normalized_update_flags'] = true;
        }
    }

    $metaColumns = [
        'Artikel' => [
            'Meta_Title' => 'ALTER TABLE "Artikel" ADD COLUMN Meta_Title TEXT',
            'Meta_Description' => 'ALTER TABLE "Artikel" ADD COLUMN Meta_Description TEXT',
        ],
        'category' => [
            'meta_title' => 'ALTER TABLE "category" ADD COLUMN meta_title TEXT',
            'meta_description' => 'ALTER TABLE "category" ADD COLUMN meta_description TEXT',
        ],
    ];

    foreach ($metaColumns as $table => $definitions) {
        foreach ($definitions as $column => $statement) {
            if (!columnExists($pdo, $table, $column)) {
                $pdo->exec($statement);
                $changes['added_meta_columns'][] = "{$table}.{$column}";
            }
        }
    }

    $masterColumns = [
        'artikel' => [
            'is_master' => 'ALTER TABLE "artikel" ADD COLUMN is_master INTEGER',
            'master_model' => 'ALTER TABLE "artikel" ADD COLUMN master_model TEXT',
            'products_image' => 'ALTER TABLE "artikel" ADD COLUMN products_image TEXT',
            'products_name' => 'ALTER TABLE "artikel" ADD COLUMN products_name TEXT',
            'products_description' => 'ALTER TABLE "artikel" ADD COLUMN products_description TEXT',
        ],
        'category' => [
            'description' => 'ALTER TABLE "category" ADD COLUMN description TEXT',
        ],
    ];

    foreach ($masterColumns as $table => $definitions) {
        foreach ($definitions as $column => $statement) {
            if (!columnExists($pdo, $table, $column)) {
                $pdo->exec($statement);
                $changes['added_master_columns'][] = "{$table}.{$column}";
            }
        }
    }

    return $changes;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("PRAGMA table_info(\"{$table}\")");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($columns as $col) {
        if (strcasecmp((string)$col['name'], $column) === 0) {
            return true;
        }
    }
    return false;
}
