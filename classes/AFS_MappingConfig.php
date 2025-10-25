<?php
/**
 * AFS_MappingConfig - YAML Configuration Loader for AFS Data Mapping
 * 
 * Loads and parses the source_afs.yml configuration file to provide
 * field mappings and transformation rules for AFS_Get_Data.
 */
class AFS_MappingConfig
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
     * Uses native PHP YAML parser (AFS_YamlParser) to eliminate external dependencies.
     * 
     * @throws AFS_ConfigurationException if file cannot be loaded or parsed
     */
    private function load(): void
    {
        if (!is_file($this->configPath)) {
            throw new AFS_ConfigurationException("Configuration file not found: {$this->configPath}");
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
            throw new AFS_ConfigurationException("Failed to read configuration file: {$this->configPath}");
        }

        // Replace environment variable placeholders
        $content = preg_replace_callback('/\$\{([A-Z_]+)\}/', function($matches) {
            $envVar = $matches[1];
            $value = getenv($envVar);
            return $value !== false ? $value : $matches[0];
        }, $content);

        // Parse using native YAML parser
        $parsed = AFS_YamlParser::parse($content);

        $this->config = $parsed;

        // Store in cache for future use
        AFS_ConfigCache::set($this->configPath, $parsed);
    }

    /**
     * Get entity configuration by name
     * 
     * @param string $entityName Name of the entity (e.g., 'Artikel', 'Warengruppe')
     * @return array|null Entity configuration or null if not found
     */
    public function getEntity(string $entityName): ?array
    {
        return $this->config['entities'][$entityName] ?? null;
    }

    /**
     * Get all entities
     * 
     * @return array All entity configurations
     */
    public function getEntities(): array
    {
        return $this->config['entities'] ?? [];
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

    /**
     * Get source configuration
     * 
     * @return array Source configuration
     */
    public function getSource(): array
    {
        return $this->config['source'] ?? [];
    }

    /**
     * Build SQL SELECT query from entity configuration
     * 
     * @param string $entityName Name of the entity
     * @return string SQL SELECT query
     * @throws AFS_ConfigurationException if entity not found
     */
    public function buildSelectQuery(string $entityName): string
    {
        $entity = $this->getEntity($entityName);
        if ($entity === null) {
            throw new AFS_ConfigurationException("Entity not found: {$entityName}");
        }

        $table = $entity['table'] ?? '';
        $fields = $entity['fields'] ?? [];
        $where = $entity['where'] ?? '';

        if (empty($table) || empty($fields)) {
            throw new AFS_ConfigurationException("Invalid entity configuration for: {$entityName}");
        }

        $selectParts = [];
        foreach ($fields as $targetName => $fieldConfig) {
            $sourceName = $fieldConfig['source'] ?? $targetName;
            
            if ($sourceName === $targetName) {
                $selectParts[] = "[{$sourceName}]";
            } else {
                $selectParts[] = "[{$sourceName}] AS [{$targetName}]";
            }
        }

        $sql = "SELECT\n    " . implode(",\n    ", $selectParts) . "\nFROM [{$table}]";
        
        if (!empty($where)) {
            $sql .= "\nWHERE {$where}";
        }

        return $sql;
    }

    /**
     * Get field configuration for an entity
     * 
     * @param string $entityName Name of the entity
     * @return array Field configurations
     */
    public function getFields(string $entityName): array
    {
        $entity = $this->getEntity($entityName);
        return $entity['fields'] ?? [];
    }
}
