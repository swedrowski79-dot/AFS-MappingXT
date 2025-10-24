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
     * @throws RuntimeException if file cannot be loaded or parsed
     */
    private function load(): void
    {
        if (!is_file($this->configPath)) {
            throw new RuntimeException("Configuration file not found: {$this->configPath}");
        }

        if (!extension_loaded('yaml')) {
            throw new RuntimeException('YAML extension is not loaded. Please install php-yaml.');
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read configuration file: {$this->configPath}");
        }

        // Replace environment variable placeholders
        $content = preg_replace_callback('/\$\{([A-Z_]+)\}/', function($matches) {
            $envVar = $matches[1];
            $value = getenv($envVar);
            return $value !== false ? $value : $matches[0];
        }, $content);

        $parsed = yaml_parse($content);
        if ($parsed === false) {
            throw new RuntimeException("Failed to parse YAML configuration: {$this->configPath}");
        }

        $this->config = $parsed;
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
     * @throws RuntimeException if entity not found
     */
    public function buildSelectQuery(string $entityName): string
    {
        $entity = $this->getEntity($entityName);
        if ($entity === null) {
            throw new RuntimeException("Entity not found: {$entityName}");
        }

        $table = $entity['table'] ?? '';
        $fields = $entity['fields'] ?? [];
        $where = $entity['where'] ?? '';

        if (empty($table) || empty($fields)) {
            throw new RuntimeException("Invalid entity configuration for: {$entityName}");
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
