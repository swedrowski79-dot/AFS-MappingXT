<?php

/**
 * HashManager - Generates stable, deterministic SHA-256 hashes from raw field data
 * 
 * This class is responsible for:
 * - Creating consistent hashes from article/entity data
 * - Detecting changes by comparing hashes
 * - Supporting efficient change detection without full field comparison
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
}
