<?php
declare(strict_types=1);

/**
 * TransformRegistry
 * 
 * Central registry for data transformation rules and mappings.
 * This class will manage the registration and retrieval of transformation
 * rules between different data sources and targets.
 */
class TransformRegistry
{
    /**
     * Registry of transformation rules
     * @var array
     */
    private array $transformations = [];
    private ?\PDO $sqlite = null;

    /**
     * Constructor - registers default transformations
     */
    public function __construct()
    {
        $this->registerDefaultTransformations();
    }

    /**
     * Register default transformations
     */
    private function registerDefaultTransformations(): void
    {
        // Trim whitespace
        $this->register('trim', function($value) {
            return is_string($value) ? trim($value) : $value;
        });

        // Extract basename from path
        $this->register('basename', function($value) {
            if ($value === null || $value === '') {
                return $value;
            }
            $val = (string)$value;
            // Normalize path separators
            $val = strtr($val, ['\\\\' => '/', '\\' => '/']);
            return basename($val);
        });

        // Convert RTF to simplified HTML/text
        $this->register('rtf_to_html', function($value) {
            if ($value === null) {
                return null;
            }
            $stringValue = (string)$value;
            if ($stringValue === '') {
                return '';
            }
            return $this->convertRtfToHtml($stringValue);
        });

        // Remove HTML tags
        $this->register('remove_html', function($value) {
            if ($value === null || $value === '') {
                return $value;
            }
            $val = (string)$value;
            
            // First apply RTF conversion if needed
            if (strpos($val, '{\\rtf') !== false) {
                $val = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $val);
                $val = str_replace(['{', '}', '\\'], '', $val);
                $val = trim(preg_replace('/\s+/', ' ', $val));
            }
            
            return strip_tags($val);
        });

        // Normalize title (trim and basename)
        $this->register('normalize_title', function($value) {
            $trimmed = trim((string)$value);
            if ($trimmed === '') {
                return '';
            }
            $standardized = strtr($trimmed, ['\\' => '/', '//' => '/']);
            return basename($standardized);
        });

        // Slugify (lowercase, hyphen separated)
        $this->register('slugify', function($value) {
            $str = strtolower(trim((string)$value));
            if ($str === '') {
                return '';
            }
            // German specifics and symbols
            $str = strtr($str, [
                '&' => ' und ',
                'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
                'ß' => 'ss',
            ]);
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $str);
                if ($converted !== false) {
                    $str = strtolower($converted);
                }
            }
            // Replace any non a-z0-9 with hyphen and collapse duplicates
            $str = preg_replace('/[^a-z0-9]+/', '-', $str ?? '') ?? '';
            $str = trim($str, '-');
            $str = preg_replace('/-+/', '-', $str) ?? $str;
            return $str;
        });

        $self = $this;
        $this->register('transform_rtf_to_html', function($value) use ($self) {
            return $self->apply('rtf_to_html', $value);
        });

        // Null wenn leerer String
        $this->register('null_if_empty', function($value) {
            if (!is_scalar($value) && $value !== null) {
                return $value;
            }
            $str = trim((string)$value);
            return $str === '' ? null : $value;
        });

        // Zu Dezimalzahl (locale-unabhängig)
        $this->register('to_decimal', function($value) {
            if ($value === null || $value === '') {
                return null;
            }
            $normalized = str_replace(',', '.', (string)$value);
            if (!is_numeric($normalized)) {
                return null;
            }
            return (float)$normalized;
        });

        // Integer-Konvertierung
        $this->register('to_int', function($value) {
            if ($value === null || $value === '') {
                return null;
            }
            if (is_numeric($value)) {
                return (int)$value;
            }
            return null;
        });

        // Boolean -> int (true=1/false=0)
        $this->register('bool_to_int', function($value) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (is_numeric($value)) {
                return ((int)$value) === 1 ? 1 : 0;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'ja', 'y'], true)) {
                    return 1;
                }
            }
            return 0;
        });

        $self = $this;

        $this->register('resolve_category_id', function($value) use ($self) {
            static $cache = [];
            $key = trim((string)$value);
            if ($key === '') {
                return 0;
            }
            if (array_key_exists($key, $cache)) {
                return $cache[$key];
            }
            try {
                $stmt = $self->sqlite()->prepare('SELECT id FROM category WHERE afs_id = :id LIMIT 1');
                if ($stmt && $stmt->execute([':id' => $key])) {
                    $id = $stmt->fetchColumn();
                    if ($id !== false && $id !== null) {
                        return $cache[$key] = (int)$id;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return $cache[$key] = 0;
        });

        $this->register('article_seo_slug', function($model, $warengruppe, $name, $masterModel = null) use ($self) {
            $modelStr = trim((string)$model);
            if ($modelStr !== '') {
                try {
                    $stmt = $self->sqlite()->prepare('SELECT seo_slug FROM artikel WHERE model = :m LIMIT 1');
                    if ($stmt && $stmt->execute([':m' => $modelStr])) {
                        $existing = $stmt->fetchColumn();
                        if (is_string($existing) && trim($existing) !== '') {
                            return trim($existing);
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $categoryId = $self->resolveCategoryIdValue($warengruppe);

            $masterStr = trim((string)$masterModel);
            if ($masterStr !== '' && strcasecmp($masterStr, 'master') !== 0) {
                try {
                    $stmt = $self->sqlite()->prepare('SELECT category FROM artikel WHERE model = :m LIMIT 1');
                    if ($stmt && $stmt->execute([':m' => $masterStr])) {
                        $cat = $stmt->fetchColumn();
                        if ($cat !== false && $cat !== null) {
                            $categoryId = (int)$cat;
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            $categorySlug = $self->lookupCategorySlug($categoryId);
            if ($categorySlug === '') {
                $categorySlug = 'de';
            }

            $articleSlug = $self->slugify((string)$name);
            $base = trim($categorySlug, '/');
            $full = $base !== '' ? $base : 'de';
            if ($articleSlug !== '') {
                $full .= '/' . $articleSlug;
            }
            return $full;
        });

        $this->register('article_meta_title_default', function($name) {
            return trim((string)$name);
        });

        $this->register('article_meta_description_default', function($name) {
            $value = trim((string)$name);
            if ($value === '') {
                return '';
            }
            return $value . ' &Iota; hohe Qualität &#2705; schnelle Lieferung &#2705; langlebig &#2705; &#10148; Jetzt bei Welafix kaufen!';
        });

        $this->register('category_meta_title_default', function($name) {
            $value = trim((string)$name);
            if ($value === '') {
                return '';
            }
            return $value . ' &Iota; Hier Produktvielfalt entdecken!';
        });

        $this->register('category_meta_description_default', function($name) {
            $value = trim((string)$name);
            if ($value === '') {
                return '';
            }
            return $value . ' &Iota; breites Sortiment &#2705; schnelle Lieferung &#2705; langlebig &#2705; &#10148; Jetzt bei Welafix kaufen!';
        });

    }

    /**
     * Register a new transformation rule
     * 
     * @param string $name Unique name for the transformation
     * @param callable $transformer The transformation function
     * @return void
     */
    public function register(string $name, callable $transformer): void
    {
        $this->transformations[$name] = $transformer;
    }

    /**
     * Get a registered transformation by name
     * 
     * @param string $name Name of the transformation
     * @return callable|null The transformation function or null if not found
     */
    public function get(string $name): ?callable
    {
        return $this->transformations[$name] ?? null;
    }

    /**
     * Check if a transformation is registered
     * 
     * @param string $name Name of the transformation
     * @return bool True if the transformation exists
     */
    public function has(string $name): bool
    {
        return isset($this->transformations[$name]);
    }

    /**
     * Apply a transformation to a value
     * 
     * @param string $name Name of the transformation
     * @param mixed $value Value to transform
     * @return mixed Transformed value
     */
    public function apply(string $name, $value)
    {
        $transformer = $this->get($name);
        if ($transformer === null) {
            return $value;
        }
        
        try {
            return $transformer($value);
        } catch (\Throwable $e) {
            // Log the error before returning the original value
            error_log(sprintf(
                "TransformRegistry::apply() error in '%s': %s in %s on line %d\nStack trace:\n%s",
                $name,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));
            return $value;
        }
    }

    /**
     * Convert basic RTF markup to simplified HTML/text.
     */
    private function convertRtfToHtml(string $rtf): string
    {
        $trimmed = trim($rtf);
        if ($trimmed === '') {
            return '';
        }

        $hasRtfHeader = str_contains($trimmed, '{\\rtf');
        if (!$hasRtfHeader) {
            return $this->finalizeRtfPlainText($trimmed);
        }

        $encoding = $this->detectRtfEncoding($trimmed);
        $rtf = $this->removeRtfGroups($trimmed, [
            'fonttbl',
            'colortbl',
            'stylesheet',
            'info',
            'header',
            'footer',
            'generator',
            'pict',
        ]);

        $rtf = $this->replaceSymbolicControls($rtf);
        $rtf = $this->decodeRtfEntities($rtf, $encoding);

        // Convert common paragraph/line/tab control words before stripping others
        $rtf = preg_replace('/\\\\par[d]?/', "\n", $rtf) ?? $rtf;
        $rtf = preg_replace('/\\\\line/', "\n", $rtf) ?? $rtf;
        $rtf = preg_replace('/\\\\tab/', "\t", $rtf) ?? $rtf;
        $rtf = preg_replace('/\\\\emdash/', '—', $rtf) ?? $rtf;
        $rtf = preg_replace('/\\\\endash/', '–', $rtf) ?? $rtf;

        // Remove remaining control words/backslashes
        $rtf = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $rtf) ?? $rtf;
        $rtf = str_replace(['\\{', '\\}'], ['{', '}'], $rtf);
        $rtf = str_replace(['{', '}', '\\'], '', $rtf);

        return $this->finalizeRtfPlainText($rtf);
    }

    /**
     * Remove RTF groups (e.g. font table, color table, optional groups).
     *
     * @param array<int,string> $keywords
     */
    private function removeRtfGroups(string $rtf, array $keywords): string
    {
        $length = strlen($rtf);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $rtf[$i];
            if ($char === '{') {
                $j = $i + 1;
                $skipGroup = false;

                if ($j < $length && $rtf[$j] === '\\') {
                    $j++;
                    if ($j < $length && $rtf[$j] === '*') {
                        $skipGroup = true;
                    } else {
                        $keyword = '';
                        while ($j < $length && ctype_alpha($rtf[$j])) {
                            $keyword .= $rtf[$j];
                            $j++;
                        }
                        if ($keyword !== '' && in_array(strtolower($keyword), $keywords, true)) {
                            $skipGroup = true;
                        }
                    }
                }

                if ($skipGroup) {
                    $depth = 1;
                    for ($k = $i + 1; $k < $length; $k++) {
                        $token = $rtf[$k];
                        if ($token === '{') {
                            $depth++;
                        } elseif ($token === '}') {
                            $depth--;
                            if ($depth === 0) {
                                $i = $k;
                                break;
                            }
                        }
                    }
                    continue;
                }
            }
            $result .= $char;
        }

        return $result;
    }

    /**
     * Translate symbolic control words to plain text equivalents.
     */
    private function replaceSymbolicControls(string $rtf): string
    {
        $replacements = [
            '\\~'        => ' ',
            '\\_'        => '-',
            '\\emdash'   => '—',
            '\\endash'   => '–',
            '\\lquote'   => '‘',
            '\\rquote'   => '’',
            '\\ldblquote'=> '“',
            '\\rdblquote'=> '”',
            '\\bullet'   => '•',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $rtf);
    }

    /**
     * Decode hex and unicode escape sequences within the RTF payload.
     */
    private function decodeRtfEntities(string $rtf, string $encoding): string
    {
        $encoding = $encoding !== '' ? $encoding : 'CP1252';

        $rtf = preg_replace('/\\\\uc\d+/', '', $rtf) ?? $rtf;

        $rtf = preg_replace_callback(
            '/\\\\\'([0-9a-fA-F]{2})/',
            function(array $matches) use ($encoding): string {
                $byte = chr(hexdec($matches[1]));
                if (function_exists('mb_convert_encoding')) {
                    $converted = @mb_convert_encoding($byte, 'UTF-8', $encoding);
                    if ($converted !== false) {
                        return $converted;
                    }
                }
                if (function_exists('iconv')) {
                    $converted = @iconv($encoding, 'UTF-8//IGNORE', $byte);
                    if ($converted !== false) {
                        return $converted;
                    }
                }
                return $byte;
            },
            $rtf
        ) ?? $rtf;

        $rtf = preg_replace_callback(
            '/\\\\u(-?\d+)\??/',
            static function(array $matches): string {
                $code = (int)$matches[1];
                if ($code < 0) {
                    $code += 65536;
                }
                if ($code === 0) {
                    return '';
                }
                $entity = '&#' . $code . ';';
                if (function_exists('mb_convert_encoding')) {
                    $converted = @mb_convert_encoding($entity, 'UTF-8', 'HTML-ENTITIES');
                    if ($converted !== false) {
                        return $converted;
                    }
                }
                return html_entity_decode($entity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $rtf
        ) ?? $rtf;

        return $rtf;
    }

    /**
     * Final cleanup: collapse whitespace and normalize paragraph breaks.
     */
    private function finalizeRtfPlainText(string $text): string
    {
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*[\w\s\-,.]+;/', '', $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $lines = array_map('trim', explode("\n", $text));
        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        if ($lines === []) {
            return '';
        }

        return implode('<br>', $lines);
    }

    /**
     * Detect declared ANSI code page within the RTF header.
     */
    private function detectRtfEncoding(string $rtf): string
    {
        if (preg_match('/\\\ansicpg(\d+)/i', $rtf, $matches)) {
            return 'CP' . $matches[1];
        }

        return 'CP1252';
    }

    private function sqlite(): \PDO
    {
        if ($this->sqlite === null) {
            $path = dirname(__DIR__, 2) . '/db/evo.db';
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->sqlite = $pdo;
        }
        return $this->sqlite;
    }

    private function resolveCategoryIdValue($value): int
    {
        $key = trim((string)$value);
        if ($key === '') {
            return 0;
        }
        try {
            $stmt = $this->sqlite()->prepare('SELECT id FROM category WHERE afs_id = :id LIMIT 1');
            if ($stmt && $stmt->execute([':id' => $key])) {
                $id = $stmt->fetchColumn();
                if ($id !== false && $id !== null) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return 0;
    }

    private function lookupCategorySlug(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }
        try {
            $stmt = $this->sqlite()->prepare('SELECT seo_slug FROM category WHERE id = :id LIMIT 1');
            if ($stmt && $stmt->execute([':id' => $categoryId])) {
                $slug = $stmt->fetchColumn();
                if (is_string($slug) && trim($slug) !== '') {
                    return trim($slug);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = strtr($value, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            'ß' => 'ss',
        ]);
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }
}
