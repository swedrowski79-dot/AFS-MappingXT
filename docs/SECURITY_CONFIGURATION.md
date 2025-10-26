# Security Configuration Documentation

## Overview

AFS-MappingXT includes a configurable security mechanism that can restrict direct access to the main entry points (`index.php` and `indexcli.php`). When enabled, these files can only be accessed through the API endpoints, providing an additional layer of security for production environments.

## Configuration

### Enable Security Mode

Add the following to your `.env` file:

```bash
AFS_SECURITY_ENABLED=true
```

Or set as an environment variable:

```bash
export AFS_SECURITY_ENABLED=true
```

### Disable Security Mode (Default)

```bash
AFS_SECURITY_ENABLED=false
```

## How It Works

### Security Validation

When security mode is enabled:

1. **index.php**: Direct browser access is blocked with an HTTP 403 error and a user-friendly error page
2. **indexcli.php**: Direct CLI execution is blocked with an error message to STDERR
3. **API Access**: Normal operation continues when calls originate from the `api/` directory

### Detection Mechanism

The security system uses PHP's `debug_backtrace()` to analyze the call stack and determine if a request originated from an API endpoint. This is a reliable method because:

- It inspects the actual execution path
- Cannot be spoofed by external requests
- Works for both HTTP and CLI contexts

### Visual Flow

```
┌─────────────────────────────────────────────────┐
│ Security Disabled (AFS_SECURITY_ENABLED=false)  │
├─────────────────────────────────────────────────┤
│                                                 │
│  Browser → index.php ✓ Allowed                 │
│  CLI → indexcli.php ✓ Allowed                  │
│  API → index.php ✓ Allowed                     │
│  API → indexcli.php ✓ Allowed                  │
│                                                 │
└─────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────┐
│ Security Enabled (AFS_SECURITY_ENABLED=true)    │
├─────────────────────────────────────────────────┤
│                                                 │
│  Browser → index.php ✗ Blocked (403)           │
│  CLI → indexcli.php ✗ Blocked (exit 1)         │
│  API → index.php ✓ Allowed                     │
│  API → indexcli.php ✓ Allowed                  │
│                                                 │
└─────────────────────────────────────────────────┘
```

## Use Cases

### When to Enable Security Mode

- **Production Environments**: Prevent unauthorized direct access to management interfaces. This ensures users cannot bypass API authentication/authorization mechanisms by directly accessing the application entry points (index.php/indexcli.php), which could expose sensitive operations or data.
- **API-Only Deployments**: Ensure all interactions go through controlled API endpoints where authentication, rate limiting, and other security measures can be consistently applied
- **Multi-Server Setups**: Restrict access to internal application files, preventing unauthorized servers or services from executing entry points directly
- **Compliance Requirements**: Meet security policies requiring API-based access control and auditable access patterns

### When to Keep Security Disabled

- **Development Environments**: Allow direct access for testing and debugging
- **Single-User Installations**: When direct UI access is needed
- **Initial Setup**: During configuration and testing phases

## Error Messages

### Browser Access (index.php)

When security is enabled and direct access is attempted, users see:

```
🔒 Zugriff verweigert

Direkter Zugriff auf index.php ist nicht erlaubt.

Der Sicherheitsmodus ist aktiviert. Zugriff ist nur über die 
API-Schnittstelle erlaubt.

Bitte verwenden Sie die API-Endpunkte im api/ Verzeichnis für 
den Zugriff auf diese Funktionalität.
```

### CLI Access (indexcli.php)

When security is enabled and direct CLI execution is attempted:

```
FEHLER: Direkter Zugriff auf indexcli.php ist nicht erlaubt.
Der Sicherheitsmodus ist aktiviert. Zugriff ist nur über die API-Schnittstelle erlaubt.
```

## API Integration

All API endpoints continue to work normally when security is enabled:

- `api/sync_start.php` - Start synchronization
- `api/sync_status.php` - Get sync status
- `api/health.php` - System health check
- `api/db_setup.php` - Database setup
- All other API endpoints...

The security mechanism transparently allows these endpoints to function while blocking direct access.

## Testing

### Unit Tests

Run the security validator tests:

```bash
php scripts/test_security_validator.php
```

Expected output:
```
Testing SecurityValidator functionality...

Test 1: SecurityValidator class exists... ✓ PASS
Test 2: isSecurityEnabled with security disabled... ✓ PASS
Test 3: isSecurityEnabled with security enabled... ✓ PASS
Test 4: isCalledFromApi (direct call)... ✓ PASS
Test 5: isCalledFromApi (simulated from API)... ✓ PASS
Test 6: Configuration security setting... ✓ PASS

✅ All tests passed!
```

### Integration Tests

Run the integration tests:

```bash
php scripts/test_security_integration.php
```

### Manual Testing

1. **Test with security disabled**:
   ```bash
   # In .env
   AFS_SECURITY_ENABLED=false
   
   # Access index.php in browser - should work
   # Run: php indexcli.php status - should work
   ```

2. **Test with security enabled**:
   ```bash
   # In .env
   AFS_SECURITY_ENABLED=true
   
   # Access index.php in browser - should show 403 error
   # Run: php indexcli.php status - should show error
   # Access api/sync_status.php - should work normally
   ```

## Implementation Details

### Class: SecurityValidator

Located in `classes/security/SecurityValidator.php`

**Methods:**

- `isSecurityEnabled(array $config): bool` - Check if security is enabled
- `isCalledFromApi(): bool` - Detect if call originates from API directory
- `validateAccess(array $config, string $entryPoint): void` - Web/HTTP validation with HTML error page
- `validateCliAccess(array $config, string $entryPoint): void` - CLI validation with STDERR error output

### Integration Points

1. **index.php** (Line 33-35):
   ```php
   // Security check: If security is enabled, only allow access from API
   SecurityValidator::validateAccess($config, 'index.php');
   ```

2. **indexcli.php** (Line 23-25):
   ```php
   // Security check: If security is enabled, only allow CLI access from API context
   SecurityValidator::validateCliAccess($config, 'indexcli.php');
   ```

3. **config.php** (Line 84-86):
   ```php
   'security' => [
       'enabled' => filter_var(getenv('AFS_SECURITY_ENABLED'), FILTER_VALIDATE_BOOLEAN),
   ],
   ```

## Migration Guide

### Upgrading to Security-Aware Version

1. **Pull the latest changes**
2. **Check your .env file**:
   - Add `AFS_SECURITY_ENABLED=false` to maintain current behavior
   - Or enable security with `AFS_SECURITY_ENABLED=true`
3. **Test your setup**:
   ```bash
   php scripts/test_security_validator.php
   php scripts/test_security_integration.php
   ```
4. **Update documentation** if you have custom deployment procedures

### No Breaking Changes

The default behavior is **security disabled**, so existing installations continue to work without modification.

## Security Considerations

### What This Feature Protects Against

- ✅ Unauthorized direct access to web interface (bypassing authentication/authorization at API level)
- ✅ Direct execution of management scripts without proper authorization
- ✅ Uncontrolled access patterns that bypass centralized security measures

### What This Feature Does NOT Protect Against

The following security concerns are handled by other system components and are outside the scope of this feature:

- ❌ **API endpoint vulnerabilities**: API endpoints still require their own authentication, input validation, and authorization
- ❌ **SQL injection or other code vulnerabilities**: These require secure coding practices throughout the application
- ❌ **Network-level attacks**: Such as DDoS, man-in-the-middle, or packet sniffing - these require network security measures
- ❌ **Server misconfiguration**: Proper web server, PHP, and operating system configuration is still required
- ❌ **Dependency vulnerabilities**: Regular updates and security patches for PHP and dependencies are needed

### Best Practices

1. **Enable in Production**: Always enable security mode for production deployments
2. **Use HTTPS**: Combine with HTTPS for encrypted communication
3. **Firewall Rules**: Add network-level restrictions
4. **Regular Updates**: Keep the system updated for security patches
5. **Monitor Logs**: Watch for unauthorized access attempts

## Troubleshooting

### Problem: API calls return 403

**Cause**: Security is enabled but the detection mechanism isn't recognizing API calls

**Solution**: 
- Check that API files are in the `api/` directory
- Verify the autoloader includes the security subdirectory
- Run tests to validate the detection mechanism

### Problem: Direct access still works with security enabled

**Cause**: Configuration not loaded or .env not read

**Solution**:
- Verify `.env` file exists and is readable
- Check `getenv('AFS_SECURITY_ENABLED')` returns 'true'
- Confirm config.php includes the security configuration

### Problem: Everything is blocked including API

**Cause**: Misconfiguration or file permission issues

**Solution**:
- Run `php scripts/test_security_validator.php`
- Check file permissions on classes/security/
- Verify autoload.php includes 'security' in subdirectories array

## Future Enhancements

Possible future improvements:

- API key authentication for additional security
- Rate limiting for API endpoints
- IP whitelist/blacklist support
- Audit logging of access attempts
- Integration with external authentication systems

## Support

For issues or questions:

1. Check this documentation
2. Run the test scripts
3. Review the implementation in `classes/security/SecurityValidator.php`
4. Open an issue on GitHub with test results
