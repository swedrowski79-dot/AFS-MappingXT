# Class Structure Documentation

## Overview
The classes are organized by database type and functionality to enable flexible, modular data synchronization between different systems.

## Directory Structure

```
classes/
├── afs/           # AFS-specific classes for reading/writing mapping data
├── mapping/       # Generic mapping and transformation classes
├── mssql/         # MSSQL database operations
├── evo/           # EVO intermediate database (SQLite) classes
├── sqlite/        # SQLite-specific database operations
├── mysql/         # MySQL database operations (future)
└── file/          # File-based data structures and metadata
```

## Purpose of Each Directory

### `afs/` - AFS System Classes
**Purpose:** Classes for interacting with the AFS-ERP system and managing mapping data.

**Key Classes:**
- `AFS`: Aggregates data from AFS sources (articles, categories, documents, images, attributes)
- `AFS_Get_Data`: Reads and normalizes data from MSSQL AFS tables
- `AFS_MappingConfig`: Loads and manages source mapping configuration from YAML
- `AFS_TargetMappingConfig`: Loads and manages target mapping configuration from YAML
- `AFS_HashManager`: Efficient change detection using SHA-256 hashes
- `AFS_ConfigCache`: In-memory cache for YAML configuration files
- `AFS_YamlParser`: Native PHP YAML parser
- `AFS_SqlBuilder`: SQL query builder for AFS operations
- `AFS_MappingLogger`: Structured JSON logging for mapping operations
- `AFS_MetadataLoader`: Loads metadata from filesystem structures

**Exception Classes:**
- `AFS_DatabaseException`: Database-related errors
- `AFS_ValidationException`: Validation errors
- `AFS_ConfigurationException`: Configuration errors
- `AFS_SyncBusyException`: Concurrent sync attempt errors

### `mapping/` - Generic Mapping Classes
**Purpose:** Generic classes for data mapping and transformation between systems.

**Key Classes:**
- `SourceMapper`: Maps source data to normalized format
- `TargetMapper`: Maps normalized data to target format
- `TransformRegistry`: Registry for data transformation functions

### `mssql/` - Microsoft SQL Server Classes
**Purpose:** Classes for connecting to and querying Microsoft SQL Server databases.

**Key Classes:**
- `MSSQL`: SQLSRV wrapper with connection management, query execution, and parameter binding

**Use Cases:**
- Source database for AFS-ERP data
- Any MSSQL database as source or target

### `evo/` - EVO Intermediate Database Classes
**Purpose:** Classes for managing the EVO intermediate database (SQLite), which serves as a staging area between source and target systems.

**Key Classes:**
- `AFS_Evo`: Main orchestration class for EVO synchronization
- `AFS_Evo_ArticleSync`: Article/product synchronization
- `AFS_Evo_CategorySync`: Category/Warengruppen synchronization
- `AFS_Evo_ImageSync`: Image management and file copying
- `AFS_Evo_DocumentSync`: Document management and file copying
- `AFS_Evo_AttributeSync`: Product attribute synchronization
- `AFS_Evo_StatusTracker`: Synchronization progress and status tracking
- `AFS_Evo_DeltaExporter`: Exports changed records to delta database
- `AFS_Evo_Reset`: Utility to clear EVO database tables
- `AFS_Evo_Base`: Base class with common utilities

**Database:** `db/evo.db` (SQLite)
**Delta Database:** `db/evo_delta.db` (SQLite, contains only changed records)

### `sqlite/` - SQLite Database Classes
**Purpose:** Generic SQLite database operations, not specific to EVO.

**Status:** Placeholder for future development
**Planned Use Cases:**
- Generic SQLite database connections
- SQLite-specific query builders
- Reusable SQLite components

### `mysql/` - MySQL Database Classes
**Purpose:** MySQL database operations for source or target systems.

**Status:** Placeholder for future development
**Planned Use Cases:**
- MySQL as source database
- MySQL as target database
- MySQL for intermediate data storage

### `file/` - File-Based Data Structures
**Purpose:** Classes for working with file-based data sources.

**Status:** Placeholder (metadata loading currently in AFS_MetadataLoader)
**Current Use Cases:**
- Article metadata from filesystem (`/srcFiles/Data/Artikel/{ArticleNumber}/`)
- Category metadata from filesystem (`/srcFiles/Data/Warengruppen/{CategoryName}/`)

**Planned Use Cases:**
- CSV file import/export
- JSON data files
- XML data sources
- File-based configuration storage

## Architecture Patterns

### Flexible Source/Target Configuration
The new structure enables flexible YAML-based configurations:

```yaml
# Example: AFS → EVO (initial sync)
source:
  type: mssql
  classes: afs/
  
target:
  type: sqlite
  classes: evo/
```

```yaml
# Example: EVO → XT-Commerce (reverse sync)
source:
  type: sqlite
  classes: evo/
  
target:
  type: mysql
  classes: mysql/
```

### Separation of Concerns
- **Data Access Layer:** `mssql/`, `sqlite/`, `mysql/`, `file/`
- **Business Logic Layer:** `afs/`, `evo/`
- **Mapping Layer:** `mapping/`

## Autoloading
The `autoload.php` file automatically loads classes from all directories:
```php
$subdirs = ['afs', 'mssql', 'mapping', 'evo', 'sqlite', 'mysql', 'file'];
```

No namespace is used; classes are loaded based on filename matching class name.

## Migration Notes
All `AFS_Evo*` classes have been moved from `classes/afs/` to `classes/evo/`:
- Class names remain unchanged
- No code changes required in classes themselves
- All references still work through autoloader
- Tests and application continue to function

## Future Enhancements
1. **MySQL Support:** Implement MySQL classes in `mysql/` directory
2. **SQLite Abstraction:** Create generic SQLite wrapper in `sqlite/` directory
3. **File Handlers:** Move metadata loading to `file/` directory and expand functionality
4. **Multiple Sources:** Enable multi-source synchronization through YAML configuration
5. **Bidirectional Sync:** Support EVO → AFS and other reverse synchronization patterns
