<?php
/**
 * AFS_SqlBuilder - Dynamic SQL Statement Builder using Target Mapping
 * 
 * Generates SQL statements dynamically from target mapping configuration
 * to ensure write operations are controlled via YAML configuration.
 */
class AFS_SqlBuilder
{
    private AFS_TargetMappingConfig $mapping;

    public function __construct(AFS_TargetMappingConfig $mapping)
    {
        $this->mapping = $mapping;
    }

    /**
     * Build an INSERT ... ON CONFLICT UPDATE statement for an entity
     * 
     * @param string $entityName Name of the entity (e.g., 'articles')
     * @return string SQL statement
     */
    public function buildEntityUpsert(string $entityName): string
    {
        $info = $this->mapping->buildUpsertStatement($entityName);
        $table = $this->quote($info['table']);
        $uniqueKey = $info['unique_key'];
        $insertFields = $info['insert_fields'];
        $updateFields = $info['update_fields'];

        // Build INSERT clause
        $quotedFields = array_map([$this, 'quote'], $insertFields);
        $placeholders = array_map(function($field) { return ':' . strtolower($field); }, $insertFields);
        
        $insertClause = sprintf(
            "INSERT INTO %s (\n    %s\n) VALUES (\n    %s\n)",
            $table,
            implode(", ", $quotedFields),
            implode(", ", $placeholders)
        );

        // Build UPDATE clause
        $updatePairs = array_map(function($field) {
            return sprintf("%s = excluded.%s", $this->quote($field), $this->quote($field));
        }, $updateFields);

        $updateClause = sprintf(
            "ON CONFLICT(%s) DO UPDATE SET\n    %s",
            $this->quote($uniqueKey),
            implode(",\n    ", $updatePairs)
        );

        return $insertClause . "\n" . $updateClause;
    }

    /**
     * Build an INSERT ... ON CONFLICT UPDATE statement for a relationship
     * 
     * @param string $relationshipName Name of the relationship (e.g., 'article_images')
     * @return string SQL statement
     */
    public function buildRelationshipUpsert(string $relationshipName): string
    {
        $info = $this->mapping->buildRelationshipUpsertStatement($relationshipName);
        $table = $this->quote($info['table']);
        $uniqueConstraint = $info['unique_constraint'];
        $insertFields = $info['insert_fields'];
        $updateFields = $info['update_fields'];

        // Build INSERT clause
        $quotedFields = array_map([$this, 'quote'], $insertFields);
        $placeholders = array_map(function($field) { return ':' . strtolower($field); }, $insertFields);
        
        $insertClause = sprintf(
            "INSERT INTO %s (%s)\n VALUES (%s)",
            $table,
            implode(", ", $quotedFields),
            implode(", ", $placeholders)
        );

        // Build UPDATE clause
        if (!empty($updateFields)) {
            $updatePairs = array_map(function($field) {
                return sprintf("%s = excluded.%s", $this->quote($field), $this->quote($field));
            }, $updateFields);

            $constraintFields = array_map([$this, 'quote'], $uniqueConstraint);
            $updateClause = sprintf(
                "ON CONFLICT(%s) DO UPDATE SET %s",
                implode(", ", $constraintFields),
                implode(", ", $updatePairs)
            );

            return $insertClause . "\n " . $updateClause;
        }

        // No update fields, just ignore conflicts
        $constraintFields = array_map([$this, 'quote'], $uniqueConstraint);
        return $insertClause . sprintf("\n ON CONFLICT(%s) DO NOTHING", implode(", ", $constraintFields));
    }

    /**
     * Build a DELETE statement for a relationship
     * 
     * @param string $relationshipName Name of the relationship
     * @param array $whereFields Fields to use in WHERE clause
     * @return string SQL statement
     */
    public function buildRelationshipDelete(string $relationshipName, array $whereFields): string
    {
        $tableName = $this->mapping->getRelationshipTableName($relationshipName);
        if ($tableName === null) {
            throw new AFS_ConfigurationException("Relationship not found: {$relationshipName}");
        }

        $table = $this->quote($tableName);
        $whereParts = array_map(function($field) {
            return sprintf("%s = :%s", $this->quote($field), strtolower($field));
        }, $whereFields);

        return sprintf(
            "DELETE FROM %s WHERE %s",
            $table,
            implode(" AND ", $whereParts)
        );
    }

    /**
     * Get field names for parameter binding (lowercase for consistency)
     * 
     * @param string $entityName Name of the entity
     * @return array Array mapping actual field names to parameter names
     */
    public function getParameterMapping(string $entityName): array
    {
        $info = $this->mapping->buildUpsertStatement($entityName);
        $mapping = [];
        foreach ($info['insert_fields'] as $field) {
            $mapping[$field] = strtolower($field);
        }
        return $mapping;
    }

    /**
     * Quote identifier (table or column name)
     * 
     * @param string $identifier Identifier to quote
     * @return string Quoted identifier
     */
    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
