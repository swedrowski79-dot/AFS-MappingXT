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
}
