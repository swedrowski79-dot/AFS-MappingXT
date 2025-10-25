# Quick Start: Code Style Setup

This guide helps you quickly set up the code style tools and start contributing to the project.

## 1. Prerequisites

Ensure you have:
- PHP >= 8.1
- Composer
- Git

Check your PHP version:
```bash
php --version
```

## 2. Install Dependencies

From the project root, run:

```bash
composer install
```

Or using make:
```bash
make install
```

This will install:
- PHP_CodeSniffer (phpcs/phpcbf) for PSR-12 compliance
- PHPStan for static analysis

## 3. Check Your Code

Before committing changes, run:

```bash
# Check code style
composer cs:check

# Fix automatically fixable issues
composer cs:fix

# Run static analysis
composer stan

# Run all checks at once
composer test:style
```

Or using make:
```bash
make cs-check    # Check code style
make cs-fix      # Fix code style
make stan        # Static analysis
make test-style  # All checks
```

## 4. Understand the Output

### PHP_CodeSniffer Output

```
FILE: /path/to/file.php
----------------------------------------------------------------------
FOUND 2 ERRORS AFFECTING 2 LINES
----------------------------------------------------------------------
 12 | ERROR | [ ] Expected 1 space after FUNCTION keyword; 0 found
 25 | ERROR | [x] Line indented incorrectly; expected 4 spaces, found 2
----------------------------------------------------------------------
```

- `[x]` = Can be fixed automatically with `composer cs:fix`
- `[ ]` = Must be fixed manually

### PHPStan Output

```
------ -------------------------------------------------------------------
 Line   file.php
------ -------------------------------------------------------------------
 15     Method Example::test() has no return type specified.
 23     Property Example::$name has no type specified.
------ -------------------------------------------------------------------
```

## 5. Fix Common Issues

### Missing Return Type
```php
// Bad
public function getName() {
    return $this->name;
}

// Good
public function getName(): string {
    return $this->name;
}
```

### Missing Property Type
```php
// Bad
private $name;

// Good
private string $name;
```

### Wrong Indentation
```php
// Bad
public function test()
{
  return true; // 2 spaces
}

// Good
public function test()
{
    return true; // 4 spaces
}
```

## 6. Pre-Commit Workflow

Before each commit:

1. **Write your code**
2. **Run code style check**: `make cs-check` or `composer cs:check`
3. **Fix issues automatically**: `make cs-fix` or `composer cs:fix`
4. **Fix remaining issues manually**
5. **Run static analysis**: `make stan` or `composer stan`
6. **Fix type issues if any**
7. **Commit your changes**

## 7. CI/CD Integration

When you push to GitHub or create a pull request:

1. GitHub Actions automatically runs code style checks
2. The workflow checks both PSR-12 compliance and static analysis
3. If checks fail, the PR cannot be merged
4. Fix issues locally and push again

## 8. Need Help?

- **Code Style Guide**: See [CODE_STYLE.md](CODE_STYLE.md) for detailed information
- **PSR-12 Standard**: https://www.php-fig.org/psr/psr-12/
- **PHP_CodeSniffer**: https://github.com/squizlabs/PHP_CodeSniffer/wiki
- **PHPStan**: https://phpstan.org/user-guide/getting-started

## 9. Common Commands Quick Reference

| Task | Composer | Make |
|------|----------|------|
| Install dependencies | `composer install` | `make install` |
| Check code style | `composer cs:check` | `make cs-check` |
| Fix code style | `composer cs:fix` | `make cs-fix` |
| Run static analysis | `composer stan` | `make stan` |
| Run all checks | `composer test:style` | `make test-style` |

## 10. Tips

- **Run `cs:fix` often**: It saves time by automatically fixing most issues
- **Use an IDE**: Modern IDEs like PhpStorm or VS Code can show issues in real-time
- **Install EditorConfig**: Most editors support `.editorconfig` for automatic formatting
- **Don't ignore warnings**: They help catch bugs before they reach production
- **When in doubt**: Run `make test-style` before committing

Happy coding! 🚀
