<?php
declare(strict_types=1);

final class YamlMappingLoader
{
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('YAML-Datei nicht gefunden: ' . $path);
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException('YAML-Datei konnte nicht gelesen werden: ' . $path);
        }
        // Prefer native yaml extension when available
        if (function_exists('yaml_parse')) {
            $data = @yaml_parse($text);
            if (is_array($data)) return $data;
        }
        // Fallback: try JSON if the file actually is JSON
        $tryJson = json_decode($text, true);
        if (is_array($tryJson)) {
            return $tryJson;
        }
        // Minimal YAML parser (supports: key: value, nested maps, lists '- ')
        return self::parseSimpleYaml($text);
    }

    private static function parseSimpleYaml(string $yaml): array
    {
        $lines = preg_split("/\r?\n/", $yaml) ?: [];
        $root = [];
        $stack = [ [&$root, -1] ]; // [nodeRef, indent]

        foreach ($lines as $rawLine) {
            if ($rawLine === '' || preg_match('/^\s*#/', $rawLine)) continue;
            $line = rtrim($rawLine, "\r\n");
            $indent = (int) (strlen($line) - strlen(ltrim($line, ' ')));
            $trim = ltrim($line, ' ');
            // pop to correct level
            while (count($stack) > 1 && $indent <= $stack[count($stack)-1][1]) {
                array_pop($stack);
            }
            [$parent, $parentIndent] = &$stack[count($stack)-1];

            // list item
            if (strpos($trim, '- ') === 0) {
                $item = substr($trim, 2);
                if (!is_array($parent)) { $parent = []; }
                // ensure parent is list (numeric)
                $parent[] = self::parseInline($item);
                // If item ends with ':' create nested map
                if (substr($item, -1) === ':') {
                    $idx = count($parent) - 1;
                    $parent[$idx] = [];
                    $stack[] = [&$parent[$idx], $indent];
                }
                unset($parent, $parentIndent);
                continue;
            }

            // key: value
            if (preg_match('/^([A-Za-z0-9_\-.]+):\s*(.*)$/', $trim, $m)) {
                $key = $m[1];
                $val = $m[2];
                if (!is_array($parent)) { $parent = []; }
                if ($val === '') {
                    $parent[$key] = [];
                    $stack[] = [&$parent[$key], $indent];
                } else {
                    $parent[$key] = self::parseInline($val);
                }
                unset($parent, $parentIndent);
                continue;
            }
            // otherwise ignore unsupported constructs
        }
        return $root;
    }

    private static function parseInline(string $val)
    {
        $val = trim($val);
        if ($val === 'true') return true;
        if ($val === 'false') return false;
        if ($val === 'null' || $val === '~') return null;
        if (is_numeric($val)) {
            return strpos($val, '.') !== false ? (float)$val : (int)$val;
        }
        // strip matching quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            return substr($val, 1, -1);
        }
        return $val;
    }
}
