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

    public function __construct(?TransformRegistry $registry = null)
    {
        $this->transformRegistry = $registry ?? new TransformRegistry();
    }

    /**
     * @param array<string,mixed> $context
     */
    public function evaluate(string $expression, array $context)
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        $segments = array_map('trim', explode('|', $expression));
        if ($segments === []) {
            return null;
        }

        $first = array_shift($segments);
        $value = $this->resolveReference($first ?? '', $context);

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $value = $this->applyTransformation($segment, $value);
        }

        return $value;
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

    private function applyTransformation(string $segment, $value)
    {
        $segment = trim($segment);
        if ($segment === '') {
            return $value;
        }

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\((.*)\)$/', $segment, $matches)) {
            $name = $matches[1];
            $args = trim($matches[2]);
            return $this->applyFunction($name, $value, $args);
        }

        return $this->transformRegistry->apply($segment, $value);
    }

    private function applyFunction(string $name, $value, string $args)
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
}
