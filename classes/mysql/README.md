# MySQL Classes

This directory contains classes for MySQL database operations.

## Purpose
The MySQL classes provide:
- MySQL database connection and query execution
- MySQL-specific query builders and helpers
- Support for MySQL as source or target database

## Future Development
This directory is prepared for MySQL database support, enabling the system to:
- Read data from MySQL databases as a source
- Write data to MySQL databases as a target
- Use MySQL for intermediate data storage

## Usage
Once implemented, MySQL classes will allow the system to work with MySQL databases in addition to MSSQL and SQLite. This enables scenarios like:
- Source: MySQL → Target: EVO
- Source: EVO → Target: MySQL
- Source: AFS (MSSQL) → Target: MySQL
