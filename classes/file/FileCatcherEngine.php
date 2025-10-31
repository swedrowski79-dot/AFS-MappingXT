<?php
declare(strict_types=1);

use PDO;
use PDOStatement;

/**
 * FileCatcherEngine
 *
 * Liest filecatcher-YAML-Konfigurationen, sucht Dateien in einem Quellverzeichnis,
 * kopiert sie in ein Ziel-"Vault"-Verzeichnis und pflegt Metadaten in einer Datenbanktabelle.
 */
final class FileCatcherEngine
{
    /** @var array<string,mixed> */
    private array $config;
    private string $projectRoot;
    private string $sourcePath;
    private bool $recursive;
    private bool $followSymlinks;
    /** @var array<int,string> */
    private array $extensions;
    private string $vaultBasePath;
    private string $filenamePattern;
    /** @var array<string,mixed> */
    private array $checksumConfig;
    /** @var array<string,mixed> */
    private array $tableConfig;
    /** @var array<string,mixed> */
    private array $logicConfig;

    /**
     * @param array<string,mixed> $config
     * @param string $projectRoot
     */
    public function __construct(array $config, string $projectRoot)
    {
        $this->config = $config;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);

        $source = $config['source'] ?? [];
        if (!is_array($source)) {
            throw new InvalidArgumentException('filecatcher: "source" muss ein Objekt sein.');
        }
        $searchPath = (string)($source['search_path'] ?? '');
        if ($searchPath === '') {
            throw new InvalidArgumentException('filecatcher: "source.search_path" ist erforderlich.');
        }
        $this->sourcePath = $this->resolvePath($searchPath);
        if (!is_dir($this->sourcePath)) {
            throw new RuntimeException('filecatcher: Quellpfad nicht gefunden: ' . $this->sourcePath);
        }
        $this->recursive = (bool)($source['recursive'] ?? true);
        $this->followSymlinks = (bool)($source['follow_symlinks'] ?? false);
        $includeExt = $source['include_ext'] ?? [];
        if (is_string($includeExt)) {
            $includeExt = [$includeExt];
        }
        if (!is_array($includeExt)) {
            $includeExt = [];
        }
        $this->extensions = array_values(array_filter(array_map(
            static fn($ext) => strtolower(trim((string)$ext)),
            $includeExt
        )));

        $vault = $config['vault'] ?? [];
        if (!is_array($vault)) {
            throw new InvalidArgumentException('filecatcher: "vault" muss ein Objekt sein.');
        }
        $basePath = (string)($vault['base_path'] ?? '');
        if ($basePath === '') {
            throw new InvalidArgumentException('filecatcher: "vault.base_path" ist erforderlich.');
        }
        $this->vaultBasePath = $this->resolvePath($basePath);
        if (!is_dir($this->vaultBasePath) && !@mkdir($this->vaultBasePath, 0775, true) && !is_dir($this->vaultBasePath)) {
            throw new RuntimeException('filecatcher: Zielpfad konnte nicht angelegt werden: ' . $this->vaultBasePath);
        }
        $this->filenamePattern = (string)($vault['filename_pattern'] ?? '{file_name}');
        $checksum = $vault['checksum'] ?? [];
        if (!is_array($checksum)) {
            $checksum = [];
        }
        $this->checksumConfig = $checksum;

        $table = $config['table'] ?? [];
        if (!is_array($table)) {
            throw new InvalidArgumentException('filecatcher: "table" muss ein Objekt sein.');
        }
        if (!isset($table['name']) || !is_string($table['name']) || trim($table['name']) === '') {
            throw new InvalidArgumentException('filecatcher: "table.name" ist erforderlich.');
        }
        $this->tableConfig = $table;

        $logic = $table['logic'] ?? [];
        if (!is_array($logic)) {
            throw new InvalidArgumentException('filecatcher: "table.logic" muss ein Objekt sein.');
        }
        $this->logicConfig = $logic;
    }

    public static function fromFile(string $yamlPath, array $appConfig): self
    {
        $config = YamlMappingLoader::load($yamlPath);
        $projectRoot = $appConfig['paths']['root'] ?? dirname(__DIR__, 2);
        return new self($config, (string)$projectRoot);
    }

    /**
     * Führt den FileCatcher-Lauf aus.
     *
     * @return array{processed:int,inserted:int,updated:int,unchanged:int,copied:int,skipped:int}
     */
    public function run(PDO $pdo): array
    {
        $files = $this->collectFiles();

        $summary = [
            'processed' => 0,
            'inserted'  => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'skipped'   => 0,
            'copied'    => 0,
        ];

        $keys = $this->normalizeKeys($this->tableConfig['keys'] ?? []);
        if ($keys === []) {
            throw new RuntimeException('filecatcher: Tabelle benötigt mindestens einen Schlüssel.');
        }

        $insertColumns = $this->normalizeList($this->logicConfig['upsert']['insert'] ?? []);
        $updateColumns = $this->normalizeList($this->logicConfig['upsert']['update'] ?? []);
        if ($insertColumns === []) {
            throw new RuntimeException('filecatcher: upsert.insert muss Felder enthalten.');
        }
        if ($updateColumns === []) {
            $updateColumns = $insertColumns;
        }

        $tableName = (string)$this->tableConfig['name'];
        $upsertStatement = $this->prepareUpsertStatement($pdo, $tableName, $keys, $insertColumns, $updateColumns);

        foreach ($files as $file) {
            $summary['processed']++;

            try {
                $context = [
                    'src' => $file,
                ];

                $existing = $this->loadExistingRow($pdo, $tableName, $keys, $file);
                if ($existing !== null) {
                    $context['existing'] = $existing;
                }

                $row = $this->buildRow($context);
                $deltaChanged = $this->hasDeltaChanged($context, $row, $existing);

                $this->applyFlags($row, $deltaChanged, $existing !== null);
                $row['updated_at'] = $row['updated_at'] ?? $this->now();

                $this->executeUpsert($upsertStatement, $row, $insertColumns, $updateColumns);

                if ($existing === null) {
                    $summary['inserted']++;
                    $deltaChanged = true;
                } elseif ($deltaChanged) {
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }

                $copiedCount = $this->performActions($row, $deltaChanged, $file);
                if ($copiedCount > 0) {
                    $summary['copied'] += $copiedCount;
                }
            } catch (Throwable $e) {
                $summary['skipped']++;
                error_log('[FileCatcher] ' . $e->getMessage());
            }
        }

        return $summary;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function collectFiles(): array
    {
        $flags = \FilesystemIterator::SKIP_DOTS;
        if ($this->followSymlinks) {
            $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
        }

        $files = [];
        if ($this->recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sourcePath, $flags),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } else {
            $iterator = new \IteratorIterator(new \DirectoryIterator($this->sourcePath));
        }

        $finfo = extension_loaded('fileinfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }
            if (!$fileInfo->isFile()) {
                continue;
            }
            $extension = strtolower($fileInfo->getExtension());
            if ($this->extensions !== [] && !in_array($extension, $this->extensions, true)) {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $size = $fileInfo->getSize();
            $mtime = $fileInfo->getMTime();
            $basename = $fileInfo->getBasename();

            $mime = $finfo ? $finfo->file($fullPath) : null;
            if (!$mime && function_exists('mime_content_type')) {
                $mime = @mime_content_type($fullPath) ?: null;
            }

            $files[] = [
                'path'           => $fullPath,
                'relative_path'  => $this->relativeTo($fullPath, $this->sourcePath),
                'dir'            => $fileInfo->getPath(),
                'file_name'      => $basename,
                'name_stem'      => pathinfo($basename, PATHINFO_FILENAME),
                'ext'            => $extension,
                'size'           => $size,
                'mtime'          => $this->formatTime($mtime),
                'mtime_raw'      => $mtime,
                'mime'           => $mime ?? 'application/octet-stream',
            ];
        }

        return $files;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildRow(array $context): array
    {
        $map = $this->logicConfig['map'] ?? [];
        if (!is_array($map)) {
            throw new RuntimeException('filecatcher: logic.map muss Objekt sein.');
        }

        $row = [];
        foreach ($map as $column => $expression) {
            if (!is_string($column) || $column === '') {
                continue;
            }
            $row[$column] = $this->evaluate($expression, $context + ['row' => $row]);
        }

        // Standardfelder, falls im Mapping nicht explizit gesetzt
        if (!isset($row['stored_file'])) {
            $row['stored_file'] = $this->buildVaultFilename($context, $row);
        }
        if (!isset($row['stored_path'])) {
            $row['stored_path'] = '';
        }
        if (!isset($row['stored_at'])) {
            $row['stored_at'] = null;
        }

        return $row;
    }

    private function buildVaultFilename(array $context, array $row): string
    {
        $pattern = $this->filenamePattern;
        $replacements = $this->buildPlaceholderContext($context, $row);
        $value = $this->replacePlaceholders($pattern, $replacements);
        $value = $this->sanitizeFilename($value);
        $ext = $context['src']['ext'] ?? null;
        if ($ext && !str_ends_with(strtolower($value), '.' . strtolower((string)$ext))) {
            $value .= '.' . $ext;
        }
        return $value;
    }

    /**
     * @param array<string,mixed>|null $existing
     */
    private function hasDeltaChanged(array $context, array $row, ?array $existing): bool
    {
        if ($existing === null) {
            return true;
        }
        $delta = $this->logicConfig['delta']['fields'] ?? [];
        if (!is_array($delta) || $delta === []) {
            return true;
        }
        foreach ($delta as $field) {
            $field = (string)$field;
            $current = $row[$field] ?? null;
            $old = $existing[$field] ?? null;
            if ($current != $old) { // intentional loose comparison to detect numeric strings etc.
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function applyFlags(array &$row, bool $deltaChanged, bool $existing): void
    {
        $flags = $this->logicConfig['flags'] ?? [];
        if (!is_array($flags)) {
            return;
        }
        $context = ['row' => $row];

        if (!$existing && isset($flags['on_insert']) && is_array($flags['on_insert'])) {
            foreach ($flags['on_insert'] as $column => $expression) {
                $row[$column] = $this->evaluate($expression, $context);
            }
        } elseif ($existing && $deltaChanged && isset($flags['on_update_when_delta_changed']) && is_array($flags['on_update_when_delta_changed'])) {
            foreach ($flags['on_update_when_delta_changed'] as $column => $expression) {
                $row[$column] = $this->evaluate($expression, $context);
            }
        } elseif ($existing && !$deltaChanged && isset($flags['on_update_when_no_change']) && is_array($flags['on_update_when_no_change'])) {
            foreach ($flags['on_update_when_no_change'] as $column => $expression) {
                $row[$column] = $this->evaluate($expression, $context);
            }
        }
    }

    /**
     * @param array<string,mixed> $file
     */
    private function loadExistingRow(PDO $pdo, string $table, array $keys, array $file): ?array
    {
        $conditions = [];
        $params = [];
        foreach ($keys as $key) {
            $value = $this->resolveKeyValue($key, $file);
            $conditions[] = $this->quoteIdentifier($key) . ' = :' . $key;
            $params[':' . $key] = $value;
        }
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $conditions)
        );
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('filecatcher: SELECT vorbereiten fehlgeschlagen: ' . $sql);
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $insertColumns
     * @param array<int,string> $updateColumns
     */
    private function executeUpsert(PDOStatement $stmt, array $row, array $insertColumns, array $updateColumns): void
    {
        $params = [];
        foreach ($insertColumns as $column) {
            $params[':' . $column] = $row[$column] ?? null;
        }
        foreach ($updateColumns as $column) {
            $params[':upd_' . $column] = $row[$column] ?? null;
        }
        if ($stmt->execute($params) === false) {
            $info = $stmt->errorInfo();
            throw new RuntimeException('filecatcher: Upsert fehlgeschlagen: ' . ($info[2] ?? 'Unbekannter Fehler'));
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $file
     */
    private function performActions(array $row, bool $deltaChanged, array $file): int
    {
        $actions = $this->logicConfig['actions'] ?? [];
        if (!is_array($actions) || $actions === []) {
            return 0;
        }
        $copied = 0;
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = strtolower((string)($action['type'] ?? ''));
            if ($type !== 'file_copy') {
                continue;
            }
            $onlyWhenChanged = (bool)($action['only_when_changed'] ?? false);
            if ($onlyWhenChanged && !$deltaChanged) {
                continue;
            }
            $context = [
                'row' => $row,
                'src' => $file,
            ];
            $from = $this->evaluate($action['from'] ?? '', $context);
            $to = $this->evaluate($action['to'] ?? '', $context);
            if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
                continue;
            }
            $destinationDir = dirname($to);
            if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
                throw new RuntimeException('filecatcher: Zielordner konnte nicht angelegt werden: ' . $destinationDir);
            }
            if (!@copy($from, $to)) {
                throw new RuntimeException(sprintf('filecatcher: Kopieren fehlgeschlagen (%s -> %s)', $from, $to));
            }
            $copied++;
        }
        return $copied;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }
        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<string,mixed> $file
     */
    private function resolveKeyValue(string $key, array $file)
    {
        if ($key === 'file_name') {
            return $file['file_name'] ?? null;
        }
        if (array_key_exists($key, $file)) {
            return $file[$key];
        }
        throw new RuntimeException('filecatcher: Schlüssel "' . $key . '" nicht im Dateikontext verfügbar.');
    }

    /**
     * @param string|mixed $expression
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function evaluate($expression, array $context)
    {
        if ($expression === null) {
            return null;
        }
        if (is_numeric($expression) || is_bool($expression)) {
            return $expression;
        }
        if (!is_string($expression)) {
            return $expression;
        }
        $expr = trim($expression);
        if ($expr === '') {
            return '';
        }

        if (str_starts_with($expr, '=')) {
            return $this->parseLiteral(substr($expr, 1));
        }

        if ($expr === 'null') {
            return null;
        }

        if ((str_starts_with($expr, '"') && str_ends_with($expr, '"')) ||
            (str_starts_with($expr, "'") && str_ends_with($expr, "'"))) {
            $value = substr($expr, 1, -1);
            return stripslashes($value);
        }

        if (preg_match('/^\\$func\\.([a-zA-Z0-9_]+)\\((.*)\\)$/', $expr, $matches)) {
            $func = strtolower($matches[1]);
            $args = $this->parseArguments($matches[2] ?? '', $context);
            return $this->callFunction($func, $args, $context);
        }

        if (str_starts_with($expr, '$')) {
            return $this->resolveVariable(substr($expr, 1), $context);
        }

        return $expr;
    }

    /**
     * @param string $var
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function resolveVariable(string $var, array $context)
    {
        $parts = explode('.', $var);
        $value = $context;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /**
     * @param string $args
     * @param array<string,mixed> $context
     * @return array<int,mixed>
     */
    private function parseArguments(string $args, array $context): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }

        $result = [];
        $depth = 0;
        $current = '';
        $length = strlen($args);
        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];
            if ($char === '(') {
                $depth++;
                $current .= $char;
                continue;
            }
            if ($char === ')') {
                $depth--;
                $current .= $char;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                $result[] = $this->evaluate($current, $context);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $result[] = $this->evaluate($current, $context);
        }
        return $result;
    }

    /**
     * @param array<int,mixed> $args
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function callFunction(string $name, array $args, array $context)
    {
        switch ($name) {
            case 'easy_checksum':
                return $this->funcEasyChecksum($args);
            case 'vault_name':
                return $this->funcVaultName($args, $context);
            case 'vault_path':
                return $this->funcVaultPath($args, $context);
            case 'now':
                return $this->now();
            default:
                throw new RuntimeException('filecatcher: unbekannte Funktion $func.' . $name);
        }
    }

    /**
     * @param array<int,mixed> $args
     */
    private function funcEasyChecksum(array $args): string
    {
        $mtime = (string)($args[0] ?? '');
        $size = (string)($args[1] ?? '');
        $algo = strtolower((string)($this->checksumConfig['algo'] ?? 'sha256'));
        $data = $mtime . '|' . $size;
        if ($algo === 'mtime_size_xxh64' && in_array('xxh64', hash_algos(), true)) {
            return hash('xxh64', $data);
        }
        if (in_array($algo, hash_algos(), true)) {
            return hash($algo, $data);
        }
        return hash('sha256', $data);
    }

    /**
     * @param array<int,mixed> $args
     * @param array<string,mixed> $context
     */
    private function funcVaultName(array $args, array $context): string
    {
        $template = (string)($args[0] ?? $this->filenamePattern);
        if ($template === '') {
            $template = $this->filenamePattern;
        }
        $row = $context['row'] ?? [];
        if (!is_array($row)) {
            $row = [];
        }
        $replacements = $this->buildPlaceholderContext($context, $row);
        $value = $this->replacePlaceholders($template, $replacements);
        $value = $this->sanitizeFilename($value);
        $ext = $context['src']['ext'] ?? null;
        if ($ext && !str_ends_with(strtolower($value), '.' . strtolower((string)$ext))) {
            $value .= '.' . $ext;
        }
        return $value;
    }

    /**
     * @param array<int,mixed> $args
     * @param array<string,mixed> $context
     */
    private function funcVaultPath(array $args, array $context): string
    {
        $storedPath = trim((string)($args[0] ?? ''));
        $storedFile = (string)($args[1] ?? '');
        if ($storedFile === '') {
            $storedFile = $this->buildVaultFilename($context, $context['row'] ?? []);
        }
        $base = rtrim($this->vaultBasePath, DIRECTORY_SEPARATOR);
        $destinationDir = $storedPath !== ''
            ? $base . DIRECTORY_SEPARATOR . ltrim($storedPath, DIRECTORY_SEPARATOR)
            : $base;
        return $destinationDir . DIRECTORY_SEPARATOR . $storedFile;
    }

    private function now(): string
    {
        return date(DATE_ATOM);
    }

    private function sanitizeFilename(string $value): string
    {
        $value = preg_replace('/[\\\\\\/]+/', '_', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9._\\-]+/', '_', $value) ?? $value;
        return trim($value, ' ._-');
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function buildPlaceholderContext(array $context, array $row): array
    {
        $placeholders = [];
        foreach (['src', 'row'] as $scope) {
            if (!isset($context[$scope]) || !is_array($context[$scope])) {
                continue;
            }
            foreach ($context[$scope] as $key => $value) {
                if (is_scalar($value)) {
                    $placeholders[$key] = (string)$value;
                }
            }
        }
        foreach ($row as $key => $value) {
            if (is_scalar($value)) {
                $placeholders[$key] = (string)$value;
            }
        }
        return $placeholders;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function normalizeList($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * @param array<int,mixed> $keys
     * @return array<int,string>
     */
    private function normalizeKeys($keys): array
    {
        return $this->normalizeList($keys);
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

    private function formatTime(int $timestamp): string
    {
        return date(DATE_ATOM, $timestamp);
    }

    private function relativeTo(string $path, string $base): string
    {
        $normalizedBase = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $normalizedBase)) {
            return substr($path, strlen($normalizedBase));
        }
        return $path;
    }

    /**
     * @param array<string,string> $replacements
     */
    private function replacePlaceholders(string $pattern, array $replacements): string
    {
        return preg_replace_callback('/\\{([^}]+)\\}/', function ($matches) use ($replacements) {
            $key = $matches[1];
            return $replacements[$key] ?? $matches[0];
        }, $pattern) ?? $pattern;
    }

    private function parseLiteral(string $literal)
    {
        $literal = trim($literal);
        if ($literal === '') {
            return '';
        }
        if (strcasecmp($literal, 'null') === 0) {
            return null;
        }
        if (strcasecmp($literal, 'true') === 0) {
            return true;
        }
        if (strcasecmp($literal, 'false') === 0) {
            return false;
        }
        if (is_numeric($literal)) {
            return strpos($literal, '.') !== false ? (float)$literal : (int)$literal;
        }
        return $literal;
    }

    /**
     * @param array<int,string> $keys
     * @param array<int,string> $insertColumns
     * @param array<int,string> $updateColumns
     */
    private function prepareUpsertStatement(PDO $pdo, string $table, array $keys, array $insertColumns, array $updateColumns): PDOStatement
    {
        $quotedTable = $this->quoteIdentifier($table);
        $insertPlaceholders = [];
        $quotedInsertColumns = [];
        foreach ($insertColumns as $column) {
            $quotedInsertColumns[] = $this->quoteIdentifier($column);
            $insertPlaceholders[] = ':' . $column;
        }

        $conflictKeys = [];
        foreach ($keys as $key) {
            $conflictKeys[] = $this->quoteIdentifier($key);
        }

        $updateAssignments = [];
        foreach ($updateColumns as $column) {
            $updateAssignments[] = sprintf('%s = :upd_%s', $this->quoteIdentifier($column), $column);
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s',
            $quotedTable,
            implode(', ', $quotedInsertColumns),
            implode(', ', $insertPlaceholders),
            implode(', ', $conflictKeys),
            implode(', ', $updateAssignments)
        );

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('filecatcher: Upsert-Statement konnte nicht vorbereitet werden: ' . $sql);
        }
        return $stmt;
    }
}
