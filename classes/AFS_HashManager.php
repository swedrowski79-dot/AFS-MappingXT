<?php

/**
 * HashManager - Generates stable, deterministic SHA-256 hashes from raw field data
 * 
 * This class is responsible for:
 * - Creating consistent hashes from article/entity data
 * - Detecting changes by comparing hashes
 * - Supporting efficient change detection without full field comparison
 * - Supporting partial hash scopes (price, media, content) for selective updates
 */
class AFS_HashManager
{
    /**
     * Generate a SHA-256 hash from raw field data
     * 
     * The hash is computed from a normalized, deterministic representation
     * of the input data to ensure stability across multiple runs.
     * 
     * @param array<string,mixed> $fields Array of field name => value pairs
     * @return string SHA-256 hash (64 character hex string)
     */
    public function generateHash(array $fields): string
    {
        // Sort fields by key to ensure deterministic ordering
        ksort($fields);
        
        // Normalize and serialize the data
        $normalized = $this->normalizeFields($fields);
        
        // Create deterministic string representation
        $data = json_encode($normalized, JSON_THROW_ON_ERROR);
        
        // Generate SHA-256 hash
        return hash('sha256', $data);
    }
    
    /**
     * Check if a hash has changed
     * 
     * @param string|null $oldHash Previous hash (null if first import)
     * @param string $newHash Current hash
     * @return bool True if hash has changed or is new
     */
    public function hasChanged(?string $oldHash, string $newHash): bool
    {
        // New record if no old hash exists
        if ($oldHash === null || $oldHash === '') {
            return true;
        }
        
        // Compare hashes
        return $oldHash !== $newHash;
    }
    
    /**
     * Normalize field values for consistent hash generation
     * 
     * Handles different data types and null values consistently.
     * 
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];
        
        foreach ($fields as $key => $value) {
            // Convert null to empty string for consistency
            if ($value === null) {
                $normalized[$key] = '';
                continue;
            }
            
            // Handle different types
            if (is_string($value)) {
                // Trim whitespace for strings
                $normalized[$key] = trim($value);
            } elseif (is_numeric($value)) {
                // Normalize numeric values
                if (is_float($value)) {
                    // Round floats to avoid floating-point precision issues
                    $normalized[$key] = round($value, 2);
                } else {
                    $normalized[$key] = (int)$value;
                }
            } elseif (is_bool($value)) {
                // Convert boolean to int (0 or 1)
                $normalized[$key] = $value ? 1 : 0;
            } elseif (is_array($value)) {
                // Recursively normalize arrays
                $normalized[$key] = $this->normalizeFields($value);
            } else {
                // For other types, convert to string
                $normalized[$key] = (string)$value;
            }
        }
        
        return $normalized;
    }
    
    /**
     * Extract hashable fields from article payload
     * 
     * Excludes fields that shouldn't trigger updates (like IDs, timestamps, update flags)
     * 
     * @param array<string,mixed> $payload Article data payload
     * @return array<string,mixed> Fields to include in hash
     */
    public function extractHashableFields(array $payload): array
    {
        // Fields to exclude from hash calculation
        $excludeFields = [
            'ID',
            'id',
            'xt_id',
            'afs_id',
            'XT_ID',
            'AFS_ID',
            'update',
            'last_update',
            'last_update_ts',
            'last_imported_hash',
            'last_seen_hash',
            'price_hash',
            'media_hash',
            'content_hash',
            // XT-specific IDs
            'XT_Category_ID',
            'xt_category_id',
            'XT_ARTIKEL_ID',
            'XT_Bild_ID',
            'XT_Attrib_ID',
        ];
        
        $hashableFields = [];
        foreach ($payload as $key => $value) {
            if (!in_array($key, $excludeFields, true)) {
                $hashableFields[$key] = $value;
            }
        }
        
        return $hashableFields;
    }
    
    /**
     * Generate partial hashes for different scopes (price, media, content)
     * 
     * This enables selective table updates - only update related tables
     * when their specific scope has changed.
     * 
     * @param array<string,mixed> $payload Complete article data payload
     * @param array<string,array<string>> $scopeDefinitions Field groupings by scope
     * @return array<string,string> Map of scope name => hash
     */
    public function generatePartialHashes(array $payload, array $scopeDefinitions): array
    {
        $hashes = [];
        
        foreach ($scopeDefinitions as $scopeName => $fieldList) {
            // Extract only the fields that belong to this scope
            $scopeData = [];
            foreach ($fieldList as $fieldName) {
                // Convert to lowercase to match payload keys
                $fieldKey = strtolower($fieldName);
                if (array_key_exists($fieldKey, $payload)) {
                    $scopeData[$fieldKey] = $payload[$fieldKey];
                }
            }
            
            // Generate hash for this scope's data
            if (!empty($scopeData)) {
                $hashes[$scopeName] = $this->generateHash($scopeData);
            } else {
                // Empty scope gets a null hash
                $hashes[$scopeName] = null;
            }
        }
        
        return $hashes;
    }
    
    /**
     * Determine which scopes have changed by comparing old and new partial hashes
     * 
     * @param array<string,string|null> $oldHashes Previous scope hashes
     * @param array<string,string|null> $newHashes Current scope hashes
     * @return array<string,bool> Map of scope name => has_changed
     */
    public function detectScopeChanges(array $oldHashes, array $newHashes): array
    {
        $changes = [];
        
        foreach ($newHashes as $scope => $newHash) {
            $oldHash = $oldHashes[$scope] ?? null;
            $changes[$scope] = $this->hasChanged($oldHash, $newHash);
        }
        
        return $changes;
    }
}
