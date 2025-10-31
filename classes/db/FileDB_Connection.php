<?php
declare(strict_types=1);

/**
 * Liest strukturierte Textdatei-basierte "FileDB"-Datenquellen.
 *
 * Erwartete Verzeichnisstruktur:
 *   <base_path>/<table_folder>/<primary_key>/<field><extension>
 *
 * Beispiel:
 *   srcFiles/Data/Artikel/12345/Meta_Title.txt
 */
final class FileDB_Connection
{
    private string $basePath;
    private string $extension;
    private string $encoding;
    private string $filenameCase;
    private bool $sanitizeFilenames;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(string $basePath, array $config = [])
    {
        $resolved = rtrim($basePath, DIRECTORY_SEPARATOR);
        if (!is_dir($resolved)) {
            throw new RuntimeException('FileDB base path not found: ' . $basePath);
        }
        $this->basePath = $resolved;
        $extension = (string)($config['extension'] ?? '.txt');
        if ($extension !== '' && $extension[0] !== '.') {
            $extension = '.' . $extension;
        }
        $this->extension = $extension;
        $this->encoding = strtolower((string)($config['encoding'] ?? 'utf-8'));
        $this->filenameCase = strtolower((string)($config['filename_case'] ?? 'preserve'));
        $this->sanitizeFilenames = (bool)($config['sanitize_filenames'] ?? false);
    }

    /**
     * Convenience factory für YAML-Definitionen.
     *
     * @param array<string,mixed> $config
     */
    public static function fromConfig(array $config, string $projectRoot): self
    {
        $source = $config['source'] ?? [];
        if (!is_array($source)) {
            $source = [];
        }

        $basePath = (string)($source['base_path'] ?? '');
        if ($basePath === '') {
            throw new RuntimeException('FileDB source definition requires "base_path".');
        }

        if (!self::isAbsolutePath($basePath)) {
            $basePath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($basePath, DIRECTORY_SEPARATOR);
        }

        return new self($basePath, $source);
    }

    /**
     * Lädt alle Datensätze einer Tabelle gemäß der YAML-Konfiguration.
     *
     * @param array<string,mixed> $tableConfig
     * @return array<int,array<string,mixed>>
     */
    public function fetchTable(string $tableName, array $tableConfig): array
    {
        $folder = (string)($tableConfig['folder'] ?? $tableName);
        if ($folder === '') {
            throw new RuntimeException(sprintf('FileDB table "%s" lacks folder definition.', $tableName));
        }

        $tablePath = $this->basePath . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($tablePath)) {
            throw new RuntimeException(sprintf('FileDB table folder not found: %s', $tablePath));
        }

        $keys = $tableConfig['keys'] ?? [];
        if (!is_array($keys) || $keys === []) {
            throw new RuntimeException(sprintf('FileDB table "%s" requires at least one key.', $tableName));
        }
        $keys = array_values(array_map('strval', $keys));

        $fields = $tableConfig['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $records = [];
        $entries = scandir($tablePath);
        if ($entries === false) {
            return [];
        }

        $separator = (string)($tableConfig['key_separator'] ?? '__');

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $recordPath = $tablePath . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($recordPath)) {
                continue;
            }

            $keyValues = $this->extractKeyValues($entry, $keys, $separator, $tableName);
            if ($keyValues === null) {
                continue;
            }

            $data = $keyValues;
            foreach ($fields as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $filePath = $recordPath . DIRECTORY_SEPARATOR . $this->fieldFilename($field);
                if (!is_file($filePath)) {
                    $data[$field] = null;
                    continue;
                }
                $data[$field] = $this->readFile($filePath);
            }

            $records[] = $data;
        }

        return $records;
    }

    private function extractKeyValues(string $folderName, array $keys, string $separator, string $tableName): ?array
    {
        if (count($keys) === 1) {
            return [$keys[0] => $this->restoreCase($folderName)];
        }

        $parts = explode($separator, $folderName);
        if (count($parts) !== count($keys)) {
            error_log(sprintf('[FileDB] Tabelle "%s": Erwartete %d Schlüssel, Ordner "%s" enthält %d Teile.', $tableName, count($keys), $folderName, count($parts)));
            return null;
        }

        $values = [];
        foreach ($keys as $idx => $key) {
            $values[$key] = $this->restoreCase($parts[$idx]);
        }
        return $values;
    }

    private function restoreCase(string $value): string
    {
        if ($this->filenameCase === 'lower') {
            return strtolower($value);
        }
        if ($this->filenameCase === 'upper') {
            return strtoupper($value);
        }
        return $value;
    }

    private function fieldFilename(string $field): string
    {
        $name = $field;
        if ($this->sanitizeFilenames) {
            $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?? $name;
        }

        if ($this->filenameCase === 'lower') {
            $name = strtolower($name);
        } elseif ($this->filenameCase === 'upper') {
            $name = strtoupper($name);
        }

        return $name . $this->extension;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('FileDB: Datei konnte nicht gelesen werden: ' . $path);
        }
        if ($this->encoding !== '' && $this->encoding !== 'utf-8' && function_exists('mb_convert_encoding')) {
            $content = mb_convert_encoding($content, 'UTF-8', $this->encoding);
        }
        return rtrim($content, "\r\n");
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');
    }
}
