<?php
declare(strict_types=1);

class MigrateService
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function run(array $config): array
    {
        $pdo = createEvoPdo($config);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $changes = [
            'added_update_columns' => [],
            'added_meta_columns' => [],
            'normalized_update_flags' => false,
        ];
        $updateTargets = [
            'Artikel_Bilder' => 'ALTER TABLE "Artikel_Bilder" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
            'Artikel_Dokumente' => 'ALTER TABLE "Artikel_Dokumente" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
            'Attrib_Artikel' => 'ALTER TABLE "Attrib_Artikel" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
            'category' => 'ALTER TABLE "category" ADD COLUMN "update" INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1))',
        ];
        foreach ($updateTargets as $table => $statement) {
            if (!$this->columnExists($pdo, $table, 'update')) {
                $pdo->exec($statement);
                $changes['added_update_columns'][] = $table;
            }
        }
        foreach (array_keys($updateTargets) as $table) {
            if ($this->columnExists($pdo, $table, 'update')) {
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
        foreach ($metaColumns as $table => $defs) {
            foreach ($defs as $column => $stmt) {
                if (!$this->columnExists($pdo, $table, $column)) {
                    $pdo->exec($stmt);
                    $changes['added_meta_columns'][] = "{$table}.{$column}";
                }
            }
        }
        return $changes;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
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
}
