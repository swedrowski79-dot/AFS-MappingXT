<?php
/**
 * AFS_TargetMappingConfig - YAML Configuration Loader for Target Database Mapping
 * 
 * Loads and parses the target_sqlite.yml configuration file to provide
 * table and field mappings for write operations to the target database.
 */
class AFS_TargetMappingConfig
{
    /** @var array */
    private $config = [];

    /** @var string */
    private $configPath;

    /**
     * @param string $configPath Path to the YAML configuration file
     */
    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->load();
    }

    /**
     * Load and parse the YAML configuration file
     * 
     * Uses AFS_ConfigCache to avoid repeatedly parsing the same configuration file.
     * 
     * @throws AFS_ConfigurationException if file cannot be loaded or parsed
     */
    private function load(): void
    {
        if (!is_file($this->configPath)) {
            throw new AFS_ConfigurationException("Target configuration file not found: {$this->configPath}");
        }

        if (!extension_loaded('yaml')) {
            throw new AFS_ConfigurationException('YAML extension is not loaded. Please install php-yaml.');
        }

        // Try to get from cache first
        $cached = AFS_ConfigCache::get($this->configPath);
        if ($cached !== null) {
            $this->config = $cached;
            return;
        }

        // Cache miss - load and parse the file
        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new AFS_ConfigurationException("Failed to read target configuration file: {$this->configPath}");
        }

        // Replace environment variable placeholders
        $content = preg_replace_callback('/\$\{([A-Z_]+)\}/', function($matches) {
            $envVar = $matches[1];
            $value = getenv($envVar);
            return $value !== false ? $value : $matches[0];
        }, $content);

        $parsed = yaml_parse($content);
        if ($parsed === false) {
            throw new AFS_ConfigurationException("Failed to parse YAML target configuration: {$this->configPath}");
        }

        $this->config = $parsed;

        // Store in cache for future use
        AFS_ConfigCache::set($this->configPath, $parsed);
    }

    /**
     * Get the mapping version
     * 
     * @return string|null Version string or null if not defined
     */
    public function getVersion(): ?string
    {
        return $this->config['version'] ?? null;
    }

    /**
     * Get entity configuration by name
     * 
     * @param string $entityName Name of the entity (e.g., 'articles', 'categories')
     * @return array|null Entity configuration or null if not found
     */
    public function getEntity(string $entityName): ?array
    {
        return $this->config['entities'][$entityName] ?? null;
    }

    /**
     * Get relationship configuration by name
     * 
     * @param string $relationshipName Name of the relationship (e.g., 'article_images')
     * @return array|null Relationship configuration or null if not found
     */
    public function getRelationship(string $relationshipName): ?array
    {
        return $this->config['relationships'][$relationshipName] ?? null;
    }

    /**
     * Get table name for an entity
     * 
     * @param string $entityName Name of the entity
     * @return string|null Table name or null if not found
     */
    public function getTableName(string $entityName): ?string
    {
        $entity = $this->getEntity($entityName);
        return $entity['table'] ?? null;
    }

    /**
     * Get table name for a relationship
     * 
     * @param string $relationshipName Name of the relationship
     * @return string|null Table name or null if not found
     */
    public function getRelationshipTableName(string $relationshipName): ?string
    {
        $relationship = $this->getRelationship($relationshipName);
        return $relationship['table'] ?? null;
    }

    /**
     * Get field definitions for an entity
     * 
     * @param string $entityName Name of the entity
     * @return array Field definitions (field_name => field_config)
     */
    public function getFields(string $entityName): array
    {
        $entity = $this->getEntity($entityName);
        return $entity['fields'] ?? [];
    }

    /**
     * Get field definitions for a relationship
     * 
     * @param string $relationshipName Name of the relationship
     * @return array Field definitions (field_name => field_config)
     */
    public function getRelationshipFields(string $relationshipName): array
    {
        $relationship = $this->getRelationship($relationshipName);
        return $relationship['fields'] ?? [];
    }

    /**
     * Get unique key column name for an entity
     * 
     * @param string $entityName Name of the entity
     * @return string|null Unique key column name or null if not defined
     */
    public function getUniqueKey(string $entityName): ?string
    {
        $entity = $this->getEntity($entityName);
        return $entity['unique_key'] ?? null;
    }

    /**
     * Build an INSERT ... ON CONFLICT UPDATE statement for an entity
     * 
     * @param string $entityName Name of the entity
     * @param array $excludeFields Fields to exclude from the statement
     * @return array Array with keys 'insert_sql', 'insert_fields', 'update_fields'
     * @throws RuntimeException if entity not found
     */
    public function buildUpsertStatement(string $entityName, array $excludeFields = []): array
    {
        $entity = $this->getEntity($entityName);
        if ($entity === null) {
            throw new AFS_ConfigurationException("Entity not found: {$entityName}");
        }

        $table = $entity['table'] ?? null;
        $uniqueKey = $entity['unique_key'] ?? null;
        $fields = $entity['fields'] ?? [];

        if (empty($table) || empty($fields)) {
            throw new AFS_ConfigurationException("Invalid entity configuration for: {$entityName}");
        }

        $insertFields = [];
        $updateFields = [];

        foreach ($fields as $fieldName => $fieldConfig) {
            // Skip auto-increment fields and excluded fields
            if (isset($fieldConfig['auto_increment']) && $fieldConfig['auto_increment']) {
                continue;
            }
            if (in_array($fieldName, $excludeFields, true)) {
                continue;
            }

            $insertFields[] = $fieldName;
            
            // Don't update the unique key in the UPDATE clause
            if ($fieldName !== $uniqueKey) {
                $updateFields[] = $fieldName;
            }
        }

        return [
            'table' => $table,
            'unique_key' => $uniqueKey,
            'insert_fields' => $insertFields,
            'update_fields' => $updateFields,
        ];
    }

    /**
     * Build an INSERT ... ON CONFLICT UPDATE statement for a relationship
     * 
     * @param string $relationshipName Name of the relationship
     * @param array $excludeFields Fields to exclude from the statement
     * @return array Array with keys 'insert_sql', 'insert_fields', 'update_fields'
     * @throws AFS_ConfigurationException if relationship not found
     */
    public function buildRelationshipUpsertStatement(string $relationshipName, array $excludeFields = []): array
    {
        $relationship = $this->getRelationship($relationshipName);
        if ($relationship === null) {
            throw new AFS_ConfigurationException("Relationship not found: {$relationshipName}");
        }

        $table = $relationship['table'] ?? null;
        $fields = $relationship['fields'] ?? [];
        $uniqueConstraint = $relationship['unique_constraint'] ?? [];

        if (empty($table) || empty($fields)) {
            throw new AFS_ConfigurationException("Invalid relationship configuration for: {$relationshipName}");
        }

        $insertFields = [];
        $updateFields = [];

        foreach ($fields as $fieldName => $fieldConfig) {
            // Skip auto-increment fields and excluded fields
            if (isset($fieldConfig['auto_increment']) && $fieldConfig['auto_increment']) {
                continue;
            }
            if (in_array($fieldName, $excludeFields, true)) {
                continue;
            }

            $insertFields[] = $fieldName;
            
            // Don't update fields that are part of the unique constraint
            if (!in_array($fieldName, $uniqueConstraint, true)) {
                $updateFields[] = $fieldName;
            }
        }

        return [
            'table' => $table,
            'unique_constraint' => $uniqueConstraint,
            'insert_fields' => $insertFields,
            'update_fields' => $updateFields,
        ];
    }

    /**
     * Get target configuration
     * 
     * @return array Target configuration
     */
    public function getTarget(): array
    {
        return $this->config['target'] ?? [];
    }

    /**
     * Get connection configuration
     * 
     * @return array Connection configuration
     */
    public function getConnection(): array
    {
        return $this->config['connection'] ?? [];
    }
}
