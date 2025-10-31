# Bildnummer Feature Implementation

## Overview
This document describes the implementation of the `Bildnummer` field in the `Artikel_Bilder` table, which stores the image number (1-10) from the AFS database fields (Bild1, Bild2, Bild3, etc.).

## Problem Statement
In the AFS database, articles have image fields named Bild1, Bild2, Bild3, up to Bild10. The requirement was to transfer and store the image number (the digit at the end of the field name) in the Artikel_Bilder relationship table. The Bildnummer field can be NULL.

## Solution

### 1. Database Schema Changes
**File: `scripts/create_evo.sql`**

Added `Bildnummer INTEGER` column to the Artikel_Bilder table:

```sql
CREATE TABLE IF NOT EXISTS Artikel_Bilder (
    ID           INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_ARTIKEL_ID INTEGER,
    XT_Bild_ID    INTEGER,
    Artikel_ID    INTEGER NOT NULL,
    Bild_ID       INTEGER NOT NULL,
    Bildnummer    INTEGER,  -- NEW: stores the image number (1-10)
    "update"      INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    FOREIGN KEY (Artikel_ID) REFERENCES Artikel(ID) ON DELETE CASCADE,
    FOREIGN KEY (Bild_ID)    REFERENCES Bilder(ID)  ON DELETE CASCADE
);
```

### 2. Migration Script
**File: `scripts/migrate_add_bildnummer.php`**

Created a migration script for existing databases that adds the Bildnummer column if it doesn't exist:

```bash
php scripts/migrate_add_bildnummer.php
```

The script:
- Checks if the Bildnummer column already exists
- Adds it if missing
- Provides clear status messages

### 3. Mapping Configuration
**File: `schemas/evo.yml`**

Added Bildnummer to the article_images relationship fields:

```yaml
relationships:
  article_images:
    table: Artikel_Bilder
    fields:
      # ... existing fields ...
      Bildnummer:
        type: integer
        nullable: true
      # ... other fields ...
```

### 4. Code Changes
**File: `classes/evo/EVO_ArticleSync.php`**

#### Changed `collectArticleImages()` method:
- **Before**: Returned array of image names (strings)
- **After**: Returns array of structures with 'name' and 'nummer' keys

```php
// Old:
return array_values($unique); // ['image1.jpg', 'image2.jpg']

// New:
return array_values($unique); // [['name' => 'image1.jpg', 'nummer' => 1], ...]
```

#### Updated `syncArticleImages()` method:
- Extracts both image name and number from the collected images
- Passes Bildnummer parameter to the INSERT statement

```php
foreach ($this->collectArticleImages($row) as $imageInfo) {
    $imageName = $imageInfo['name'];
    $imageNummer = $imageInfo['nummer'];
    $bildId = $this->imageSync->resolveBildId($bildMap, $imageName);
    if ($bildId !== null) {
        $desired[(int)$bildId] = $imageNummer;
    }
}

// When inserting:
$insertImage->execute([
    // ... other parameters ...
    ':bildnummer' => $bildnummer,
    // ...
]);
```

### 5. Automatic SQL Generation
The `AFS_SqlBuilder` class automatically generates the correct SQL statements from the YAML mapping configuration. No changes were needed to the SQL builder itself.

Generated INSERT statement includes Bildnummer:
```sql
INSERT INTO "Artikel_Bilder" (
    "XT_ARTIKEL_ID", "XT_Bild_ID", "Artikel_ID", "Bild_ID", 
    "Bildnummer", "update"
) VALUES (
    :xt_artikel_id, :xt_bild_id, :artikel_id, :bild_id, 
    :bildnummer, :update
)
ON CONFLICT("Artikel_ID", "Bild_ID") DO UPDATE SET 
    "XT_ARTIKEL_ID" = excluded."XT_ARTIKEL_ID",
    "XT_Bild_ID" = excluded."XT_Bild_ID",
    "Bildnummer" = excluded."Bildnummer",
    "update" = excluded."update"
```

## Testing

### Test Script
**File: `scripts/test_bildnummer.php`**

A comprehensive test script verifies:
1. YAML mapping includes Bildnummer field
2. SQL builder generates correct INSERT statements with Bildnummer
3. Database schema includes Bildnummer column
4. Code structure is correct

Run tests:
```bash
php scripts/test_bildnummer.php
```

All tests pass successfully.

## Usage

### For New Installations
The Bildnummer field will be automatically created when running:
```bash
php scripts/setup.php
```

### For Existing Installations
Run the migration script:
```bash
php scripts/migrate_add_bildnummer.php
```

### Data Flow
1. AFS database has Bild1, Bild2, Bild3, ... Bild10 fields for each article
2. `collectArticleImages()` extracts both the image filename and the number
3. `syncArticleImages()` stores the relationship with the Bildnummer
4. Bildnummer is stored as 1, 2, 3, ... 10 (or NULL if not applicable)

## Benefits
- **Preserves image order**: The original image number from AFS is maintained
- **Nullable**: Flexible to handle cases where image number is not relevant
- **Backward compatible**: Existing code continues to work
- **Minimal changes**: Only affected the specific methods that handle image relationships
- **Type-safe**: Integer type ensures data integrity
- **Configurable**: Controlled via YAML mapping configuration

## Security
- No security vulnerabilities introduced
- CodeQL analysis passed with no issues
- All data properly parameterized in SQL statements (no injection risk)
- Migration script safely checks for existing columns before adding

## Compatibility
- Compatible with existing databases (via migration script)
- Compatible with existing code (backward compatible)
- No breaking changes to the API or data structure
