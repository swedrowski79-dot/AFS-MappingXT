# Class Reorganization Summary

## Overview
This document summarizes the class reorganization performed to establish clear naming conventions and directory structure.

## Changes Made

### 1. Created New Directory Structure
- Added `classes/status/` directory for status tracking and logging classes

### 2. Class Renaming

#### STATUS Classes (formerly in classes/afs/ and classes/evo/)
- `AFS_MappingLogger` → `STATUS_MappingLogger` (moved to classes/status/)
- `AFS_Evo_StatusTracker` → `STATUS_Tracker` (moved to classes/status/)

#### EVO Classes (classes/evo/)
- `AFS_Evo` → `EVO`
- `AFS_Evo_ArticleSync` → `EVO_ArticleSync`
- `AFS_Evo_AttributeSync` → `EVO_AttributeSync`
- `AFS_Evo_Base` → `EVO_Base`
- `AFS_Evo_CategorySync` → `EVO_CategorySync`
- `AFS_Evo_DeltaExporter` → `EVO_DeltaExporter`
- `AFS_Evo_DocumentSync` → `EVO_DocumentSync`
- `AFS_Evo_ImageSync` → `EVO_ImageSync`
- `AFS_Evo_Reset` → `EVO_Reset`

#### MSSQL Classes (classes/mssql/)
- `MSSQL` → `MSSQL_Connection`

#### AFS Classes (classes/afs/)
- No changes needed - already follow AFS_ naming convention

### 3. Updated References
All references to renamed classes were updated in:
- `api/_bootstrap.php`
- `api/db_clear.php`
- `api/sync_health.php`
- `api/sync_start.php`
- `indexcli.php`
- `classes/afs/AFS_Get_Data.php`
- All EVO classes (internal references)
- `autoload.php` (added status directory)

### 4. Documentation
- Updated `classes/evo/README.md`
- Created `classes/status/README.md`

## Directory Structure

```
classes/
├── afs/          # AFS_* classes - fetch data from AFS and prepare for EVO
├── evo/          # EVO_* classes - write to EVO database and manage delta
├── mssql/        # MSSQL_* classes - handle MSSQL connections
├── status/       # STATUS_* classes - status tracking and logging
├── mapping/      # Mapping utility classes
├── mysql/        # MySQL placeholder
├── sqlite/       # SQLite placeholder
└── file/         # File handling placeholder
```

## Class Responsibilities

### AFS Classes
Fetch data from AFS-Manager (MSSQL database) and prepare it for the EVO database:
- Handle MSSQL queries
- Transform and map source data
- Parse configuration files (YAML)
- Build SQL statements
- Manage metadata and hashing

### EVO Classes
Write data to EVO database (SQLite) and manage delta database:
- Synchronize articles, categories, images, documents, and attributes
- Manage delta exports for changed records
- Handle database operations
- Provide reset functionality

### MSSQL Classes
Handle Microsoft SQL Server database connections:
- Connect to MSSQL server
- Execute queries with proper error handling
- Manage connection lifecycle

### STATUS Classes
Handle status tracking and logging:
- Track synchronization progress in status.db
- Log events, warnings, and errors
- Manage log rotation
- Provide status information to API and CLI

## Migration Notes

If you have existing code using the old class names:
1. Replace `new AFS_Evo(` with `new EVO(`
2. Replace `AFS_Evo_StatusTracker` with `STATUS_Tracker`
3. Replace `AFS_MappingLogger` with `STATUS_MappingLogger`
4. Replace `new MSSQL(` with `new MSSQL_Connection(`
5. Update any type hints and docblocks accordingly

The autoloader has been updated to support the new structure automatically.
