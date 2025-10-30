# YAML-Based Mapping Configuration Guide

## Overview

The AFS_Get_Data class has been refactored to read field mappings from `afs.yml` instead of using hardcoded SQL queries. This makes the system more maintainable and flexible.

## File Location

The configuration file is located at:
```
/mappings/afs.yml
```

## Configuration Structure

### Entity Definition

Each entity (Artikel, Warengruppe, Dokumente) has the following structure:

```yaml
entities:
  EntityName:
    table: TableName          # Database table name
    where: "SQL WHERE clause" # Optional WHERE condition
    fields:
      TargetFieldName:
        source: SourceColumnName  # Database column name
        type: DataType            # Data type (integer, float, string, boolean, datetime)
        transform: TransformName  # Optional transformation to apply
```

### Supported Data Types

- `integer` - Converts to PHP integer
- `float` / `decimal` - Converts to PHP float (handles comma as decimal separator)
- `string` - No conversion (default)
- `boolean` - Converts to PHP boolean (handles various representations: 1/0, true/false, ja/nein, etc.)
- `datetime` - Date/time fields (automatically formatted to ISO 8601)

### Available Transformations

The following transformations are registered in the TransformRegistry:

- **`trim`** - Removes leading and trailing whitespace
- **`basename`** - Extracts filename from full path (e.g., `C:\Images\file.jpg` → `file.jpg`)
- **`rtf_to_html`** - Converts RTF formatted text to HTML/plain text
- **`remove_html`** - Strips HTML tags from text
- **`normalize_title`** - Normalizes document titles (trims and extracts basename)

### Example: Artikel Entity

```yaml
Artikel:
  table: Artikel
  where: "[Mandant] = 1 AND [Art] < 255 AND [Artikelnummer] IS NOT NULL AND [Internet] = 1"
  fields:
    Artikel:
      source: Artikel
      type: integer
    Preis:
      source: VK3         # Maps VK3 column to Preis field
      type: float
    Online:
      source: Internet     # Maps Internet column to Online field
      type: boolean
    Bild1:
      source: Bild1
      type: string
      transform: basename  # Applies basename transformation
```

## Adding New Fields

To add a new field to an entity:

1. Open `/mappings/afs.yml`
2. Find the entity you want to modify
3. Add a new field entry under `fields:`

Example - adding a new field "Gewicht" mapped from "Weight":

```yaml
Artikel:
  fields:
    # ... existing fields ...
    Gewicht:
      source: Weight
      type: float
```

## Modifying Field Mappings

To change how a field is mapped:

1. Find the field in the YAML file
2. Modify the `source`, `type`, or `transform` properties
3. No code changes are required!

Example - change the Preis source column:

```yaml
Preis:
  source: VK1  # Changed from VK3 to VK1
  type: float
```

## Creating Custom Transformations

To add a custom transformation:

1. Open `src/Mapping/TransformRegistry.php`
2. Add a new transformation in the `registerDefaultTransformations()` method:

```php
$this->register('my_transform', function($value) {
    // Your transformation logic here
    return $transformedValue;
});
```

3. Use the transformation in your YAML config:

```yaml
MyField:
  source: SourceColumn
  type: string
  transform: my_transform
```

## Testing Changes

After modifying the YAML configuration, run the test scripts to verify:

```bash
# Test YAML loading and SQL generation
php scripts/test_yaml_mapping.php

# Test with mock database
php scripts/test_integration.php
```

## Backward Compatibility

The refactored implementation maintains full backward compatibility:

- Method signatures unchanged: `getArtikel()`, `getWarengruppen()`, `getDokumente()`
- Return format unchanged: same array structure with same field names
- Dependent classes (AFS.php, indexcli.php) require no modifications

## Benefits

1. **No Code Changes**: Modify field mappings without touching PHP code
2. **Self-Documenting**: YAML configuration serves as schema documentation
3. **Flexible**: Easy to add/remove fields or change transformations
4. **Maintainable**: Configuration is separated from business logic
5. **Testable**: Easy to test with mock data

## Migration Notes

The original hardcoded SQL queries have been replaced with YAML-based configuration:

### Before (Hardcoded):
```php
$sql = "SELECT [VK3] AS [Preis] FROM [Artikel] WHERE ...";
```

### After (YAML-based):
```yaml
Preis:
  source: VK3
  type: float
```

The SQL is now generated automatically from the YAML configuration.

## Troubleshooting

### Error: "Configuration file not found"
- Check that `/mappings/afs.yml` exists
- Verify file permissions

### Error: "Failed to parse YAML"
- Check YAML syntax (indentation must be consistent)
- Use a YAML validator online

### Error: "Entity not found"
- Verify entity name matches exactly (case-sensitive)
- Check that entity is defined under `entities:` in YAML

### Field not appearing in results
- Check that field is defined in YAML
- Verify source column exists in database
- Check WHERE clause doesn't filter out the data

### Transformation not working
- Verify transformation name is correct
- Check that TransformRegistry has the transformation registered
- Test transformation independently

## Environment Variables

Connection settings support environment variable substitution:

```yaml
connection:
  host: ${AFS_MSSQL_HOST}
  port: ${AFS_MSSQL_PORT}
  database: ${AFS_MSSQL_DB}
```

Set these in your environment or `config.php`.
