# Code Style Guide

## Overview

This project follows the [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/) standard for PHP code. This ensures consistent, readable, and maintainable code across the entire codebase.

## Tools

### PHP_CodeSniffer (phpcs/phpcbf)

PHP_CodeSniffer is used to detect and automatically fix coding standard violations.

**Check for violations:**
```bash
composer cs:check
# or with make:
make cs-check
# or directly:
./vendor/bin/phpcs
```

**Automatically fix violations:**
```bash
composer cs:fix
# or with make:
make cs-fix
# or directly:
./vendor/bin/phpcbf
```

### PHPStan

PHPStan is used for static analysis to catch potential bugs and type errors.

**Run static analysis:**
```bash
composer stan
# or with make:
make stan
# or directly:
./vendor/bin/phpstan analyse
```

**Run all code quality checks:**
```bash
composer test:style
# or with make:
make test-style
```

## Configuration Files

- **phpcs.xml**: PHP_CodeSniffer configuration for PSR-12 standard
- **phpstan.neon**: PHPStan configuration for static analysis
- **.editorconfig**: Editor configuration for consistent formatting across different editors
- **composer.json**: Defines development dependencies and scripts
- **Makefile**: Convenient shortcuts for common commands

## PSR-12 Quick Reference

### Key Rules

1. **Indentation**: Use 4 spaces (no tabs)
2. **Line Length**: Lines should be 120 characters or less
3. **File Headers**: PHP files must use `<?php` opening tag
4. **Namespaces**: Must be declared on the line after opening tag
5. **Class Names**: Must be in `PascalCase`
6. **Method Names**: Must be in `camelCase`
7. **Constants**: Must be in `UPPER_CASE` with underscore separators
8. **Braces**: Opening braces for classes and methods must be on the next line
9. **Visibility**: All properties and methods must declare visibility (public, protected, private)

### Example

```php
<?php

declare(strict_types=1);

namespace App\Example;

class ExampleClass
{
    private string $propertyName;

    public function __construct(string $propertyName)
    {
        $this->propertyName = $propertyName;
    }

    public function exampleMethod(): string
    {
        return $this->propertyName;
    }
}
```

## CI/CD Integration

### GitHub Actions

A GitHub Actions workflow (`.github/workflows/code-style.yml`) automatically checks code style on:
- Pull requests to `main` and `develop` branches
- Pushes to `main` and `develop` branches

The workflow will fail if:
- PHP_CodeSniffer detects PSR-12 violations
- PHPStan detects type errors or potential bugs

## Setup for Developers

### Prerequisites

- PHP >= 8.1
- Composer

### Installation

```bash
# Install dependencies
composer install
# or with make:
make install

# Run code style check
composer cs:check
# or:
make cs-check

# Fix code style issues automatically
composer cs:fix
# or:
make cs-fix

# Run static analysis
composer stan
# or:
make stan
```

### Editor Integration

#### Visual Studio Code

Install the following extensions:
- [PHP Intelephense](https://marketplace.visualstudio.com/items?itemName=bmewburn.vscode-intelephense-client)
- [EditorConfig for VS Code](https://marketplace.visualstudio.com/items?itemName=EditorConfig.EditorConfig)
- [phpcs](https://marketplace.visualstudio.com/items?itemName=shevaua.phpcs)

Add to your `.vscode/settings.json`:
```json
{
    "php.validate.executablePath": "/usr/bin/php",
    "phpcs.enable": true,
    "phpcs.standard": "PSR12"
}
```

#### PhpStorm/IntelliJ IDEA

1. Go to **Settings/Preferences** → **Editor** → **Code Style** → **PHP**
2. Click **Set from...** → **Predefined Style** → **PSR-12**
3. Enable PHP_CodeSniffer:
   - Go to **Settings/Preferences** → **PHP** → **Quality Tools** → **PHP_CodeSniffer**
   - Configure the path to `phpcs`
   - Set coding standard to PSR12

## Troubleshooting

### phpcs not found

If you get a "command not found" error, ensure:
1. Composer dependencies are installed: `composer install`
2. The vendor/bin directory exists
3. You're running commands from the project root

### Too many violations

If you have many existing violations:
1. Run `composer cs:fix` to automatically fix what can be fixed
2. Review remaining violations with `composer cs:check`
3. Fix remaining issues manually

### PHPStan errors

PHPStan may report issues that aren't caught by phpcs:
1. Add proper type hints to methods and properties
2. Fix potential null pointer issues
3. Use proper return types
4. If a false positive, add to `ignoreErrors` in phpstan.neon

## Resources

- [PSR-12: Extended Coding Style](https://www.php-fig.org/psr/psr-12/)
- [PHP_CodeSniffer Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)
- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [EditorConfig Documentation](https://editorconfig.org/)
