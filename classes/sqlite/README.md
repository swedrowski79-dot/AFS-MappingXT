# SQLite Classes

This directory contains classes for direct SQLite database operations.

## Purpose
The SQLite classes provide:
- Low-level SQLite database access
- Query builders for SQLite-specific operations
- Connection management for SQLite databases

## Future Development
This directory is prepared for SQLite-specific database abstraction classes that can be used independently of the EVO intermediate database. This allows for flexible database operations across different SQLite databases.

## Usage
Classes in this directory can be used for any SQLite database operations, not just the EVO database. This separation allows for:
- Reusable SQLite components
- Multiple SQLite database connections
- Database-agnostic code in higher layers
