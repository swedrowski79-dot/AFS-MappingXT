# STATUS Classes

This directory contains classes for status tracking and logging functionality.

## Purpose
The STATUS classes are responsible for:
- Managing the status database (`db/status.db`)
- Tracking synchronization progress and state
- Logging operations and errors
- Providing status information to the API and CLI

## Classes
- `STATUS_Tracker`: Manages synchronization status in the status.db database
  - Tracks job state (ready, running, error)
  - Logs events, warnings, and errors
  - Maintains sync progress information
  - Enforces error log limits

- `STATUS_MappingLogger`: Unified JSON logger for mapping and delta operations
  - Writes structured logs to daily log files
  - Supports log levels (info, warning, error)
  - Provides specialized logging methods for sync operations
  - Manages log rotation

## Usage
These classes are used throughout the application to track synchronization status and log important events. They provide visibility into the sync process and help with debugging and monitoring.

### Example: STATUS_Tracker
```php
$tracker = new STATUS_Tracker($statusDbPath, 'categories', 200, 'warning');
$tracker->begin('artikel', 'Starting article sync');
$tracker->advance('artikel', ['processed' => 50, 'total' => 100]);
$tracker->complete(['processed' => 100, 'total' => 100]);
```

### Example: STATUS_MappingLogger
```php
$logger = new STATUS_MappingLogger($logDir, '1.0.0', 'warning');
$logger->logSyncStart(['job' => 'categories']);
$logger->logRecordChanges('Artikel', 10, 5, 2, 100);
$logger->logSyncComplete(45.2, ['total_records' => 100]);
```
