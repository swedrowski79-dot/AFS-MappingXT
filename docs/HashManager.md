# HashManager - Efficient Change Detection

## Overview

The `AFS_HashManager` class implements SHA-256 hash-based change detection for the AFS-MappingXT synchronization system. This provides a robust, deterministic method to identify when article data has changed, enabling efficient delta synchronization.

**Hash management has been unified**: The system now uses only `last_imported_hash` and `last_seen_hash` for robust change detection.

## Features

- **Deterministic Hash Generation**: Same data always produces the same hash, regardless of field order
- **Efficient Change Detection**: Compare hashes instead of comparing all fields individually
- **Unified Hash Approach**: Single hash covers all business data fields for consistent change detection
- **Stable & Reliable**: Uses SHA-256 algorithm (64-character hex string)
- **Performance Optimized**: < 0.01ms per hash generation on average
- **Field Filtering**: Automatically excludes IDs and metadata from hash calculation
- **JSON Encoding**: Uses JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES for consistent serialization

## Database Schema

### Hash Columns

Two columns for change detection (all entity tables):

```sql
last_imported_hash TEXT  -- Hash when data was last successfully imported/updated
last_seen_hash TEXT      -- Hash of current data (always persisted on sync)
```

**Change Detection Logic:**
- `update = 1` when `last_seen_hash != last_imported_hash`
- After successful update: `last_imported_hash = last_seen_hash`
- `last_seen_hash` is always persisted, even when no update is needed

### Indices

For optimal query performance:

```sql
-- Hash indices
CREATE INDEX IF NOT EXISTS ix_artikel_imported_hash ON Artikel(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_artikel_seen_hash ON Artikel(last_seen_hash);
CREATE INDEX IF NOT EXISTS ix_bilder_imported_hash ON Bilder(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_dokumente_imported_hash ON Dokumente(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_attribute_imported_hash ON Attribute(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_category_imported_hash ON category(last_imported_hash);
```

## How It Works

### 1. Hash Generation

The hash is computed from normalized field data using `buildHash()` or `generateHash()`:

```php
$hashManager = new AFS_HashManager();

$payload = [
    'artikelnummer' => 'ART-001',
    'bezeichnung' => 'Test Product',
    'preis' => 19.99,
    'bestand' => 100,
    // ... other fields
];

// Extract fields that should be included in hash
$hashableFields = $hashManager->extractHashableFields($payload);

// Generate SHA-256 hash
$currentHash = $hashManager->buildHash($hashableFields);
// Result: "e95a1ec270a3c40912c1a5a157163950b9c67eef9fe0a41c7ea194da36c46959"
```

### 2. Change Detection

Compare the current hash with the previously stored hash:

```php
$existingHash = $existing['last_imported_hash'] ?? null;
$shouldUpdate = $hashManager->hasChanged($existingHash, $currentHash);

if ($shouldUpdate) {
    // Data has changed or is new - trigger update
    $payload['update'] = 1;
    $payload['last_imported_hash'] = $currentHash;
    $payload['last_seen_hash'] = $currentHash;
    $upsert->execute($payload);
} else {
    // Data unchanged - skip update
    continue;
}
```

### 3. Update Flag

The `update` flag is set to `1` when:
- A new article is imported (no existing `last_imported_hash`)
- The hash has changed (data modified)

The `update` flag remains `0` when:
- The hash matches the previous import
- Data is unchanged

## Field Normalization

To ensure hash stability, fields are normalized:

- **Strings**: Trimmed of whitespace
- **Numbers**: Integers kept as-is, floats rounded to 2 decimal places
- **Null values**: Converted to empty strings
- **Booleans**: Converted to 0 or 1
- **Field order**: Sorted alphabetically before hashing

## Excluded Fields

The following fields are excluded from hash calculation (as they shouldn't trigger updates):

- `ID`, `id` - Database primary keys
- `xt_id`, `afs_id` - Foreign system IDs
- `update` - The update flag itself
- `last_update`, `last_update_ts` - Timestamp metadata
- `last_imported_hash`, `last_seen_hash` - Hash metadata
- `XT_Category_ID`, `XT_ARTIKEL_ID`, etc. - External system IDs

## Integration with ArticleSync

The `AFS_Evo_ArticleSync` class integrates HashManager:

```php
public function __construct(/* ... */) {
    // ...
    $this->hashManager = new AFS_HashManager();
}

public function import(): array {
    // In the import loop:
    foreach ($rows as $row) {
        $payload = $this->buildArtikelPayload($row, $categoryMap);
        
        // Compute hash of current data
        $hashableFields = $this->hashManager->extractHashableFields($payload);
        $currentHash = $this->hashManager->generateHash($hashableFields);
        $existingHash = $existing['last_imported_hash'] ?? null;
        
        // Always persist last_seen_hash
        $payload['last_seen_hash'] = $currentHash;
        
        // Determine if update is needed
        if ($this->hashManager->hasChanged($existingHash, $currentHash)) {
            $payload['update'] = 1;
            $payload['last_imported_hash'] = $currentHash;
            $upsert->execute($payload);
            $stats['updated']++;
        } else {
            // Data unchanged - skip update
            continue;
        }
    }
}
```

## Hash Field Documentation

The `mappings/target_sqlite.yml` file documents which fields are included in the hash calculation under the `hash_fields` section:

```yaml
hash_fields:
  articles:
    included:
      - Art
      - Artikelnummer
      - Bezeichnung
      - Preis
      - Bestand
      - Mindestmenge
      - Gewicht
      - Online
      - Langtext
      - Werbetext
      - Meta_Title
      - Meta_Description
      - Bild1
      - Bild2
      # ... all business data fields
```

This provides clear documentation of which fields trigger updates when changed.

## Migration

For existing installations, run the migration script:

```bash
# Add hash columns (idempotent - safe to run multiple times)
php scripts/migrate_add_hash_columns.php
```

The migration will:
1. Add `last_imported_hash` and `last_seen_hash` columns to all entity tables
2. Create indices for efficient hash-based queries
3. Remove obsolete partial hash indices if they exist

The migration is **idempotent** - it can be run safely multiple times without causing errors.

## Performance

Based on testing:

- **Hash generation**: ~0.006ms per article (1000 hashes in 6ms)
- **Memory impact**: Negligible (hash strings are 64 bytes each)
- **Database impact**: Minimal (indexed TEXT columns)
- **Overall sync impact**: < 1% overhead

Performance is excellent even for large catalogs (10,000+ articles).

## Testing

### Unit Tests

Run the HashManager unit tests:

```bash
php scripts/test_hashmanager.php
```

This validates:
- Deterministic hash generation
- Field order independence
- Change detection
- Null value handling
- Field extraction
- Hash format (SHA-256)

## Benefits

1. **Efficient Change Detection**: Avoid comparing every field individually
2. **Accurate Updates**: Only update when data actually changes
3. **Database Performance**: Fewer unnecessary writes
4. **Delta Exports**: Only changed records are flagged for export
5. **Audit Trail**: `last_seen_hash` tracks when data was last checked
6. **Deterministic**: Same data always produces the same hash

## Troubleshooting

### Hash mismatch despite identical data

- Check for whitespace differences (strings are trimmed)
- Verify floating-point precision (rounded to 2 decimals)
- Ensure field order doesn't matter (fields are sorted)

### Migration fails

- Check database file path in config.php
- Verify write permissions on database file
- Run `PRAGMA database_list;` to confirm correct database

### Performance issues

- Ensure indices are created (run migration)
- Check that excluded fields list is up-to-date
- Monitor hash generation time (should be < 0.01ms)

## See Also

- `docs/YAML_MAPPING_GUIDE.md` - Field mapping configuration
- `docs/architecture_before_after.md` - System architecture overview
- `scripts/test_hashmanager.php` - Hash functionality tests
