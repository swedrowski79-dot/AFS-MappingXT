# Multi-Database Sync Configuration Guide

## Overview

The AFS-MappingXT system now supports multiple database synchronization pairs with flexible source and target configurations. This enables complex data flows between different systems like AFS-ERP (MSSQL), EVO (SQLite), and XT-Commerce (MySQL).

## New Features

### 1. SQLite_Connection Class

A new database abstraction class for SQLite operations:

**Location:** `classes/sqlite/SQLite_Connection.php`

**Key Features:**
- PDO wrapper with consistent API
- Transaction support
- Parametrized queries for security
- Performance optimizations (WAL mode, caching)
- Automatic connection management

**Basic Usage:**
```php
$conn = new SQLite_Connection('/path/to/database.db');
$rows = $conn->fetchAll("SELECT * FROM table WHERE id > ?", [100]);
$conn->execute("INSERT INTO table (name) VALUES (?)", ['value']);
$lastId = $conn->lastInsertId();
```

### 2. XT-Commerce Class Directory

New directory structure for XT-Commerce specific classes:

**Location:** `classes/xt/`

This directory is prepared for future XT-Commerce integration classes, similar to the existing `evo/` and `mssql/` directories.

### 3. Multi-Mapping Configuration

Configure multiple source-target sync pairs via environment variables.

**Configuration Location:** `.env` file (copy from `.env.example`)

**Available Sync Pairs:**

#### Primary Sync: AFS → EVO
```env
RULE_MAPPING=mapping/afs_evo.yml
# Optional: SOURCE_MAPPING, SCHEMA_MAPPING (werden sonst aus dem Mapping abgeleitet)
```

#### Secondary Sync: XT Orders → EVO
```env
SOURCE_MAPPING_2=mapping/xt-order.yaml
TARGET_MAPPING_2=mapping/orders-evo.yaml
```

#### Tertiary Sync: EVO Articles → XT
```env
SOURCE_MAPPING_3=mapping/evo-artikel.yaml
TARGET_MAPPING_3=mapping/xt-artikel.yaml
```

### 4. New YAML Mapping Files

Four new YAML configuration files define the database structures:

1. **xt-order.yaml** - XT-Commerce order database as source
   - Maps orders and order products from XT-Commerce MySQL
   
2. **orders-evo.yaml** - EVO orders database as target
   - Target structure for orders in SQLite
   
3. **evo-artikel.yaml** - EVO articles database as source
   - Maps articles, images, and relationships from EVO
   
4. **xt-artikel.yaml** - XT-Commerce articles as target
   - Target structure for products in XT-Commerce MySQL

## Environment Variables

### Database Connections

```env
# XT-Commerce MySQL
XT_MYSQL_HOST=localhost
XT_MYSQL_PORT=3306
XT_MYSQL_DB=xtcommerce
XT_MYSQL_USER=xt_user
XT_MYSQL_PASS=xt_password

# Additional Database Paths
ORDERS_DB_PATH=db/orders_evo.db
ORDERS_DELTA_DB_PATH=db/orders_evo_delta.db
```

### Sync Configuration

```env
# Enable specific sync actions (comma-separated)
SYNC_ENABLED_ACTIONS=sync_afs_to_evo,sync_xt_orders_to_evo

# Enable bidirectional sync (experimental)
SYNC_BIDIRECTIONAL=false
```

## Configuration in Code

The `config.php` now includes sync mapping configuration:

```php
'sync_mappings' => [
    'primary' => [
        'enabled' => true,
        'source' => getenv('SOURCE_MAPPING') ?: 'schemas/afs.yml',
        'target' => getenv('TARGET_MAPPING') ?: 'schemas/evo.yml',
        'action' => 'sync_afs_to_evo',
    ],
    'secondary' => [
        'enabled' => str_contains(getenv('SYNC_ENABLED_ACTIONS') ?: '', 'sync_xt_orders_to_evo'),
        'source' => getenv('SOURCE_MAPPING_2') ?: 'mapping/xt-order.yaml',
        'target' => getenv('TARGET_MAPPING_2') ?: 'mapping/orders-evo.yaml',
        'action' => 'sync_xt_orders_to_evo',
    ],
    // ... more mappings
],
```

## Use Cases

### 1. AFS to EVO (Default)
Standard sync from AFS-ERP MSSQL to EVO SQLite intermediate database.

### 2. XT Orders to EVO
Import orders from XT-Commerce into EVO database for processing.

```env
SYNC_ENABLED_ACTIONS=sync_afs_to_evo,sync_xt_orders_to_evo
```

### 3. EVO to XT Articles
Export articles from EVO to XT-Commerce MySQL database.

```env
SYNC_ENABLED_ACTIONS=sync_evo_to_xt
```

### 4. Bidirectional Sync
Synchronize changes in both directions (experimental).

```env
SYNC_BIDIRECTIONAL=true
```

## YAML Mapping Structure

### Source Configuration (e.g., xt-order.yaml)

```yaml
source:
  type: mysql
  name: XT-Commerce Orders
  description: XT-Commerce order database as source system

connection:
  host: ${XT_MYSQL_HOST}
  port: ${XT_MYSQL_PORT}
  database: ${XT_MYSQL_DB}
  username: ${XT_MYSQL_USER}
  password: ${XT_MYSQL_PASS}

entities:
  Orders:
    table: orders
    where: "status_id > 0"
    fields:
      orders_id:
        source: orders_id
        type: integer
      # ... more fields
```

### Target Configuration (e.g., orders-evo.yaml)

```yaml
target:
  type: sqlite
  name: EVO Orders
  description: SQLite database for EVO order synchronization

connection:
  database: ${ORDERS_DB_PATH}
  mode: readwrite

entities:
  orders:
    table: Orders
    primary_key: ID
    unique_key: XT_Order_ID
    fields:
      ID:
        type: integer
        auto_increment: true
      # ... more fields

delta:
  enabled: true
  export_database: ${ORDERS_DELTA_DB_PATH}
  track_field: update
```

## Migration Guide

### From Single Mapping to Multi-Mapping

1. **Update .env file:**
   ```bash
   cp .env.example .env
   # Edit .env with your configuration
   ```

2. **Configure sync actions:**
   ```env
   SYNC_ENABLED_ACTIONS=sync_afs_to_evo,sync_xt_orders_to_evo
   ```

3. **Add XT-Commerce credentials:**
   ```env
   XT_MYSQL_HOST=your-xt-host
   XT_MYSQL_DB=your-xt-database
   XT_MYSQL_USER=your-xt-user
   XT_MYSQL_PASS=your-xt-password
   ```

4. **Create additional databases:**
   ```bash
   # Orders database will be created automatically
   # Or create manually:
   sqlite3 db/orders_evo.db < scripts/create_orders.sql
   ```

## Testing

Run the included test scripts:

```bash
# Test SQLite_Connection
php scripts/test_sqlite_connection.php

# Test STATUS_Tracker
php scripts/test_status_tracker.php
```

## Future Extensions

The system is now prepared for:
- Additional database types (PostgreSQL, Oracle, etc.)
- More complex ETL workflows
- Custom transformation pipelines
- Real-time bidirectional sync
- Conflict resolution strategies

## Troubleshooting

### Issue: Sync mapping not found
**Solution:** Check that the YAML files exist in the `mapping/` directory and environment variables are correctly set.

### Issue: Database connection failed
**Solution:** Verify connection credentials in .env file and ensure database servers are accessible.

### Issue: Sync action not enabled
**Solution:** Add the action to `SYNC_ENABLED_ACTIONS` in .env:
```env
SYNC_ENABLED_ACTIONS=sync_afs_to_evo,sync_xt_orders_to_evo,sync_evo_to_xt
```

## Best Practices

1. **Always backup databases** before enabling new sync pairs
2. **Test with a subset of data** first
3. **Monitor sync logs** for errors and warnings
4. **Use delta tracking** to minimize sync overhead
5. **Configure appropriate indexes** on target databases
6. **Set realistic query timeouts** for large datasets

## Related Documentation

- [YAML Mapping Guide](YAML_MAPPING_GUIDE.md)
- [Class Structure](CLASS_STRUCTURE.md)
- [Hash Manager](HashManager.md)
- [Quick Start Docker](QUICK_START_DOCKER.md)
