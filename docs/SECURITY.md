# Security Documentation

This document outlines the security measures implemented in the AFS-MappingXT application.

## Security Headers

The application implements comprehensive security headers at multiple levels to protect against common web vulnerabilities.

### HTTP Security Headers

All responses include the following security headers:

#### X-Content-Type-Options
```
X-Content-Type-Options: nosniff
```
Prevents browsers from MIME-sniffing a response away from the declared content-type, protecting against MIME confusion attacks.

#### X-Frame-Options
```
X-Frame-Options: SAMEORIGIN
```
Prevents clickjacking attacks by ensuring the application cannot be embedded in frames from other origins.

#### X-XSS-Protection
```
X-XSS-Protection: 1; mode=block
```
Enables the browser's built-in XSS filter to block detected cross-site scripting attacks.

#### Referrer-Policy
```
Referrer-Policy: strict-origin-when-cross-origin
```
Controls how much referrer information is included with requests, balancing privacy and functionality.

#### Content-Security-Policy (CSP)
```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'
```

The CSP policy:
- **default-src 'self'**: Only allows resources from the same origin by default
- **script-src 'self' 'unsafe-inline'**: Allows scripts from same origin and inline scripts (needed for the embedded UI)
- **style-src 'self' 'unsafe-inline'**: Allows styles from same origin and inline styles (needed for the embedded UI)
- **img-src 'self' data:**: Allows images from same origin and data URIs
- **font-src 'self' data:**: Allows fonts from same origin and data URIs
- **connect-src 'self'**: Only allows AJAX/WebSocket connections to same origin
- **frame-ancestors 'self'**: Prevents embedding in frames from other origins
- **base-uri 'self'**: Restricts the URLs that can be used in the document's `<base>` element
- **form-action 'self'**: Restricts the URLs where forms can be submitted

#### Permissions-Policy
```
Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()
```

Disables unnecessary browser features that this application doesn't require:
- Accelerometer
- Camera
- Geolocation
- Gyroscope
- Magnetometer
- Microphone
- Payment API
- USB API

#### Server Signature Removal
```
Header unset Server
Header unset X-Powered-By
```
Removes server version information to prevent information disclosure.

### HTTPS/TLS (HSTS)

For production deployments with HTTPS, you should enable HTTP Strict Transport Security (HSTS):

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

This header is commented out by default in the configuration files but can be enabled once HTTPS is properly configured.

## Implementation Locations

Security headers are implemented at three levels for defense in depth:

### 1. Apache Configuration
- **File**: `docker/apache2.conf`
- **Scope**: Global Apache configuration
- **Purpose**: Provides baseline security for all requests

### 2. Virtual Host Configuration
- **File**: `docker/afs-mappingxt.conf`
- **Scope**: Specific to the AFS-MappingXT virtual host
- **Purpose**: Provides application-specific security headers

### 3. .htaccess
- **File**: `.htaccess`
- **Scope**: Directory-level configuration
- **Purpose**: Provides security headers when Apache configuration cannot be modified
- **Additional**: Includes CORS headers for API endpoints, HTTP compression, and cache control

### 4. PHP Application Level
- **Files**: 
  - `index.php` (main UI)
  - `api/_bootstrap.php` (API responses)
  - `api/health.php` (health check endpoint)
- **Scope**: Individual PHP responses
- **Purpose**: Ensures security headers are present even if Apache headers fail

## HTTP Compression

The application implements HTTP compression for optimal performance:

### Compression Configuration

```apache
<IfModule mod_deflate.c>
    # Text files
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript
    AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json
    
    # XML files
    AddOutputFilterByType DEFLATE application/xml application/xhtml+xml application/rss+xml
    
    # SVG
    AddOutputFilterByType DEFLATE image/svg+xml
    
    # Fonts
    AddOutputFilterByType DEFLATE application/font-woff application/font-woff2
    
    # Vary header for proper caching of compressed content
    Header append Vary Accept-Encoding
</IfModule>
```

**Benefits**:
- Reduces bandwidth usage by 60-80% for text-based resources
- Improves page load times significantly
- Minimal CPU overhead on modern servers

## Cache Control Headers

The application implements comprehensive caching strategies for different resource types:

### Static Assets - Long Cache (1 year, immutable)

Images and fonts are cached with long expiration times and marked as immutable:

```apache
# Images
<FilesMatch "\.(jpg|jpeg|png|gif|webp|svg|ico)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

# Fonts
<FilesMatch "\.(woff|woff2|ttf|otf|eot)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
```

### CSS and JavaScript - Moderate Cache (1 month, revalidation)

```apache
<FilesMatch "\.(css|js)$">
    Header set Cache-Control "public, max-age=2592000, must-revalidate"
</FilesMatch>
```

### Dynamic Content - No Cache

API endpoints and dynamic PHP content are marked as non-cacheable:

```apache
<FilesMatch "\.php$">
    Header set Cache-Control "no-cache, no-store, must-revalidate"
    Header set Pragma "no-cache"
    Header set Expires "0"
</FilesMatch>
```

### API Response Headers

All API responses include proper cache control headers:

```php
// API responses (dynamic data - no caching)
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Vary: Accept-Encoding');
```

**Cache-Control Directives Explained**:
- `public`: Response may be cached by any cache (browser, CDN)
- `max-age=N`: Maximum time (in seconds) the resource is considered fresh
- `immutable`: Indicates the resource will never change (optimizes cache behavior)
- `must-revalidate`: Forces cache to verify with server after expiration
- `no-cache`: Must revalidate with server before using cached copy
- `no-store`: Do not cache this response at all

## PHP Security Configuration

Additional security settings in `docker/php.ini`:

```ini
; Hide PHP version
expose_php = Off

; Prevent code execution from remote URLs
allow_url_include = Off

; Session security
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
session.use_only_cookies = 1

; Disable error display in production
display_errors = Off
display_startup_errors = Off
log_errors = On
```

## File Access Protection

### Sensitive Files Protection
The following files are protected from direct web access:

- Configuration files: `config.php`, `.env`, `composer.json`, `composer.lock`
- Database files: `*.db`, `*.db-shm`, `*.db-wal`
- Version control: `.git/*`, `.github/*`
- Hidden files: `.*` (except `.well-known`)

### Directory Restrictions
The following directories are restricted:

- `/db` - Database files
- `/logs` - Log files
- `/scripts` - Management scripts
- `/.git` - Version control
- `/.github` - GitHub workflows

## CORS Configuration

API endpoints include CORS headers to control cross-origin access:

```apache
Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Methods "GET, POST, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Accept"
Header always set Access-Control-Max-Age "3600"
```

**Note**: In production, consider restricting `Access-Control-Allow-Origin` to specific trusted origins instead of using `*`.

## Best Practices

### For Production Deployments

1. **Enable HTTPS**: Always use HTTPS in production
2. **Enable HSTS**: Uncomment the HSTS header in configuration files
3. **Restrict CORS**: Change `Access-Control-Allow-Origin` from `*` to specific trusted origins
4. **Regular Updates**: Keep Apache, PHP, and all dependencies up to date
5. **Monitor Logs**: Regularly review error logs and access logs
6. **Database Security**: Ensure database files have proper file permissions (not web-accessible)
7. **Secure Credentials**: Use environment variables for sensitive configuration (see `.env.example`)

### Security Testing

To verify security headers are properly set, you can use:

```bash
# Test main UI
curl -I http://localhost/

# Test API endpoints
curl -I http://localhost/api/health.php

# Test with security header scanner
curl -I http://localhost/ | grep -i "x-\|content-security\|permissions-policy"
```

Online tools:
- [Security Headers](https://securityheaders.com/)
- [Mozilla Observatory](https://observatory.mozilla.org/)

## Reporting Security Issues

If you discover a security vulnerability, please report it responsibly:

1. **Do not** create a public GitHub issue
2. Contact the project maintainers directly
3. Provide detailed information about the vulnerability
4. Allow reasonable time for a fix before public disclosure

## Additional Resources

- [OWASP Secure Headers Project](https://owasp.org/www-project-secure-headers/)
- [Mozilla Web Security Guidelines](https://infosec.mozilla.org/guidelines/web_security)
- [CSP Evaluator](https://csp-evaluator.withgoogle.com/)
