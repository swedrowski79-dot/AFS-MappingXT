<?php
declare(strict_types=1);

namespace Mapping;

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

        // Convert RTF to HTML (simplified)
        $this->register('rtf_to_html', function($value) {
            if ($value === null || $value === '') {
                return $value;
            }
            $val = (string)$value;
            
            if (strpos($val, '{\\rtf') !== false) {
                // Remove RTF control words
                $val = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $val);
                // Remove braces and backslashes
                $val = str_replace(['{', '}', '\\'], '', $val);
                // Reduce multiple spaces
                $val = trim(preg_replace('/\s+/', ' ', $val));
            }
            
            return $val;
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
}
