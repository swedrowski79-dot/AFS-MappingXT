# Error and Exception Handling Strategy

## Overview

This document describes the standardized error and exception handling strategy used throughout the AFS-MappingXT project.

## Exception Hierarchy

The project uses a tiered exception system built on PHP's standard exceptions:

### Custom Exception Classes

#### `AFS_SyncBusyException extends RuntimeException`
**Purpose:** Indicates that a synchronization is already running when another sync is attempted.

**Usage:**
```php
if ($status['state'] === 'running') {
    throw new AFS_SyncBusyException('Synchronisation läuft bereits. Bitte warten.');
}
```

**Handling:** Caught in API endpoints and returns HTTP 409 (Conflict) status.

#### `AFS_ConfigurationException extends RuntimeException`
**Purpose:** Configuration and YAML parsing errors.

**Use cases:**
- Missing configuration files
- Invalid YAML syntax
- Missing required configuration keys
- Invalid configuration values
- Missing or invalid SQL schema files

**Example:**
```php
if (!is_file($configPath)) {
    throw new AFS_ConfigurationException("Configuration file not found: {$configPath}");
}
```

#### `AFS_DatabaseException extends RuntimeException`
**Purpose:** Database operation failures.

**Use cases:**
- Database connection failures
- SQL execution errors
- Transaction failures
- Database file not found
- Database write operations failing

**Example:**
```php
if (!$this->conn) {
    throw new AFS_DatabaseException("MSSQL connection failed: " . $errorMessage);
}
```

#### `AFS_ValidationException extends InvalidArgumentException`
**Purpose:** Data validation and argument validation failures.

**Use cases:**
- Invalid method arguments
- Data type mismatches
- Missing required data
- Data constraint violations

**Example:**
```php
if (!is_object($db)) {
    throw new AFS_ValidationException('$db must be an object.');
}
```

## Exception Handling Patterns

### 1. Catch Block Consistency

**Standard:** Always use `\Throwable` with a leading backslash for maximum compatibility.

```php
try {
    // ... operation
} catch (\Throwable $e) {
    // ... handle error
}
```

**Rationale:** 
- Catches both `Exception` and `Error` classes
- Consistent namespace resolution
- PHP 8.1+ compatibility

### 2. Propagating Exceptions

Let exceptions propagate when:
- The error cannot be meaningfully handled at the current level
- Higher-level code has better context for error handling
- The operation cannot continue without the failed component

```php
private function loadConfiguration(): void
{
    // Let exception propagate to caller
    $this->config = yaml_parse_file($this->configPath);
}
```

### 3. Exception Chaining

Use exception chaining to preserve the original error context:

```php
try {
    $mssql->connect();
} catch (\Throwable $e) {
    throw new AFS_DatabaseException(
        'MSSQL connection failed: ' . $e->getMessage(),
        0,
        $e  // Preserve original exception
    );
}
```

### 4. Transaction Error Handling

Always rollback database transactions on error:

```php
$pdo->beginTransaction();
try {
    // ... database operations
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
```

### 5. Structured Error Logging

**Do NOT use:** `error_log()` for application errors.

**Use instead:** 
- `AFS_MappingLogger` for permanent structured logs
- `AFS_Evo_StatusTracker` for sync status and UI feedback

```php
// WRONG
error_log('Error loading articles: ' . $e->getMessage());

// RIGHT
$this->logger->logError('Error loading articles', [
    'exception' => get_class($e),
    'message' => $e->getMessage(),
    'trace' => $e->getTraceAsString(),
], 'articles');
```

## API Error Handling

### Standard Pattern

All API endpoints follow this pattern:

```php
try {
    // ... main operation
    api_ok($result);
} catch (AFS_SyncBusyException $e) {
    api_error($e->getMessage(), 409);
} catch (\Throwable $e) {
    if (isset($tracker)) {
        $tracker->logError($e->getMessage(), [
            'endpoint' => 'endpoint_name'
        ], 'api');
        $tracker->fail($e->getMessage(), 'api');
    }
    api_error($e->getMessage());
} finally {
    // Cleanup (e.g., close database connections)
}
```

### HTTP Status Codes

- `200 OK` - Successful operation
- `405 Method Not Allowed` - Wrong HTTP method
- `409 Conflict` - `AFS_SyncBusyException` (sync already running)
- `500 Internal Server Error` - All other exceptions

## Best Practices

### 1. Exception Messages

- **Be specific:** Include relevant context (file paths, entity names, etc.)
- **Be actionable:** Help the user understand what went wrong and how to fix it
- **Use English for code, German for user-facing messages** (project convention)

```php
// Good
throw new AFS_ConfigurationException("Configuration file not found: {$configPath}");

// Bad
throw new RuntimeException("Error");
```

### 2. Resource Cleanup

Always clean up resources in `finally` blocks or ensure cleanup even on error:

```php
try {
    $mssql = createMssql($config);
    $result = performSync($mssql);
    return $result;
} finally {
    if (isset($mssql)) {
        $mssql->close();
    }
}
```

### 3. Silent Failures

Avoid silently swallowing exceptions unless absolutely necessary:

```php
// AVOID
try {
    $tracker->logInfo('Status update', $data);
} catch (\Throwable $e) {
    // Silent failure - OK only if tracker errors shouldn't stop main operation
}
```

When silently catching exceptions, **always document why** with a comment.

### 4. Type-Specific Catch Blocks

Catch specific exceptions when different handling is needed:

```php
try {
    performSync();
} catch (AFS_SyncBusyException $e) {
    // Specific handling for busy state
    return ['status' => 'busy', 'retry_after' => 60];
} catch (AFS_DatabaseException $e) {
    // Database-specific error handling
    logDatabaseError($e);
    throw $e;
} catch (\Throwable $e) {
    // General error handling
    logGeneralError($e);
    throw $e;
}
```

## Testing Exception Handling

Ensure exception handling is tested:

```php
public function testInvalidConfiguration(): void
{
    $this->expectException(AFS_ConfigurationException::class);
    $this->expectExceptionMessage('Configuration file not found');
    
    new AFS_MappingConfig('/nonexistent/path.yml');
}
```

## Migration Notes

### Previous Issues

1. **Inconsistent `Throwable` usage:** Mix of `Throwable` and `\Throwable`
   - **Fixed:** All catch blocks now use `\Throwable`

2. **`error_log()` usage:** Direct error_log calls bypassed structured logging
   - **Fixed:** Removed error_log, exceptions now propagate properly

3. **Generic exceptions:** Overuse of `RuntimeException` and `InvalidArgumentException`
   - **Fixed:** Introduced specific exception types for better error categorization

4. **Silent failures:** Exceptions caught and logged without propagation
   - **Fixed:** Exceptions propagate to appropriate level for handling

## Summary

- Use specific exception types for better error categorization
- Always use `\Throwable` in catch blocks
- Let exceptions propagate unless they can be meaningfully handled
- Use structured logging via `AFS_MappingLogger` and `AFS_Evo_StatusTracker`
- Clean up resources properly using `finally` blocks
- Include relevant context in exception messages
- Test exception handling paths
