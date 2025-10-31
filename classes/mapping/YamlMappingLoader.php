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
        if (function_exists('yaml_parse')) {
            $data = @yaml_parse($text);
            if (is_array($data)) {
                return $data;
            }
        }
        $tryJson = json_decode($text, true);
        if (is_array($tryJson)) {
            return $tryJson;
        }
        return self::parseSimpleYaml($text);
    }

    private static function parseSimpleYaml(string $yaml): array
    {
        $lines = preg_split("/\r?\n/", $yaml) ?: [];
        $root = [];
        $stack = [ [ &$root, -1 ] ];

        foreach ($lines as $rawLine) {
            if ($rawLine === '' || preg_match('/^\s*#/', $rawLine)) {
                continue;
            }
            $line = rtrim($rawLine, "\r\n");
            $indent = (int)(strlen($line) - strlen(ltrim($line, ' ')));
            $trim = ltrim($line, ' ');

            while (count($stack) > 1 && $indent <= $stack[count($stack) - 1][1]) {
                array_pop($stack);
            }
            [$parent, $parentIndent] = $stack[count($stack) - 1];

            if (strpos($trim, '- ') === 0) {
                $item = substr($trim, 2);
                if (!is_array($parent)) {
                    $parent = [];
                }
                $parent[] = self::parseInline($item);
                $stack[count($stack) - 1][0] = $parent;
                if (substr($item, -1) === ':') {
                    $idx = count($parent) - 1;
                    $parent[$idx] = [];
                    $stack[] = [ &$parent[$idx], $indent ];
                }
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_\-.]+):\s*(.*)$/', $trim, $match)) {
                $key = $match[1];
                $val = $match[2];
                if (!is_array($parent)) {
                    $parent = [];
                }
                if ($val === '') {
                    $parent[$key] = [];
                    $stack[count($stack) - 1][0] = $parent;
                    $stack[] = [ &$parent[$key], $indent ];
                } else {
                    $parent[$key] = self::parseInline($val);
                    $stack[count($stack) - 1][0] = $parent;
                }
                continue;
            }
        }

        return $root;
    }

    private static function parseInline(string $value)
    {
        $value = trim($value);
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value === 'null' || $value === '~') {
            return null;
        }
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }
        return $value;
    }
}
