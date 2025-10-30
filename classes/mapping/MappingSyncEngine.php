<?php
declare(strict_types=1);

/**
 * Orchestriert den Mapping-Lauf zwischen Quelle (z. B. AFS/MSSQL) und Ziel (z. B. EVO/SQLite).
 * Aktueller Fokus: Entities "artikel" und "warengruppe" aus mappings/afs_evo.yml.
 */
class MappingSyncEngine
{
    private SourceMapper $sourceMapper;
    private TargetMapper $targetMapper;
    /** @var array<string,mixed> */
    private array $mappingConfig;
    private MappingExpressionEvaluator $expressionEvaluator;

    /**
     * @param array<string,mixed> $entityMapping
     */
    public function __construct(SourceMapper $sourceMapper, TargetMapper $targetMapper, array $entityMapping)
    {
        $this->sourceMapper = $sourceMapper;
        $this->targetMapper = $targetMapper;
        $this->mappingConfig = $entityMapping;
        $this->expressionEvaluator = new MappingExpressionEvaluator(new TransformRegistry());
    }

    public static function fromFiles(string $sourcePath, string $targetPath, string $mappingPath): self
    {
        $source = SourceMapper::fromFile($sourcePath);
        $target = TargetMapper::fromFile($targetPath);
        $mapping = YamlMappingLoader::load($mappingPath);
        return new self($source, $target, $mapping);
    }

    /**
     * Führt den Sync für eine Entity aus.
     *
     * @return array<string,int> Statistiken (processed, skipped, errors)
     */
    public function syncEntity(string $entityName, MSSQL_Connection $sourceDb, PDO $targetDb): array
    {
        $entityConfig = $this->getEntityConfig($entityName);

        $sourceTables = $this->detectSourceTables($entityConfig);
        if ($sourceTables === []) {
            throw new RuntimeException(sprintf('Entity "%s" referenziert keine Quelle.', $entityName));
        }

        // Aktuell unterstützen wir nur 1:1-Tabellen-Bezug.
        $primarySource = $sourceTables[0];
        $sourceRows = $this->sourceMapper->fetch($sourceDb, $primarySource);

        $stats = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $startedTransaction = false;
        if (method_exists($targetDb, 'inTransaction') && !$targetDb->inTransaction()) {
            $targetDb->beginTransaction();
            $startedTransaction = true;
        }

        try {
            foreach ($sourceRows as $row) {
                $stats['processed']++;
                try {
                    $targetRows = $this->buildTargetRows($entityConfig, [
                        'AFS' => [
                            $primarySource => $row,
                        ],
                    ]);
                    foreach ($targetRows as $table => $payload) {
                        if ($payload === []) {
                            $stats['skipped']++;
                            continue;
                        }
                        $this->targetMapper->upsert($targetDb, $table, $payload);
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    error_log(sprintf(
                        '[MappingSyncEngine] Entity "%s": Fehler bei Datensatz #%d: %s',
                        $entityName,
                        $stats['processed'],
                        $e->getMessage()
                    ));
                }
            }
            if ($startedTransaction) {
                $targetDb->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && method_exists($targetDb, 'inTransaction') && $targetDb->inTransaction()) {
                $targetDb->rollBack();
            }
            throw $e;
        }

        $stats['mode'] = 'mapping';
        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    private function getEntityConfig(string $entityName): array
    {
        $entities = $this->mappingConfig['entities'] ?? [];
        if (!is_array($entities) || !isset($entities[$entityName])) {
            throw new RuntimeException(sprintf('Entity "%s" nicht in Mapping-Datei definiert.', $entityName));
        }
        $config = $entities[$entityName];
        if (!is_array($config)) {
            throw new RuntimeException(sprintf('Ungültige Definition für Entity "%s".', $entityName));
        }
        return $config;
    }

    /**
     * @param array<string,mixed> $entityConfig
     * @return array<int,string>
     */
    private function detectSourceTables(array $entityConfig): array
    {
        $map = $entityConfig['map'] ?? [];
        if (!is_array($map)) {
            return [];
        }
        $tables = [];
        foreach ($map as $expression) {
            if (!is_string($expression)) {
                continue;
            }
            if (preg_match_all('/AFS\.([A-Za-z0-9_]+)/', $expression, $matches)) {
                foreach ($matches[1] as $table) {
                    if (!in_array($table, $tables, true)) {
                        $tables[] = $table;
                    }
                }
            }
        }
        return $tables;
    }

    /**
     * @param array<string,mixed> $entityConfig
     * @param array<string,mixed> $context
     * @return array<string,array<string,mixed>>
     */
    private function buildTargetRows(array $entityConfig, array $context): array
    {
        $map = $entityConfig['map'] ?? [];
        if (!is_array($map) || $map === []) {
            return [];
        }

        $result = [];
        foreach ($map as $targetPath => $expression) {
            if (!is_string($targetPath) || !is_string($expression)) {
                continue;
            }
            $targetInfo = $this->parseTargetPath($targetPath);
            if ($targetInfo === null) {
                continue;
            }
            $value = $this->expressionEvaluator->evaluate($expression, $context);
            $table = $targetInfo['table'];
            $column = $targetInfo['column'];
            if (!isset($result[$table])) {
                $result[$table] = [];
            }
            $result[$table][$column] = $value;
        }

        return $result;
    }

    /**
     * Zerlegt "evo.Artikel.Artikelnummer" in ['table' => 'Artikel', 'column' => 'Artikelnummer'].
     *
     * @return array{table:string,column:string}|null
     */
    private function parseTargetPath(string $path): ?array
    {
        $parts = explode('.', $path);
        if (count($parts) < 3) {
            return null;
        }
        // Schema (z. B. "evo") ignorieren, wichtig sind Tabelle + Feld
        $table = $parts[1] ?? '';
        $column = $parts[2] ?? '';
        if ($table === '' || $column === '') {
            return null;
        }
        return [
            'table' => $table,
            'column' => $column,
        ];
    }
}
