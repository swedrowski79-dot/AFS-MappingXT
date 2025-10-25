# Contributing to AFS-MappingXT

Thank you for considering contributing to AFS-MappingXT! This document outlines the process and guidelines for contributing to this project.

## Code Style

This project follows the **PSR-12: Extended Coding Style** standard. All code contributions must comply with this standard.

### Setup

1. Install dependencies:
   ```bash
   composer install
   ```

2. Before committing, check your code:
   ```bash
   composer test:style
   ```

See [CODE_STYLE.md](docs/CODE_STYLE.md) and [CODE_STYLE_QUICKSTART.md](docs/CODE_STYLE_QUICKSTART.md) for detailed information.

## Development Workflow

1. **Fork the repository** (if external contributor)
2. **Create a feature branch** from `develop`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes**
4. **Test your changes**:
   - Run code style checks: `composer cs:check`
   - Fix style issues: `composer cs:fix`
   - Run static analysis: `composer stan`
5. **Commit your changes**:
   ```bash
   git commit -m "Add feature: your feature description"
   ```
6. **Push to your branch**:
   ```bash
   git push origin feature/your-feature-name
   ```
7. **Create a Pull Request** to the `develop` branch

## Pull Request Guidelines

- **Target branch**: Always create PRs against `develop`, not `main`
- **Description**: Provide a clear description of what changes you made and why
- **Testing**: Ensure all automated checks pass
- **Code style**: All code must pass PSR-12 checks
- **Documentation**: Update documentation if you change functionality
- **Commits**: Use clear, descriptive commit messages

## Code Quality Requirements

All pull requests must pass the following checks:

1. **PSR-12 compliance** (enforced by PHP_CodeSniffer)
2. **Static analysis** (enforced by PHPStan level 5)
3. **No syntax errors**
4. **Follows project conventions**

These checks run automatically via GitHub Actions when you create a PR.

## Commit Message Guidelines

Use clear and descriptive commit messages:

### Format
```
<type>: <subject>

<body>

<footer>
```

### Types
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, missing semicolons, etc.)
- `refactor`: Code refactoring
- `test`: Adding tests
- `chore`: Maintenance tasks

### Examples
```
feat: Add PSR-12 code style configuration

Added PHP_CodeSniffer and PHPStan configuration for automated
code quality checks. Includes GitHub Actions workflow.

Closes #123
```

```
fix: Correct SQL query in article sync

Fixed issue where article synchronization would fail with
duplicate key error.

Fixes #456
```

## Reporting Issues

When reporting issues, please include:

1. **Clear title** describing the issue
2. **Steps to reproduce** the problem
3. **Expected behavior**
4. **Actual behavior**
5. **Environment details** (PHP version, OS, etc.)
6. **Error messages** or logs if applicable

## Project Structure

```
AFS-MappingXT/
├── api/              # API endpoints
├── classes/          # Business logic classes
├── src/              # Namespaced source code
├── scripts/          # CLI scripts
├── docs/             # Documentation
├── mappings/         # YAML mapping configurations
├── Files/            # Media files (images, documents)
├── db/               # SQLite databases
└── logs/             # Log files
```

## Development Tools

- **PHP_CodeSniffer**: Code style checking and fixing
- **PHPStan**: Static analysis
- **EditorConfig**: Editor configuration
- **Git**: Version control

## Questions?

If you have questions about contributing:

1. Check the [documentation](docs/)
2. Review existing issues and pull requests
3. Open a new issue with your question

## License

By contributing to this project, you agree that your contributions will be licensed under the same license as the project.

## Thank You!

Your contributions are greatly appreciated! Together we can make AFS-MappingXT better. 🎉
