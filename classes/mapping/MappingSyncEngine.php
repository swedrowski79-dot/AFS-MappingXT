<?php
declare(strict_types=1);

/**
 * MappingSyncEngine
 *
 * Orchestriert den Mapping-Lauf anhand eines Manifestes (z. B. mapping/afs_evo.yml).
 * Unterstützt mehrere Quellen (MSSQL, FileDB, FileCatcher) und ein Ziel (SQLite EVO).
 */
class MappingSyncEngine
{
    /**
     * Liefert die Entity-Namen in der im Manifest definierten Reihenfolge.
     *
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
     * True, wenn im Manifest Quellen vom Typ "filecatcher" registriert sind.
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
     * @var array<string,array<string,mixed>>
     *   - type: mapper|filecatcher
     *   - mapper: SourceMapper (bei type=mapper)
     *   - connection: object (MSSQL_Connection|FileDB_Connection)
     */
    private array $sources;

    private TargetMapper $targetMapper;

    /** @var array<string,mixed> */
    private array $manifest;

    private MappingExpressionEvaluator $expressionEvaluator;

    /** @var array<string,string>|null */
    private ?array $categoryPaths = null;

    /** @var array<string,array<int,string>> */
    private array $uniqueKeyCache = [];

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $existingRowCache = [];

    /** @var array<string,bool> */
    private array $existingRowCacheLoaded = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    private array $compiledMapCache = [];

    /**
     * @param array<string,array<string,mixed>> $sources
     * @param array<string,mixed> $manifest
     */
    public function __construct(array $sources, TargetMapper $targetMapper, array $manifest)
    {
        $this->sources = $sources;
        $this->targetMapper = $targetMapper;
        $this->manifest = $manifest;
        $this->expressionEvaluator = new MappingExpressionEvaluator(new TransformRegistry());
    }

    /**
     * Führt den Sync für eine Entity aus.
     *
     * @return array<string,int> Statistiken (processed, skipped, errors, inserted, updated, unchanged, copied)
     */
    public function syncEntity(string $entityName, PDO $targetDb): array
    {
        $entityConfig = $this->getEntityConfig($entityName);
        $sourceRef = (string)($entityConfig['from'] ?? '');
        if ($sourceRef === '') {
            throw new RuntimeException(sprintf('Entity "%s" ohne "from"-Definition.', $entityName));
        }
        [$sourceId, $sourceTable] = $this->parseSourceReference($sourceRef);

        $sourceDef = $this->sources[$sourceId] ?? null;
        if ($sourceDef === null) {
            throw new RuntimeException(sprintf('Quelle "%s" für Entity "%s" nicht registriert.', $sourceId, $entityName));
        }

        $compiledMap = $this->getCompiledMap($entityName, $entityConfig);

        $rows = $this->fetchSourceRows($sourceId, $sourceTable, $sourceDef, $targetDb);
        if ($entityName === 'artikel') {
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
        }

        $stats = [
            'processed' => 0,
            'skipped'   => 0,
            'errors'    => 0,
            'orphans'   => 0,
        ];
        $processedKeyInfo = [];

        $startedTransaction = false;
        if (method_exists($targetDb, 'inTransaction') && !$targetDb->inTransaction()) {
            $targetDb->beginTransaction();
            $startedTransaction = true;
        }

        /** @var array<string,array<string,bool>> $uniqueSeen */
        $uniqueSeen = [];

        try {
            foreach ($rows as $row) {
                $stats['processed']++;

                try {
                    $context = $this->buildContext($sourceId, $sourceTable, $row);

                    $targetRows = $this->buildTargetRows($compiledMap, $context);
                    foreach ($targetRows as $table => $payload) {
                        if ($payload === []) {
                            $stats['skipped']++;
                            continue;
                        }
                        // Ensure media.file_name is present; fallback to hash or skip
                        if ($table === 'media') {
                            $fn = $payload['file_name'] ?? null;
                            if (!is_string($fn) || trim($fn) === '') {
                                $h = $payload['hash'] ?? null;
                                if (is_string($h) && trim($h) !== '') {
                                    $payload['file_name'] = $h;
                                } else {
                                    $stats['skipped']++;
                                    continue;
                                }
                            }
                        }
                        $uniqueKeys = $this->getTargetUniqueKeys($table);
                        if ($uniqueKeys !== []) {
                            $payload = $this->resolveForeignKeyPolicy($targetDb, $table, $payload);
                        }
                        if ($uniqueKeys !== [] && !$this->hasAllUniqueKeyValues($payload, $uniqueKeys)) {
                            $stats['skipped']++;
                            continue;
                        }

                        $dedupeKey = null;
                        if ($uniqueKeys !== [] && $table === 'attribute') {
                            $keyValues = $this->resolveKeyValues($uniqueKeys, $payload, null);
                            if ($keyValues !== null) {
                                $dedupeKey = $this->buildCacheKeyFromValues($uniqueKeys, $keyValues);
                                if (($uniqueSeen[$table][$dedupeKey] ?? false) === true) {
                                    $stats['skipped']++;
                                    continue;
                                }
                            }
                        }

                        $needsExisting = $uniqueKeys !== [] && $this->requiresExistingRow($entityConfig);
                        $existingRow = $needsExisting
                            ? $this->loadExistingRow($targetDb, $table, $uniqueKeys, $payload)
                            : null;

                        $deltaChanged = $this->isDeltaChanged($entityConfig, $payload, $existingRow);
                        $payload = $this->applyFlags($entityConfig, $payload, $context, $existingRow, $deltaChanged);

                        // Enforce SEO slug policy (keep existing, ensure uniqueness)
                        $payload = $this->enforceSeoSlugPolicy($targetDb, $table, $payload, $existingRow, $uniqueKeys);

                        $this->targetMapper->upsert($targetDb, $table, $payload);
                        $this->storeRowInCache($table, $uniqueKeys, $payload, $existingRow);
                        if ($dedupeKey !== null && $table === 'attribute') {
                            if (!isset($uniqueSeen[$table])) {
                                $uniqueSeen[$table] = [];
                            }
                            $uniqueSeen[$table][$dedupeKey] = true;
                        }

                        if ($uniqueKeys !== []) {
                            $keyValues = $this->resolveKeyValues($uniqueKeys, $payload, $existingRow);
                            if ($keyValues !== null) {
                                if (!isset($processedKeyInfo[$table])) {
                                    $processedKeyInfo[$table] = [];
                                }
                                $combinedRow = $existingRow !== null
                                    ? array_merge($existingRow, $payload)
                                    : $payload;
                                $processedKeyInfo[$table][] = [
                                    'keys' => $uniqueKeys,
                                    'values' => $keyValues,
                                    'row' => $combinedRow,
                                ];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    error_log(sprintf(
                        '[MappingSyncEngine] Entity "%s": Fehler bei Datensatz #%d: %s',
                        $entityName,
                        $stats['processed'],
                        $e->getMessage()
                    ));
                }
            }

            $orphanUpdates = $this->applyOrphanPolicies($entityConfig, $targetDb, $processedKeyInfo);
            if ($orphanUpdates > 0) {
                $stats['orphans'] = $orphanUpdates;
            }

            if ($startedTransaction) {
                $targetDb->commit();
            }
        } catch (Throwable $e) {
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
        $entities = $this->manifest['entities'] ?? [];
        if (!is_array($entities) || !isset($entities[$entityName])) {
            throw new RuntimeException(sprintf('Entity "%s" nicht im Manifest definiert.', $entityName));
        }
        $config = $entities[$entityName];
        if (!is_array($config)) {
            throw new RuntimeException(sprintf('Ungültige Konfiguration für Entity "%s".', $entityName));
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
            throw new RuntimeException('Ungültige from-Referenz: ' . $reference);
        }
        $sourceId = trim($parts[0]);
        $table = trim($parts[1]);
        if ($sourceId === '' || $table === '') {
            throw new RuntimeException('Ungültige from-Referenz: ' . $reference);
        }
        return [$sourceId, $table];
    }

    /**
     * @param array<string,mixed> $sourceDef
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
                throw new RuntimeException('filecatcher: Tabelle konnte nicht gelesen werden: ' . $table);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        throw new RuntimeException(sprintf('Unbekannter Source-Typ "%s" für Quelle "%s".', (string)$type, $sourceId));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function buildContext(string $sourceId, string $table, array $row): array
    {
        $context = [];
        $context[$sourceId] = [$table => $row];
        $context[strtoupper($sourceId)] = [$table => $row];
        $context[$table] = $row;
        $context[strtoupper($table)] = $row;

        // Provide category path lookup for functions
        if ($this->categoryPaths === null) {
            $this->categoryPaths = $this->buildCategoryPaths();
        }
        if ($this->categoryPaths !== null && $this->categoryPaths !== []) {
            $context['__category_paths'] = $this->categoryPaths;
        }

        return $context;
    }

    /**
     * @param array<int,array<string,mixed>> $compiledMap
     * @param array<string,mixed> $context
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
     * @param array<string,mixed> $entityConfig
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
     * @param mixed $mapConfig
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
     * @param array<string,mixed> $payload
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
     * @param array<int,string> $keys
     */
    private function loadExistingRow(PDO $pdo, string $table, array $keys, array $payload): ?array
    {
        if ($keys === []) {
            return null;
        }

        $keyValues = $this->resolveKeyValues($keys, $payload, null);
        if ($keyValues === null) {
            return null;
        }

        $this->loadExistingRows($pdo, $table, $keys);
        $cacheKey = $this->buildCacheKeyFromValues($keys, $keyValues);

        return $this->existingRowCache[$table][$cacheKey] ?? null;
    }

    private function isDeltaChanged(array $entityConfig, array $payload, ?array $existingRow): bool
    {
        if ($existingRow === null) {
            return true;
        }
        $delta = $entityConfig['delta']['fields'] ?? [];
        if (!is_array($delta) || $delta === []) {
            return true;
        }
        foreach ($delta as $field) {
            $field = (string)$field;
            $current = $payload[$field] ?? null;
            $previous = $existingRow[$field] ?? null;
            if ($current != $previous) {
                return true;
            }
        }
        return false;
    }

    private function applyFlags(array $entityConfig, array $payload, array $context, ?array $existingRow, bool $deltaChanged): array
    {
        $flags = $entityConfig['flags'] ?? [];
        if (!is_array($flags) || $flags === []) {
            return $payload;
        }

        $evaluationContext = $context;
        $evaluationContext['row'] = $payload;
        if ($existingRow !== null) {
            $evaluationContext['existing'] = $existingRow;
        }

        $applySet = function (array $expressions) use (&$payload, &$evaluationContext): void {
            foreach ($expressions as $columnRef => $expression) {
                $target = $this->parseTargetPath($columnRef) ?? ['column' => $columnRef];
                $column = $target['column'];
                $value = $this->expressionEvaluator->evaluate((string)$expression, $evaluationContext);
                $payload[$column] = $value;
                $evaluationContext['row'][$column] = $value;
            }
        };

        if ($existingRow === null && isset($flags['on_insert']) && is_array($flags['on_insert'])) {
            $applySet($flags['on_insert']);
        } elseif ($existingRow !== null && $deltaChanged && isset($flags['on_update_when_delta_changed']) && is_array($flags['on_update_when_delta_changed'])) {
            $applySet($flags['on_update_when_delta_changed']);
        } elseif ($existingRow !== null && !$deltaChanged && isset($flags['on_update_when_no_change']) && is_array($flags['on_update_when_no_change'])) {
            $applySet($flags['on_update_when_no_change']);
        }

        return $payload;
    }

    private function requiresExistingRow(array $entityConfig): bool
    {
        $delta = $entityConfig['delta']['fields'] ?? [];
        if (is_array($delta) && $delta !== []) {
            return true;
        }
        $flags = $entityConfig['flags'] ?? [];
        return is_array($flags) && $flags !== [];
    }

    /**
     * Build slugified category paths using AFS Warengruppe (id: Warengruppe, parent: Anhang, name: Bezeichnung)
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
            } catch (\Throwable $e) {
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

    /**
     * Enforce SEO slug rules: don't overwrite existing slugs; ensure uniqueness by appending -%i
     * @param array<int,string> $uniqueKeys
     */
    private function resolveForeignKeyPolicy(PDO $pdo, string $table, array $payload): array
    {
        // artikel_system.id expects EVO artikel.id, map from model when given
        if ($table === 'artikel_system') {
            if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                $model = trim($payload['artikel_id']);
                if ($model !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "artikel" WHERE "model" = :m LIMIT 1');
                    if ($stmt && $stmt->execute([':m' => $model])) {
                        $id = $stmt->fetchColumn();
                        if ($id !== false && $id !== null) {
                            $payload['artikel_id'] = (int)$id;
                        }
                    }
                }
            }
        }
        // artikel_attribute: map id (model->id) and attribute_id (name->attribute_id)
        if ($table === 'artikel_attribute') {
            if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                $model = trim($payload['artikel_id']);
                if ($model !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "artikel" WHERE "model" = :m LIMIT 1');
                    if ($stmt && $stmt->execute([':m' => $model])) {
                        $id = $stmt->fetchColumn();
                        if ($id !== false && $id !== null) {
                            $payload['artikel_id'] = (int)$id;
                        }
                    }
                }
            }
            if (isset($payload['attribute_id']) && is_string($payload['attribute_id'])) {
                $name = trim($payload['attribute_id']);
                if ($name !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "attribute" WHERE "name" = :n LIMIT 1');
                    if ($stmt && $stmt->execute([':n' => $name])) {
                        $aid = $stmt->fetchColumn();
                        if ($aid !== false && $aid !== null) {
                            $payload['attribute_id'] = (int)$aid;
                        }
                    }
                }
            }
        }
        // artikel: resolve category via afs_id lookup
        if ($table === 'artikel') {
            if (array_key_exists('category', $payload)) {
                $cat = $payload['category'];
                $code = is_int($cat) ? (string)$cat : trim((string)$cat);
                $resolved = 0;
                if ($code !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "category" WHERE "afs_id" = :c LIMIT 1');
                    if ($stmt && $stmt->execute([':c' => $code])) {
                        $cid = $stmt->fetchColumn();
                        if ($cid !== false && $cid !== null) {
                            $resolved = (int)$cid;
                        }
                    }
                }
                $payload['category'] = $resolved;
            }
        }
        // category_system: resolve category_id via seo_slug lookup
        if ($table === 'category_system') {
            if (isset($payload['category_id']) && is_string($payload['category_id'])) {
                $slug = trim($payload['category_id']);
                if ($slug !== '') {
                    $stmt = $pdo->prepare('SELECT cat_id FROM "category" WHERE "seo_slug" = :s LIMIT 1');
                    if ($stmt && $stmt->execute([':s' => $slug])) {
                        $cid = $stmt->fetchColumn();
                        if ($cid !== false && $cid !== null) {
                            $payload['category_id'] = (int)$cid;
                        }
                    }
                }
            }
        }
        // artikel_attribute_system: resolve artikel_id (model->id) and attribute_id (name->attribute_id)
        if ($table === 'artikel_attribute_system') {
            if (isset($payload['artikel_id']) && is_string($payload['artikel_id'])) {
                $model = trim($payload['artikel_id']);
                if ($model !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "artikel" WHERE "model" = :m LIMIT 1');
                    if ($stmt && $stmt->execute([':m' => $model])) {
                        $id = $stmt->fetchColumn();
                        if ($id !== false && $id !== null) {
                            $payload['artikel_id'] = (int)$id;
                        }
                    }
                }
            }
            if (isset($payload['attribute_id']) && is_string($payload['attribute_id'])) {
                $name = trim($payload['attribute_id']);
                if ($name !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM "attribute" WHERE "name" = :n LIMIT 1');
                    if ($stmt && $stmt->execute([':n' => $name])) {
                        $aid = $stmt->fetchColumn();
                        if ($aid !== false && $aid !== null) {
                            $payload['attribute_id'] = (int)$aid;
                        }
                    }
                }
            }
        }
        return $payload;
    }

    private function enforceSeoSlugPolicy(PDO $pdo, string $table, array $payload, ?array $existingRow, array $uniqueKeys): array
    {
        if (!array_key_exists('seo_slug', $payload)) {
            return $payload;
        }

        $existing = $existingRow['seo_slug'] ?? null;
        if (is_string($existing) && trim($existing) !== '') {
            // Keep existing slug, do not overwrite
            unset($payload['seo_slug']);
            return $payload;
        }

        $candidate = $payload['seo_slug'];
        $base = is_string($candidate) ? trim($candidate) : '';
        if ($base === '') {
            unset($payload['seo_slug']);
            return $payload;
        }

        // Build exclusion for current row if keys available
        $excludeSql = '';
        $excludeParams = [];
        if ($uniqueKeys !== []) {
            $clauses = [];
            $idx = 0;
            foreach ($uniqueKeys as $key) {
                $val = $payload[$key] ?? ($existingRow[$key] ?? null);
                if ($val === null) { $clauses = []; break; }
                $param = ':k' . $idx++;
                $clauses[] = $this->quoteIdentifier($key) . ' = ' . $param;
                $excludeParams[$param] = $val;
            }
            if ($clauses !== []) {
                $excludeSql = ' AND NOT (' . implode(' AND ', $clauses) . ')';
            }
        }

        $slug = $base;
        $i = 0;
        while (true) {
            $sql = sprintf(
                'SELECT 1 FROM %s WHERE %s = :slug%s LIMIT 1',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier('seo_slug'),
                $excludeSql
            );
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) { break; }
            $params = array_merge([':slug' => $slug], $excludeParams);
            $ok = $stmt->execute($params);
            $exists = $ok ? ($stmt->fetchColumn() !== false) : false;
            if (!$exists) { break; }
            $i++;
            $slug = $base . '-' . $i;
        }

        $payload['seo_slug'] = $slug;
        return $payload;
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

    /**
     * @param array<int,string> $keys
     */
    private function loadExistingRows(PDO $pdo, string $table, array $keys): void
    {
        if ($keys === [] || ($this->existingRowCacheLoaded[$table] ?? false)) {
            return;
        }

        $sql = sprintf('SELECT * FROM %s', $this->quoteIdentifier($table));
        $stmt = $pdo->query($sql, PDO::FETCH_ASSOC);
        if ($stmt === false) {
            throw new RuntimeException('SELECT fehlgeschlagen: ' . $sql);
        }

        $this->existingRowCache[$table] = [];
        foreach ($stmt as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keyValues = $this->resolveKeyValues($keys, $row, null);
            if ($keyValues === null) {
                continue;
            }
            $cacheKey = $this->buildCacheKeyFromValues($keys, $keyValues);
            $this->existingRowCache[$table][$cacheKey] = $row;
        }

        $this->existingRowCacheLoaded[$table] = true;
    }

    /**
     * @param array<int,string> $keys
     * @param array<string,mixed> $primary
     * @param array<string,mixed>|null $fallback
     * @return array<string,mixed>|null
     */
    private function resolveKeyValues(array $keys, array $primary, ?array $fallback): ?array
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $primary)) {
                $value = $primary[$key];
            } elseif ($fallback !== null && array_key_exists($key, $fallback)) {
                $value = $fallback[$key];
            } else {
                return null;
            }
            if ($value === null) {
                return null;
            }
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $entityConfig
     * @param array<string,array<int,array<string,mixed>>> $processedKeyInfo
     */
    private function applyOrphanPolicies(array $entityConfig, PDO $targetDb, array $processedKeyInfo): int
    {
        $policyConfig = $entityConfig['orphan_policy'] ?? null;
        if (!is_array($policyConfig) || $policyConfig === []) {
            return 0;
        }

        $policies = $this->normalizeOrphanPolicies($policyConfig);
        if ($policies === []) {
            return 0;
        }

        $updated = 0;
        foreach ($policies as $policy) {
            $scopeRef = (string)($policy['scope'] ?? '');
            $matchKeyCfg = $policy['match_key'] ?? [];
            $actions = $policy['actions'] ?? [];

            if ($scopeRef === '' || !is_array($matchKeyCfg) || $matchKeyCfg === [] || !is_array($actions) || $actions === []) {
                continue;
            }

            $scope = $this->parseScopeReference($scopeRef);
            if ($scope === null) {
                continue;
            }
            $table = $scope['table'];
            $matchKeys = array_values(array_filter(array_map('strval', $matchKeyCfg)));
            if ($matchKeys === []) {
                continue;
            }

            $this->loadExistingRows($targetDb, $table, $matchKeys);
            $existingRows = $this->existingRowCache[$table] ?? [];
            if ($existingRows === []) {
                continue;
            }

            $processedSignatures = [];
            $processedEntries = $processedKeyInfo[$table] ?? [];
            foreach ($processedEntries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $row = [];
                if (isset($entry['row']) && is_array($entry['row'])) {
                    $row = $entry['row'];
                } elseif (isset($entry['values']) && is_array($entry['values'])) {
                    $row = $entry['values'];
                }

                $signatureValues = $this->resolveKeyValues($matchKeys, $row, null);
                if ($signatureValues === null) {
                    continue;
                }
                $processedSignatures[$this->buildCacheKeyFromValues($matchKeys, $signatureValues)] = true;
            }

            foreach ($existingRows as $cacheKey => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $signatureValues = $this->resolveKeyValues($matchKeys, $row, null);
                if ($signatureValues === null) {
                    continue;
                }
                $signature = $this->buildCacheKeyFromValues($matchKeys, $signatureValues);
                if (isset($processedSignatures[$signature])) {
                    continue;
                }

                $payload = $this->buildOrphanUpdatePayload($table, $matchKeys, $row, $actions);
                if ($payload === null) {
                    continue;
                }

                $this->targetMapper->upsert($targetDb, $table, $payload);
                $this->storeRowInCache($table, $matchKeys, $payload, $row);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param array<string,mixed> $policyConfig
     * @return array<int,array<string,mixed>>
     */
    private function normalizeOrphanPolicies(array $policyConfig): array
    {
        if (isset($policyConfig['scope'])) {
            return [$policyConfig];
        }

        $policies = [];
        foreach ($policyConfig as $candidate) {
            if (is_array($candidate)) {
                $policies[] = $candidate;
            }
        }

        return $policies;
    }

    /**
     * @return array{table:string}|null
     */
    private function parseScopeReference(string $reference): ?array
    {
        $parts = explode('.', $reference);
        if (count($parts) < 2) {
            return null;
        }
        $table = trim((string)$parts[1]);
        if ($table === '') {
            return null;
        }
        return ['table' => $table];
    }

    /**
     * @param array<int,mixed> $actions
     */
    private function buildOrphanUpdatePayload(string $table, array $matchKeys, array $existingRow, array $actions): ?array
    {
        $context = [
            'row' => $existingRow,
            'existing' => $existingRow,
            $table => $existingRow,
            strtoupper($table) => $existingRow,
        ];

        $payload = [];
        $changed = false;

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            if (isset($action['set']) && is_array($action['set'])) {
                foreach ($action['set'] as $columnRef => $expression) {
                    $target = $this->parseTargetPath($columnRef) ?? ['table' => $table, 'column' => $columnRef];
                    if ($target['table'] !== $table) {
                        continue;
                    }

                    if (is_string($expression)) {
                        $value = $this->expressionEvaluator->evaluate($expression, $context);
                    } else {
                        $value = $expression;
                    }

                    if (($existingRow[$target['column']] ?? null) != $value) {
                        $changed = true;
                    }
                    $payload[$target['column']] = $value;
                    $context['row'][$target['column']] = $value;
                }
            }
        }

        if (!$changed) {
            return null;
        }

        foreach ($matchKeys as $key) {
            if (!array_key_exists($key, $existingRow)) {
                return null;
            }
            $payload[$key] = $existingRow[$key];
        }

        return $payload;
    }

    /**
     * @param array<int,string> $keys
     * @param array<string,mixed> $values
     */
    private function buildCacheKeyFromValues(array $keys, array $values): string
    {
        $parts = [];
        foreach ($keys as $key) {
            $val = $values[$key] ?? null;
            $parts[] = $key . ':' . $this->serializeCacheValue($val);
        }
        return implode('|', $parts);
    }

    private function serializeCacheValue($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return md5(serialize($value));
    }

    /**
     * @param array<int,string> $keys
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $existingRow
     */
    private function storeRowInCache(string $table, array $keys, array $payload, ?array $existingRow): void
    {
        if ($keys === []) {
            return;
        }

        $values = $this->resolveKeyValues($keys, $payload, $existingRow);
        if ($values === null) {
            return;
        }

        $cacheKey = $this->buildCacheKeyFromValues($keys, $values);
        if (!isset($this->existingRowCache[$table])) {
            $this->existingRowCache[$table] = [];
        }

        $row = $existingRow !== null ? $existingRow : [];
        foreach ($payload as $column => $value) {
            $row[$column] = $value;
        }

        $this->existingRowCache[$table][$cacheKey] = $row;
    }

}
