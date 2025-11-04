<?php
declare(strict_types=1);

/**
 * BatchSyncEngine
 * 
 * Refactored sync engine that:
 * 1. Normalizes all source data in RAM (PHP arrays)
 * 2. Creates TEMP staging tables
 * 3. Batch inserts data (respecting SQLite ~999 bind limit)
 * 4. Performs set-based merges with INSERT ... ON CONFLICT DO UPDATE
 */
class BatchSyncEngine
{
    private const SQLITE_BIND_LIMIT = 999;
    
    private array $sources;
    private $targetMapper;
    private array $manifest;
    private MappingExpressionEvaluator $expressionEvaluator;
    private ?array $categoryPaths = null;
    
    /** @var array<string,array<int,string>> */
    private array $uniqueKeyCache = [];
    
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $compiledMapCache = [];
    
    /**
     * @param array<string,array<string,mixed>> $sources
     * @param array<string,mixed> $manifest
     */
    public function __construct(array $sources, $targetMapper, array $manifest)
    {
        $this->sources = $sources;
        $this->targetMapper = $targetMapper;
        $this->manifest = $manifest;
        $this->expressionEvaluator = new MappingExpressionEvaluator(new TransformRegistry());
    }
    
    /**
     * List entity names in configured order
     * @return array<int,string>
     */
    public function listEntityNames(): array
    {
        $entities = $this->manifest['entities'] ?? [];
        if (!is_array($entities) || $entities === []) {
            return [];
        }
        $names = array_keys($entities);
        $priority = [
            'warengruppe' => 10,
            'artikel' => 20,
        ];
        usort($names, static function (string $a, string $b) use ($priority): int {
            $pa = $priority[$a] ?? 100;
            $pb = $priority[$b] ?? 100;
            if ($pa === $pb) {
                return strcmp($a, $b);
            }
            return $pa <=> $pb;
        });
        return $names;
    }
    
    /**
     * Check if manifest has filecatcher sources
     */
    public function hasFileCatcherSources(): bool
    {
        foreach ($this->sources as $def) {
            if (($def['type'] ?? 'mapper') === 'filecatcher') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Sync entity using batch processing with TEMP staging tables
     * 
     * @return array<string,int> Statistics (processed, inserted, updated, errors)
     */
    public function syncEntity(string $entityName, PDO $targetDb): array
    {
        $startTime = microtime(true);
        
        // Phase 1: Load and normalize all data in RAM
        $entityConfig = $this->getEntityConfig($entityName);
        $compiledMap = $this->getCompiledMap($entityName, $entityConfig);
        
        $sourceRef = (string)($entityConfig['from'] ?? '');
        if ($sourceRef === '') {
            throw new RuntimeException(sprintf('Entity "%s" without "from" definition.', $entityName));
        }
        
        [$sourceId, $sourceTable] = $this->parseSourceReference($sourceRef);
        $sourceDef = $this->sources[$sourceId] ?? null;
        if ($sourceDef === null) {
            throw new RuntimeException(sprintf('Source "%s" for entity "%s" not registered.', $sourceId, $entityName));
        }
        
        // Fetch all source rows
        $sourceRows = $this->fetchSourceRows($sourceId, $sourceTable, $sourceDef, $targetDb);
        
        // Sort artikel by master/variant priority
        if ($entityName === 'artikel') {
            $sourceRows = $this->sortArtikelByPriority($sourceRows);
        }
        
        // Normalize all data in RAM
        $normalizedData = $this->normalizeDataInRam($sourceId, $sourceTable, $sourceRows, $compiledMap, $entityConfig, $targetDb);
        
        $loadTime = microtime(true) - $startTime;
        
        // Phase 2: Batch write to database with TEMP staging tables
        $stats = $this->batchWriteToDatabase($normalizedData, $targetDb, $entityConfig, $entityName);
        
        $totalTime = microtime(true) - $startTime;
        $stats['timing'] = [
            'load_normalize_ms' => round($loadTime * 1000, 2),
            'write_ms' => round(($totalTime - $loadTime) * 1000, 2),
            'total_ms' => round($totalTime * 1000, 2),
        ];
        
        return $stats;
    }
    
    /**
     * Phase 1: Normalize all data in RAM
     * Returns array keyed by target table containing normalized rows
     * 
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function normalizeDataInRam(
        string $sourceId,
        string $sourceTable,
        array $sourceRows,
        array $compiledMap,
        array $entityConfig,
        PDO $targetDb
    ): array {
        $normalized = [];
        $errors = 0;
        $skipped = 0;
        
        // Pre-load category paths if needed
        if ($this->categoryPaths === null) {
            $this->categoryPaths = $this->buildCategoryPaths();
        }
        
        // Pre-load lookup tables for FK resolution
        $lookupTables = $this->buildLookupTables($targetDb);
        
        foreach ($sourceRows as $idx => $row) {
            try {
                $context = $this->buildContext($sourceId, $sourceTable, $row);
                $targetRows = $this->buildTargetRows($compiledMap, $context);
                
                foreach ($targetRows as $table => $payload) {
                    if ($payload === []) {
                        $skipped++;
                        continue;
                    }
                    
                    // Validate and resolve foreign keys in RAM
                    $payload = $this->resolveForeignKeysInRam($table, $payload, $lookupTables);
                    
                    // Skip if missing required unique key values
                    $uniqueKeys = $this->getTargetUniqueKeys($table);
                    if ($uniqueKeys !== [] && !$this->hasAllUniqueKeyValues($payload, $uniqueKeys)) {
                        $skipped++;
                        continue;
                    }
                    
                    // Handle media file_name special case
                    if ($table === 'media') {
                        $fn = $payload['file_name'] ?? null;
                        if (!is_string($fn) || trim($fn) === '') {
                            $h = $payload['hash'] ?? null;
                            if (is_string($h) && trim($h) !== '') {
                                $payload['file_name'] = $h;
                            } else {
                                $skipped++;
                                continue;
                            }
                        }
                    }
                    
                    if (!isset($normalized[$table])) {
                        $normalized[$table] = [];
                    }
                    
                    $normalized[$table][] = $payload;
                }
            } catch (Throwable $e) {
                $errors++;
                error_log(sprintf(
                    '[BatchSyncEngine] Error normalizing row %d: %s',
                    $idx,
                    $e->getMessage()
                ));
            }
        }
        
        return $normalized;
    }
    
    /**
     * Phase 2: Batch write normalized data to database
     * Creates TEMP staging tables, batch inserts, then merges
     * 
     * @param array<string,array<int,array<string,mixed>>> $normalizedData
     * @return array<string,int>
     */
    private function batchWriteToDatabase(
        array $normalizedData,
        PDO $targetDb,
        array $entityConfig,
        string $entityName
    ): array {
        $stats = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0,
        ];
        
        $targetDb->beginTransaction();
        
        try {
            foreach ($normalizedData as $table => $rows) {
                if ($rows === []) {
                    continue;
                }
                
                $uniqueKeys = $this->getTargetUniqueKeys($table);
                $tableStats = $this->batchUpsertTable($targetDb, $table, $rows, $uniqueKeys);
                
                $stats['processed'] += $tableStats['processed'];
                $stats['inserted'] += $tableStats['inserted'];
                $stats['updated'] += $tableStats['updated'];
                $stats['unchanged'] += $tableStats['unchanged'];
            }
            
            // Handle orphan policies if configured
            $orphanUpdates = $this->applyOrphanPoliciesBatch($entityConfig, $targetDb, $normalizedData);
            if ($orphanUpdates > 0) {
                $stats['orphans'] = $orphanUpdates;
            }
            
            $targetDb->commit();
        } catch (Throwable $e) {
            $targetDb->rollBack();
            $stats['errors']++;
            error_log(sprintf('[BatchSyncEngine] Transaction failed: %s', $e->getMessage()));
            throw $e;
        }
        
        $stats['mode'] = 'batch';
        return $stats;
    }
    
    /**
     * Batch upsert rows into a table using TEMP staging table
     * 
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $uniqueKeys
     * @return array<string,int>
     */
    private function batchUpsertTable(PDO $targetDb, string $table, array $rows, array $uniqueKeys): array
    {
        $stats = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        
        if ($rows === []) {
            return $stats;
        }
        
        // Get table schema
        $columns = $this->getTableColumns($targetDb, $table);
        if ($columns === []) {
            throw new RuntimeException("Cannot get schema for table: $table");
        }
        
        // Create TEMP staging table
        $stagingTable = "_stg_$table";
        $this->createStagingTable($targetDb, $table, $stagingTable, $columns);
        
        // Batch insert into staging table
        $this->batchInsertIntoStaging($targetDb, $stagingTable, $rows, $columns);
        
        // Perform set-based merge
        $mergeStats = $this->performSetBasedMerge($targetDb, $table, $stagingTable, $columns, $uniqueKeys);
        
        $stats['processed'] = count($rows);
        $stats['inserted'] = $mergeStats['inserted'];
        $stats['updated'] = $mergeStats['updated'];
        $stats['unchanged'] = $mergeStats['unchanged'];
        
        // Drop staging table
        $targetDb->exec("DROP TABLE IF EXISTS " . $this->quoteIdentifier($stagingTable));
        
        return $stats;
    }
    
    /**
     * Get all column names for a table
     * @return array<int,string>
     */
    private function getTableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare("PRAGMA table_info(" . $this->quoteIdentifier($table) . ")");
        if (!$stmt || !$stmt->execute()) {
            return [];
        }
        
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['name'])) {
                $columns[] = (string)$row['name'];
            }
        }
        
        return $columns;
    }
    
    /**
     * Create TEMP staging table with same schema as target
     * 
     * @param array<int,string> $columns
     */
    private function createStagingTable(PDO $pdo, string $sourceTable, string $stagingTable, array $columns): void
    {
        // Drop if exists
        $pdo->exec("DROP TABLE IF EXISTS " . $this->quoteIdentifier($stagingTable));
        
        // Create as TEMP table copying structure
        $columnDefs = array_map(fn($col) => $this->quoteIdentifier($col), $columns);
        $columnList = implode(', ', $columnDefs);
        
        $sql = sprintf(
            "CREATE TEMP TABLE %s AS SELECT %s FROM %s WHERE 0",
            $this->quoteIdentifier($stagingTable),
            $columnList,
            $this->quoteIdentifier($sourceTable)
        );
        
        $pdo->exec($sql);
    }
    
    /**
     * Batch insert rows into staging table (respecting SQLite bind limit)
     * 
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $columns
     */
    private function batchInsertIntoStaging(PDO $pdo, string $stagingTable, array $rows, array $columns): void
    {
        if ($rows === [] || $columns === []) {
            return;
        }
        
        // Determine batch size based on column count
        $colCount = count($columns);
        $maxRowsPerBatch = (int)floor(self::SQLITE_BIND_LIMIT / $colCount);
        if ($maxRowsPerBatch < 1) {
            $maxRowsPerBatch = 1;
        }
        
        // Process in batches
        $batches = array_chunk($rows, $maxRowsPerBatch);
        
        foreach ($batches as $batch) {
            $this->insertBatch($pdo, $stagingTable, $batch, $columns);
        }
    }
    
    /**
     * Insert a single batch
     * 
     * @param array<int,array<string,mixed>> $batch
     * @param array<int,string> $columns
     */
    private function insertBatch(PDO $pdo, string $table, array $batch, array $columns): void
    {
        if ($batch === []) {
            return;
        }
        
        $quotedCols = array_map(fn($col) => $this->quoteIdentifier($col), $columns);
        $columnList = implode(', ', $quotedCols);
        
        $valuePlaceholders = [];
        $params = [];
        $paramIndex = 0;
        
        foreach ($batch as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $param = ':p' . $paramIndex++;
                $rowPlaceholders[] = $param;
                $params[$param] = $row[$col] ?? null;
            }
            $valuePlaceholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES %s",
            $this->quoteIdentifier($table),
            $columnList,
            implode(', ', $valuePlaceholders)
        );
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute($params)) {
            throw new RuntimeException("Batch insert failed for table: $table");
        }
    }
    
    /**
     * Perform set-based merge from staging to target table
     * Uses INSERT ... ON CONFLICT DO UPDATE
     * 
     * @param array<int,string> $columns
     * @param array<int,string> $uniqueKeys
     * @return array<string,int>
     */
    private function performSetBasedMerge(
        PDO $pdo,
        string $targetTable,
        string $stagingTable,
        array $columns,
        array $uniqueKeys
    ): array {
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        
        if ($uniqueKeys === []) {
            // No unique keys - simple insert
            $quotedCols = array_map(fn($col) => $this->quoteIdentifier($col), $columns);
            $columnList = implode(', ', $quotedCols);
            
            $sql = sprintf(
                "INSERT INTO %s (%s) SELECT %s FROM %s",
                $this->quoteIdentifier($targetTable),
                $columnList,
                $columnList,
                $this->quoteIdentifier($stagingTable)
            );
            
            $stmt = $pdo->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $stats['inserted'] = $stmt->rowCount();
            }
            
            return $stats;
        }
        
        // Build UPSERT with ON CONFLICT
        $quotedCols = array_map(fn($col) => $this->quoteIdentifier($col), $columns);
        $columnList = implode(', ', $quotedCols);
        
        // Build conflict target (unique keys)
        $quotedKeys = array_map(fn($key) => $this->quoteIdentifier($key), $uniqueKeys);
        $conflictTarget = implode(', ', $quotedKeys);
        
        // Build UPDATE SET clause (exclude unique keys and id from updates)
        $updateColumns = array_filter($columns, function($col) use ($uniqueKeys) {
            return !in_array($col, $uniqueKeys, true) && strtolower($col) !== 'id';
        });
        
        $updateSet = [];
        foreach ($updateColumns as $col) {
            $quoted = $this->quoteIdentifier($col);
            $updateSet[] = "$quoted = excluded.$quoted";
        }
        
        if ($updateSet === []) {
            // Only unique keys exist, treat as insert-only
            $sql = sprintf(
                "INSERT OR IGNORE INTO %s (%s) SELECT %s FROM %s",
                $this->quoteIdentifier($targetTable),
                $columnList,
                $columnList,
                $this->quoteIdentifier($stagingTable)
            );
        } else {
            // Use INSERT OR REPLACE for SQLite compatibility
            // Note: This requires all columns to be specified
            $sql = sprintf(
                "INSERT OR REPLACE INTO %s (%s) SELECT %s FROM %s",
                $this->quoteIdentifier($targetTable),
                $columnList,
                $columnList,
                $this->quoteIdentifier($stagingTable)
            );
        }
        
        $beforeCount = $this->getTableRowCount($pdo, $targetTable);
        $stmt = $pdo->prepare($sql);
        
        if (!$stmt || !$stmt->execute()) {
            throw new RuntimeException("Merge failed for table: $targetTable");
        }
        
        $afterCount = $this->getTableRowCount($pdo, $targetTable);
        $affectedRows = $stmt->rowCount();
        
        // Estimate inserts vs updates
        $inserted = $afterCount - $beforeCount;
        $updated = $affectedRows - $inserted;
        
        $stats['inserted'] = max(0, $inserted);
        $stats['updated'] = max(0, $updated);
        $stats['unchanged'] = 0;
        
        return $stats;
    }
    
    /**
     * Get current row count for a table
     */
    private function getTableRowCount(PDO $pdo, string $table): int
    {
        $sql = sprintf("SELECT COUNT(*) FROM %s", $this->quoteIdentifier($table));
        $result = $pdo->query($sql);
        if ($result === false) {
            return 0;
        }
        return (int)$result->fetchColumn();
    }
    
    /**
     * Build lookup tables for FK resolution in RAM
     * 
     * @return array<string,array<string,mixed>>
     */
    private function buildLookupTables(PDO $pdo): array
    {
        $lookups = [];
        
        // Helper to check if table exists
        $tableExists = function(string $table) use ($pdo): bool {
            try {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($table));
                return $stmt && $stmt->fetchColumn() !== false;
            } catch (Throwable $e) {
                return false;
            }
        };
        
        // Category: afs_id -> id
        if ($tableExists('category')) {
            try {
                $stmt = $pdo->query('SELECT id, afs_id FROM category WHERE afs_id IS NOT NULL');
                if ($stmt) {
                    $lookups['category_by_afs_id'] = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $key = trim((string)($row['afs_id'] ?? ''));
                        if ($key !== '') {
                            $lookups['category_by_afs_id'][$key] = (int)$row['id'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore if column doesn't exist
            }
        }
        
        // Artikel: model -> id
        if ($tableExists('artikel')) {
            try {
                $stmt = $pdo->query('SELECT id, model FROM artikel WHERE model IS NOT NULL');
                if ($stmt) {
                    $lookups['artikel_by_model'] = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $key = trim((string)($row['model'] ?? ''));
                        if ($key !== '') {
                            $lookups['artikel_by_model'][$key] = (int)$row['id'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore if column doesn't exist
            }
        }
        
        // Attribute: name -> id
        if ($tableExists('attribute')) {
            try {
                $stmt = $pdo->query('SELECT id, name FROM attribute WHERE name IS NOT NULL');
                if ($stmt) {
                    $lookups['attribute_by_name'] = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $key = trim((string)($row['name'] ?? ''));
                        if ($key !== '') {
                            $lookups['attribute_by_name'][$key] = (int)$row['id'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore if column doesn't exist
            }
        }
        
        // Category: seo_slug -> id
        if ($tableExists('category')) {
            try {
                $stmt = $pdo->query('SELECT id, seo_slug FROM category WHERE seo_slug IS NOT NULL');
                if ($stmt) {
                    $lookups['category_by_slug'] = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $key = trim((string)($row['seo_slug'] ?? ''));
                        if ($key !== '') {
                            $lookups['category_by_slug'][$key] = (int)$row['id'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore if column doesn't exist
            }
        }
        
        return $lookups;
    }
    
    /**
     * Resolve foreign keys in RAM using lookup tables
     */
    private function resolveForeignKeysInRam(string $table, array $payload, array $lookups): array
    {
        switch ($table) {
            case 'artikel':
                // Resolve category via afs_id
                if (array_key_exists('category', $payload)) {
                    $code = trim((string)($payload['category'] ?? ''));
                    $payload['category'] = ($code !== '' && isset($lookups['category_by_afs_id'][$code]))
                        ? $lookups['category_by_afs_id'][$code]
                        : 0;
                }
                break;
                
            case 'artikel_system':
                // Resolve artikel_id via model
                if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                    $model = trim($payload['artikel_id']);
                    $payload['artikel_id'] = ($model !== '' && isset($lookups['artikel_by_model'][$model]))
                        ? $lookups['artikel_by_model'][$model]
                        : null;
                }
                break;
                
            case 'artikel_attribute':
                // Resolve artikel_id and attribute_id
                if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                    $model = trim($payload['artikel_id']);
                    $payload['artikel_id'] = ($model !== '' && isset($lookups['artikel_by_model'][$model]))
                        ? $lookups['artikel_by_model'][$model]
                        : null;
                }
                if (isset($payload['attribute_id']) && is_string($payload['attribute_id'])) {
                    $name = trim($payload['attribute_id']);
                    $payload['attribute_id'] = ($name !== '' && isset($lookups['attribute_by_name'][$name]))
                        ? $lookups['attribute_by_name'][$name]
                        : null;
                }
                break;
                
            case 'artikel_attribute_system':
                // Resolve artikel_id and attribute_id
                if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                    $model = trim($payload['artikel_id']);
                    $payload['artikel_id'] = ($model !== '' && isset($lookups['artikel_by_model'][$model]))
                        ? $lookups['artikel_by_model'][$model]
                        : null;
                }
                if (isset($payload['attribute_id']) && is_string($payload['attribute_id'])) {
                    $name = trim($payload['attribute_id']);
                    $payload['attribute_id'] = ($name !== '' && isset($lookups['attribute_by_name'][$name]))
                        ? $lookups['attribute_by_name'][$name]
                        : null;
                }
                break;
                
            case 'category_system':
                // Resolve category_id via seo_slug
                if (isset($payload['category_id']) && is_string($payload['category_id'])) {
                    $slug = trim($payload['category_id']);
                    $payload['category_id'] = ($slug !== '' && isset($lookups['category_by_slug'][$slug]))
                        ? $lookups['category_by_slug'][$slug]
                        : null;
                }
                break;
        }
        
        return $payload;
    }
    
    /**
     * Apply orphan policies in batch mode
     * 
     * @param array<string,array<int,array<string,mixed>>> $normalizedData
     */
    private function applyOrphanPoliciesBatch(array $entityConfig, PDO $targetDb, array $normalizedData): int
    {
        // TODO: Implement batch orphan policy handling
        // For now, return 0 (can be implemented similar to original but in batch)
        return 0;
    }
    
    // === Helper methods (copied/adapted from MappingSyncEngine) ===
    
    private function getEntityConfig(string $entityName): array
    {
        $entities = $this->manifest['entities'] ?? [];
        if (!is_array($entities) || !isset($entities[$entityName])) {
            throw new RuntimeException(sprintf('Entity "%s" not defined in manifest.', $entityName));
        }
        $config = $entities[$entityName];
        if (!is_array($config)) {
            throw new RuntimeException(sprintf('Invalid configuration for entity "%s".', $entityName));
        }
        return $config;
    }
    
    /**
     * @return array{string,string}
     */
    private function parseSourceReference(string $reference): array
    {
        $parts = explode('.', $reference, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid from reference: ' . $reference);
        }
        $sourceId = trim($parts[0]);
        $table = trim($parts[1]);
        if ($sourceId === '' || $table === '') {
            throw new RuntimeException('Invalid from reference: ' . $reference);
        }
        return [$sourceId, $table];
    }
    
    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchSourceRows(string $sourceId, string $table, array $sourceDef, PDO $targetDb): array
    {
        $type = $sourceDef['type'] ?? 'mapper';
        if ($type === 'mapper') {
            /** @var SourceMapper $mapper */
            $mapper = $sourceDef['mapper'];
            $connection = $sourceDef['connection'];
            return $mapper->fetch($connection, $table);
        }
        
        if ($type === 'filecatcher') {
            $stmt = $targetDb->query('SELECT * FROM ' . $this->quoteIdentifier($table));
            if ($stmt === false) {
                throw new RuntimeException('filecatcher: Cannot read table: ' . $table);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        
        throw new RuntimeException(sprintf('Unknown source type "%s" for source "%s".', (string)$type, $sourceId));
    }
    
    /**
     * Sort artikel rows by master/variant priority
     * 
     * @return array<int,array<string,mixed>>
     */
    private function sortArtikelByPriority(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $aVal = trim((string)($a['Zusatzfeld07'] ?? ''));
            $bVal = trim((string)($b['Zusatzfeld07'] ?? ''));
            $aPriority = (strcasecmp($aVal, 'master') === 0 || $aVal === '') ? 0 : 1;
            $bPriority = (strcasecmp($bVal, 'master') === 0 || $bVal === '') ? 0 : 1;
            if ($aPriority === $bPriority) {
                $aKey = (string)($a['Artikelnummer'] ?? '');
                $bKey = (string)($b['Artikelnummer'] ?? '');
                return strcmp($aKey, $bKey);
            }
            return $aPriority <=> $bPriority;
        });
        return $rows;
    }
    
    /**
     * @return array<string,mixed>
     */
    private function buildContext(string $sourceId, string $table, array $row): array
    {
        $context = [];
        $context[$sourceId] = [$table => $row];
        $context[strtoupper($sourceId)] = [$table => $row];
        $context[$table] = $row;
        $context[strtoupper($table)] = $row;
        
        if ($this->categoryPaths !== null && $this->categoryPaths !== []) {
            $context['__category_paths'] = $this->categoryPaths;
        }
        
        return $context;
    }
    
    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildTargetRows(array $compiledMap, array $context): array
    {
        if ($compiledMap === []) {
            return [];
        }
        
        $result = [];
        foreach ($compiledMap as $definition) {
            $table = $definition['table'] ?? null;
            $column = $definition['column'] ?? null;
            if (!is_string($table) || $table === '' || !is_string($column) || $column === '') {
                continue;
            }
            
            if (!isset($result[$table])) {
                $result[$table] = [];
            }
            
            if (($definition['type'] ?? 'expression') === 'literal') {
                $result[$table][$column] = $definition['value'] ?? null;
                continue;
            }
            
            $compiled = $definition['compiled'] ?? null;
            $compiled = is_array($compiled) ? $compiled : null;
            $result[$table][$column] = $this->expressionEvaluator->evaluateCompiled($compiled, $context);
        }
        
        return $result;
    }
    
    /**
     * @return array<int,string>
     */
    private function getTargetUniqueKeys(string $table): array
    {
        if (isset($this->uniqueKeyCache[$table])) {
            return $this->uniqueKeyCache[$table];
        }
        try {
            $keys = $this->targetMapper->getUniqueKeyColumns($table);
        } catch (RuntimeException $e) {
            $keys = [];
        }
        return $this->uniqueKeyCache[$table] = $keys;
    }
    
    /**
     * @return array<int,array<string,mixed>>
     */
    private function getCompiledMap(string $entityName, array $entityConfig): array
    {
        if (isset($this->compiledMapCache[$entityName])) {
            return $this->compiledMapCache[$entityName];
        }
        
        $mapConfig = $entityConfig['map'] ?? [];
        $compiled = $this->compileMap($mapConfig);
        $this->compiledMapCache[$entityName] = $compiled;
        return $compiled;
    }
    
    /**
     * @return array<int,array<string,mixed>>
     */
    private function compileMap($mapConfig): array
    {
        if (!is_array($mapConfig) || $mapConfig === []) {
            return [];
        }
        
        $compiled = [];
        foreach ($mapConfig as $targetPath => $expression) {
            if (!is_string($targetPath)) {
                continue;
            }
            $targetInfo = $this->parseTargetPath($targetPath);
            if ($targetInfo === null) {
                continue;
            }
            
            if (is_string($expression)) {
                $compiled[] = [
                    'table' => $targetInfo['table'],
                    'column' => $targetInfo['column'],
                    'type' => 'expression',
                    'compiled' => $this->expressionEvaluator->compile($expression),
                ];
                continue;
            }
            
            $compiled[] = [
                'table' => $targetInfo['table'],
                'column' => $targetInfo['column'],
                'type' => 'literal',
                'value' => $expression,
            ];
        }
        
        return $compiled;
    }
    
    /**
     * @return array{table:string,column:string}|null
     */
    private function parseTargetPath(string $path): ?array
    {
        $parts = explode('.', $path);
        if (count($parts) < 3) {
            return null;
        }
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
    
    /**
     * @param array<int,string> $keys
     */
    private function hasAllUniqueKeyValues(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                return false;
            }
            $value = $payload[$key];
            if ($value === null) {
                return false;
            }
            if (is_string($value) && trim($value) === '') {
                return false;
            }
        }
        return true;
    }
    
    /**
     * @return array<string,string>
     */
    private function buildCategoryPaths(): array
    {
        foreach ($this->sources as $srcId => $def) {
            if (($def['type'] ?? 'mapper') !== 'mapper') {
                continue;
            }
            /** @var SourceMapper $mapper */
            $mapper = $def['mapper'];
            $connection = $def['connection'];
            try {
                $rows = $mapper->fetch($connection, 'Warengruppe');
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($rows) || $rows === []) {
                continue;
            }
            
            $nodes = [];
            foreach ($rows as $r) {
                $id = (string)($r['Warengruppe'] ?? '');
                if ($id === '') { continue; }
                $parent = trim((string)($r['Anhang'] ?? ''));
                if ($parent === '0') { $parent = ''; }
                $name = trim((string)($r['Bezeichnung'] ?? ''));
                $nodes[$id] = ['p' => $parent, 'n' => $name];
            }
            
            $cache = [];
            $slugify = function (string $value): string {
                $v = strtolower(trim($value));
                if ($v === '') { return ''; }
                $v = strtr($v, [
                    '&' => ' und ',
                    'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                    'ß' => 'ss',
                ]);
                if (function_exists('iconv')) {
                    $c = @iconv('UTF-8', 'ASCII//TRANSLIT', $v);
                    if ($c !== false) { $v = strtolower($c); }
                }
                $v = preg_replace('/[^a-z0-9]+/', '-', $v ?? '') ?? '';
                $v = preg_replace('/-+/', '-', $v) ?? $v;
                return trim($v, '-');
            };
            $resolve = function ($id) use (&$nodes, &$cache, $slugify, &$resolve): string {
                $id = (string)$id;
                if (isset($cache[$id])) { return $cache[$id]; }
                $node = $nodes[$id] ?? null;
                if ($node === null) { return $cache[$id] = $id; }
                $seg = $slugify($node['n'] ?? '');
                $parent = $node['p'] ?? '';
                if ($parent === '' || !isset($nodes[$parent])) { return $cache[$id] = $seg; }
                $pp = $resolve($parent);
                return $cache[$id] = ($pp !== '' ? ($pp . '/' . $seg) : $seg);
            };
            
            $paths = [];
            foreach ($nodes as $id => $_) {
                $paths[$id] = $resolve($id);
            }
            return $paths;
        }
        return [];
    }
    
    private function quoteIdentifier(string $identifier): string
    {
        $parts = array_map('trim', explode('.', $identifier));
        $quoted = [];
        foreach ($parts as $part) {
            if ($part === '*') {
                $quoted[] = '*';
                continue;
            }
            $quoted[] = '"' . str_replace('"', '""', $part) . '"';
        }
        return implode('.', $quoted);
    }
}
