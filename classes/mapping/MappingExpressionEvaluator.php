<?php
declare(strict_types=1);

/**
 * Evaluates mapping expressions like
 *   AFS.Artikel.Artikelnummer | trim | null_if_empty
 * or with functions
 *   AFS.Artikel.Internet | case(1->1, else->0)
 */
class MappingExpressionEvaluator
{
    private TransformRegistry $transformRegistry;
    /** @var array<string,string>|null */
    private ?array $categoryMetaIndex = null;
    private ?string $categoryMetaBasePath = null;

    public function __construct(?TransformRegistry $registry = null)
    {
        $this->transformRegistry = $registry ?? new TransformRegistry();
    }

    /**
     * Bereitet einen Ausdruck für wiederholte Auswertung vor.
     *
     * @return array{base:string,transforms:array<int,string>}|null
     */
    public function compile(string $expression): ?array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        $segments = [];
        $buffer = '';
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
                $buffer .= $char;
                continue;
            }
            if ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
                $buffer .= $char;
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote) {
                if ($char === '(') {
                    $depth++;
                    $buffer .= $char;
                    continue;
                }
                if ($char === ')' && $depth > 0) {
                    $depth--;
                    $buffer .= $char;
                    continue;
                }
                if ($char === '|' && $depth === 0) {
                    $segment = trim($buffer);
                    if ($segment !== '') {
                        $segments[] = $segment;
                    }
                    $buffer = '';
                    continue;
                }
            }

            $buffer .= $char;
        }

        $buffer = trim($buffer);
        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        if ($segments === []) {
            return null;
        }

        $base = array_shift($segments);
        return [
            'base' => $base ?? '',
            'transforms' => array_values($segments),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array{base:string,transforms:array<int,string>}|null $compiled
     */
    public function evaluateCompiled(?array $compiled, array $context)
    {
        if ($compiled === null) {
            return null;
        }

        $value = $this->resolveReference($compiled['base'], $context);
        foreach ($compiled['transforms'] as $segment) {
            $value = $this->applyTransformation($segment, $value, $context);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $context
     */
    public function evaluate(string $expression, array $context)
    {
        $compiled = $this->compile($expression);
        return $this->evaluateCompiled($compiled, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function resolveReference(string $reference, array $context)
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        // Literal numbers or strings in quotes
        if (preg_match('/^[-+]?\d+(\.\d+)?$/', $reference)) {
            return strpos($reference, '.') !== false ? (float)$reference : (int)$reference;
        }
        if ((str_starts_with($reference, '"') && str_ends_with($reference, '"'))
            || (str_starts_with($reference, "'") && str_ends_with($reference, "'"))
        ) {
            return substr($reference, 1, -1);
        }

        if (strcasecmp($reference, 'null') === 0 || $reference === '~') {
            return null;
        }

        if (str_starts_with($reference, '=')) {
            return $this->parseLiteral(substr($reference, 1));
        }

        if (str_starts_with($reference, '$func.')) {
            return $this->evaluateFunctionReference(substr($reference, 6), $context);
        }

        $parts = explode('.', $reference);
        $current = $context;
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
                continue;
            }
            return null;
        }
        return $current;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function evaluateFunctionReference(string $reference, array $context)
    {
        if ($reference === '') {
            return null;
        }
        if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/', $reference, $matches)) {
            throw new RuntimeException('Ungültiger Funktionsaufruf: $func.' . $reference);
        }
        $name = strtolower($matches[1]);
        $args = $this->parseFunctionArguments($matches[2] ?? '', $context);
        return $this->invokeNamedFunction($name, $args, $context);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,mixed>
     */
    private function parseFunctionArguments(string $arguments, array $context): array
    {
        $arguments = trim($arguments);
        if ($arguments === '') {
            return [];
        }

        $result = [];
        $current = '';
        $depth = 0;
        $length = strlen($arguments);
        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
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
                $result[] = $this->evaluate(trim($current), $context);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if ($current !== '') {
            $result[] = $this->evaluate(trim($current), $context);
        }
        return $result;
    }

    /**
     * @param array<int,mixed> $args
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function invokeNamedFunction(string $name, array $args, array $context)
    {
        switch ($name) {
            case 'transform_rtf_to_html':
                return $this->transformRegistry->apply('rtf_to_html', $args[0] ?? null);
            case 'now':
                return date(DATE_ATOM);
            case 'concat':
                return $this->concatValues($args);
            case 'media_entity_type':
                return $this->determineMediaEntityType($args[0] ?? null, $args[1] ?? null, $args[2] ?? null);
            case 'media_entity_id':
                return $this->determineMediaEntityId($args[0] ?? null, $args[1] ?? null, $args[2] ?? null);
            case 'coalesce':
                return $this->coalesce($args);
            case 'tax_map':
                return $this->mapTaxClass($args[0] ?? null);
            case 'category_meta_title':
                return $this->lookupCategoryMeta($args[0] ?? null, 'Meta_Title', $args[1] ?? null);
            case 'category_meta_description':
                return $this->lookupCategoryMeta($args[0] ?? null, 'Meta_Description', $args[1] ?? null);
            case 'article_master_flag':
                return $this->determineMasterFlag($args[0] ?? null);
            case 'article_master_number':
                return $this->determineMasterNumber($args[0] ?? null);
            case 'media_detect_type':
                return $this->detectMediaType($args[0] ?? null);
            case 'media_extract_article':
                return $this->extractMediaArticleId($args[0] ?? null);
            case 'media_extract_category':
                return $this->extractMediaCategoryId($args[0] ?? null);
            case 'image_guard':
                return $this->guardImageValue($args[0] ?? null, $args[1] ?? null);
            case 'document_guard':
                return $this->guardDocumentValue($args[0] ?? null, $args[1] ?? null);
            case 'category_path':
                $id = $args[0] ?? null;
                $idStr = is_scalar($id) ? trim((string)$id) : '';
                $paths = $context['__category_paths'] ?? null;
                if (is_array($paths) && $idStr !== '' && isset($paths[$idStr])) {
                    return (string)$paths[$idStr];
                }
                return $idStr !== '' ? $idStr : null;
            case 'category_slug':
                $id = $args[0] ?? null;
                $idStr = is_scalar($id) ? trim((string)$id) : '';
                $paths = $context['__category_paths'] ?? null;
                if (is_array($paths) && $idStr !== '' && isset($paths[$idStr])) {
                    return 'de/' . (string)$paths[$idStr];
                }
                return $idStr !== '' ? ('de/' . $idStr) : null;
            case 'resolve_category_id':
            case 'article_meta_title_default':
            case 'article_meta_description_default':
            case 'category_meta_title_default':
            case 'category_meta_description_default':
                $fn = $this->transformRegistry->get($name);
                return $fn ? $fn($args[0] ?? null) : null;
            case 'article_seo_slug':
                $fn = $this->transformRegistry->get($name);
                return $fn ? $fn($args[0] ?? null, $args[1] ?? null, $args[2] ?? null, $args[3] ?? null) : null;
            default:
                $value = $args[0] ?? null;
                return $this->transformRegistry->apply($name, $value);
        }
    }

    /**
     * @param array<int,mixed> $parts
     */
    private function concatValues(array $parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }
            if (is_bool($part)) {
                $result .= $part ? '1' : '0';
                continue;
            }
            $result .= (string)$part;
        }
        return $result;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function coalesce(array $values)
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return $value;
        }
        return null;
    }

    private function determineMediaEntityType($type, $article, $category): ?string
    {
        $resolved = $this->resolveMediaRelation($type, $article, $category);
        return $resolved['type'] !== '' ? $resolved['type'] : null;
    }

    private function determineMediaEntityId($type, $article, $category): ?string
    {
        $resolved = $this->resolveMediaRelation($type, $article, $category);
        return $resolved['id'] !== '' ? $resolved['id'] : null;
    }

    /**
     * @param mixed $type
     * @param mixed $article
     * @param mixed $category
     * @return array{type:string,id:string}
     */
    private function resolveMediaRelation($type, $article, $category): array
    {
        $normalizedType = null;
        if (is_numeric($type)) {
            $normalizedType = (int)$type;
        } elseif (is_string($type) && $type !== '') {
            $normalizedType = (int)$type;
        }

        $articleId = trim((string)($article ?? ''));
        $categoryId = trim((string)($category ?? ''));

        if ($normalizedType === 1) {
            return $articleId !== '' ? ['type' => 'article', 'id' => $articleId] : ['type' => '', 'id' => ''];
        }
        if ($normalizedType === 2) {
            return $categoryId !== '' ? ['type' => 'category', 'id' => $categoryId] : ['type' => '', 'id' => ''];
        }

        if ($articleId !== '') {
            return ['type' => 'article', 'id' => $articleId];
        }
        if ($categoryId !== '') {
            return ['type' => 'category', 'id' => $categoryId];
        }

        return ['type' => '', 'id' => ''];
    }

    private function normalizeMediaPath($value): string
    {
        $path = (string)$value;
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path ?? '') ?? '';
        return trim($path, '/');
    }

    /**
     * @param mixed $value
     */
    private function detectMediaType($value): int
    {
        $info = $this->extractMediaInfo($value);
        return $info['type'];
    }

    /**
     * @param mixed $value
     */
    private function extractMediaArticleId($value): ?string
    {
        $info = $this->extractMediaInfo($value);
        return $info['article'] !== '' ? $info['article'] : null;
    }

    /**
     * @param mixed $value
     */
    private function extractMediaCategoryId($value): ?string
    {
        $info = $this->extractMediaInfo($value);
        return $info['category'] !== '' ? $info['category'] : null;
    }

    /**
     * @param mixed $value
     * @return array{type:int,article:string,category:string}
     */
    private function extractMediaInfo($value): array
    {
        $path = $this->normalizeMediaPath($value);
        if ($path === '') {
            return ['type' => 0, 'article' => '', 'category' => ''];
        }
        $segments = explode('/', $path);
        $type = 0;
        $article = '';
        $category = '';

        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            $segment = strtolower($segments[$i]);
            if ($segment === 'artikel' && $i + 1 < $count) {
                $candidate = trim($segments[$i + 1]);
                if ($candidate !== '') {
                    $article = $candidate;
                    $type = 1;
                    break;
                }
            }
            if ($segment === 'warengruppen' && $i + 1 < $count) {
                $candidate = trim($segments[$i + 1]);
                if ($candidate !== '') {
                    $category = $candidate;
                    $type = $type === 1 ? 1 : 2;
                    // nicht breaken um ggf. Artikel später zu finden
                }
            }
        }

        return ['type' => $type, 'article' => $article, 'category' => $category];
    }

    private function guardImageValue($mime, $value)
    {
        $mime = strtolower(trim((string)$mime));
        if ($mime !== '' && !str_starts_with($mime, 'image/')) {
            return null;
        }
        return $value;
    }

    private function guardDocumentValue($mime, $value)
    {
        $mime = strtolower(trim((string)$mime));
        if ($mime !== '' && str_starts_with($mime, 'image/')) {
            return null;
        }
        return $value;
    }

    private function mapTaxClass($value): int
    {
        if ($value === null) {
            return 0;
        }
        $normalized = (float)$value;
        if (abs($normalized - 19.0) < 0.1) {
            return 1;
        }
        if (abs($normalized - 7.0) < 0.1) {
            return 2;
        }
        if (abs($normalized) < 0.1) {
            return 0;
        }
        return (int)round($normalized);
    }

    private function applyTransformation(string $segment, $value, array $context = [])
    {
        $segment = trim($segment);
        if ($segment === '') {
            return $value;
        }

        // Check for function syntax: name(args)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/', $segment, $matches)) {
            $name = $matches[1];
            $args = trim($matches[2]);
            return $this->applyFunction($name, $value, $args, $context);
        }

        // Check for colon syntax: name:args (e.g., default:'text')
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*):(.+)$/s', $segment, $matches)) {
            $name = $matches[1];
            $args = $matches[2]; // Don't trim here to preserve leading/trailing spaces in expressions
            return $this->applyFunction($name, $value, $args, $context);
        }

        return $this->transformRegistry->apply($segment, $value);
    }

    private function applyFunction(string $name, $value, string $args, array $context = [])
    {
        switch (strtolower($name)) {
            case 'round':
                $precision = (int)trim($args);
                if (!is_numeric($value)) {
                    return null;
                }
                return round((float)$value, $precision);
            case 'case':
                return $this->evaluateCase($value, $args);
            case 'default':
                return $this->evaluateDefault($value, $args, $context);
            default:
                // Versuch, Funktionen ohne Parameter-Parsing über Registry abzudecken
                return $this->transformRegistry->apply($name, $value);
        }
    }

    private function evaluateCase($value, string $caseBody)
    {
        $valueStr = $this->stringify($value);
        $elseValue = $value;
        $segments = array_map('trim', explode(',', $caseBody));
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!str_contains($segment, '->')) {
                continue;
            }
            [$key, $result] = array_map('trim', explode('->', $segment, 2));
            if (strcasecmp($key, 'else') === 0) {
                $elseValue = $this->parseLiteral($result);
                continue;
            }
            if ($this->compareCaseKey($valueStr, $key)) {
                return $this->parseLiteral($result);
            }
        }
        return $elseValue;
    }

    /**
     * Evaluates default pipe: returns the fallback if value is null or empty string.
     * Supports static values and dynamic expressions like $func.concat(...) or afs.Field
     */
    private function evaluateDefault($value, string $fallbackExpression, array $context = [])
    {
        // Check if value is null or empty string
        if ($value !== null && (!is_string($value) || trim($value) !== '')) {
            return $value;
        }

        $fallbackExpression = trim($fallbackExpression);
        if ($fallbackExpression === '') {
            return $value;
        }

        // Check if fallback is a dynamic expression that should be evaluated
        // Look for: $func., field references (contains .), function calls (contains ()),
        // or arithmetic operators (+)
        if ($this->isDynamicExpression($fallbackExpression)) {
            // Evaluate as expression with provided context
            return $this->evaluate($fallbackExpression, $context);
        }

        // Static value - parse as literal
        return $this->parseLiteral($fallbackExpression);
    }

    /**
     * Check if an expression should be evaluated dynamically
     */
    private function isDynamicExpression(string $expression): bool
    {
        $expression = trim($expression);
        
        // Check for function calls: $func.
        if (str_starts_with($expression, '$func.')) {
            return true;
        }
        
        // Check for field references (contains dot but not wrapped in quotes)
        if (str_contains($expression, '.') && 
            !((str_starts_with($expression, '"') && str_ends_with($expression, '"')) ||
              (str_starts_with($expression, "'") && str_ends_with($expression, "'")))) {
            return true;
        }
        
        // Check for function call syntax (contains parentheses)
        if (str_contains($expression, '(') && str_contains($expression, ')')) {
            return true;
        }
        
        // Check for arithmetic operators (but not at start for negative numbers)
        if (preg_match('/[^0-9\s][+\-*\/]/', $expression)) {
            return true;
        }
        
        return false;
    }

    private function compareCaseKey(string $value, string $key): bool
    {
        $keyNormalized = strtolower($key);
        if ($keyNormalized === 'true' || $keyNormalized === 'false') {
            $boolVal = in_array($value, ['1', 'true'], true);
            return $boolVal === ($keyNormalized === 'true');
        }
        return $value === $this->stringify($this->parseLiteral($key));
    }

    private function stringify($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return '';
    }

    private function parseLiteral(string $literal)
    {
        $literal = trim($literal);
        if ($literal === '') {
            return '';
        }
        if (strcasecmp($literal, 'null') === 0 || $literal === '~') {
            return null;
        }
        if (strcasecmp($literal, 'true') === 0) {
            return true;
        }
        if (strcasecmp($literal, 'false') === 0) {
            return false;
        }
        if (preg_match('/^[-+]?\d+(\.\d+)?$/', $literal)) {
            return strpos($literal, '.') !== false ? (float)$literal : (int)$literal;
        }
        if ((str_starts_with($literal, '"') && str_ends_with($literal, '"'))
            || (str_starts_with($literal, "'") && str_ends_with($literal, "'"))
        ) {
            return substr($literal, 1, -1);
        }
        return $literal;
    }

    /**
     * @param mixed $name
     * @param mixed $fallback
     */
    private function lookupCategoryMeta($name, string $field, $fallback)
    {
        $categoryName = is_scalar($name) ? trim((string)$name) : '';
        if ($categoryName === '') {
            return $this->processCategoryFallback($fallback, $field, '');
        }

        $basePath = $this->getCategoryMetaBasePath();
        if ($basePath === null) {
            return $this->processCategoryFallback($fallback, $field, $categoryName);
        }

        if ($this->categoryMetaIndex === null) {
            $this->categoryMetaIndex = $this->buildCategoryMetaIndex($basePath);
        }

        if ($this->categoryMetaIndex === []) {
            return $this->processCategoryFallback($fallback, $field, $categoryName);
        }

        $folder = $this->resolveCategoryMetaFolder($categoryName, $this->categoryMetaIndex);
        if ($folder === null) {
            return $this->processCategoryFallback($fallback, $field, $categoryName);
        }

        $filename = $field === 'Meta_Title' ? 'Meta_Title.txt' : 'Meta_Description.txt';
        $path = $folder . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            return $this->processCategoryFallback($fallback, $field, $categoryName);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->processCategoryFallback($fallback, $field, $categoryName);
        }
        $content = trim($content);

        if ($content !== '') {
            return $this->applyCategoryPlaceholders($content, $categoryName);
        }

        return $this->processCategoryFallback($fallback, $field, $categoryName);
    }

    /**
     * @param mixed $fallback
     * @return mixed
     */
    private function processCategoryFallback($fallback, string $field, string $categoryName)
    {
        if ($fallback === null) {
            return null;
        }

        if ($field === 'Meta_Title') {
            $text = trim((string)$fallback);
            if ($text === '') {
                return null;
            }
            return $this->applyCategoryPlaceholders($text, $categoryName);
        }

        if ($field === 'Meta_Description') {
            $converted = $this->transformRegistry->apply('rtf_to_html', $fallback);
            if (is_string($converted)) {
                $converted = trim($converted);
                if ($converted === '') {
                    return null;
                }
                return $this->applyCategoryPlaceholders($converted, $categoryName);
            }
            return $converted;
        }

        return $fallback;
    }

    private function applyCategoryPlaceholders(string $text, string $categoryName): string
    {
        if ($categoryName !== '') {
            $replacements = [
                '{{Bezeichnung}}' => $categoryName,
                '{{bezeichnung}}' => $categoryName,
            ];
            $text = strtr($text, $replacements);
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function getCategoryMetaBasePath(): ?string
    {
        if ($this->categoryMetaBasePath !== null) {
            return $this->categoryMetaBasePath;
        }

        $root = dirname(__DIR__, 2);
        $path = $root . DIRECTORY_SEPARATOR . 'srcFiles' . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR . 'Warengruppen';
        if (!is_dir($path)) {
            $this->categoryMetaBasePath = null;
            return null;
        }

        $this->categoryMetaBasePath = $path;
        return $path;
    }

    /**
     * @return array<string,string>
     */
    private function buildCategoryMetaIndex(string $basePath): array
    {
        $index = [];
        $entries = scandir($basePath);
        if ($entries === false) {
            return $index;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $folderPath = $basePath . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($folderPath)) {
                continue;
            }
            $key = $this->normalizeMetaKey($entry);
            if ($key === '') {
                continue;
            }
            $index[$key] = $folderPath;
        }

        return $index;
    }

    /**
     * @param array<string,string> $index
     */
    private function resolveCategoryMetaFolder(string $name, array $index): ?string
    {
        $normalized = $this->normalizeMetaKey($name);
        if ($normalized === '') {
            return null;
        }

        return $index[$normalized] ?? null;
    }

    private function normalizeMetaKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = strtr($value, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue',
            'ß' => 'ss',
        ]);

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * @param mixed $raw
     */
    private function determineMasterFlag($raw): ?int
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string)$raw);
        if ($value === '') {
            return null;
        }
        if (strcasecmp($value, 'master') === 0) {
            return 1;
        }
        return 0;
    }

    /**
     * @param mixed $raw
     * @return string|null
     */
    private function determineMasterNumber($raw): ?string
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string)$raw);
        if ($value === '' || strcasecmp($value, 'master') === 0) {
            return null;
        }
        return $value;
    }
}
