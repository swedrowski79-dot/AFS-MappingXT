# Contributing to AFS-MappingXT

Thank you for considering contributing to AFS-MappingXT! This document outlines the process and guidelines for contributing to this project.

## Development Workflow

1. **Fork the repository** (if external contributor)
2. **Create a feature branch** from `develop`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes**
4. **Test your changes** thoroughly
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
- **Testing**: Ensure all functionality works as expected
- **Documentation**: Update documentation if you change functionality
- **Commits**: Use clear, descriptive commit messages

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

## Questions?

If you have questions about contributing:

1. Check the [documentation](docs/)
2. Review existing issues and pull requests
3. Open a new issue with your question

## License

By contributing to this project, you agree that your contributions will be licensed under the same license as the project.

## Thank You!

Your contributions are greatly appreciated! Together we can make AFS-MappingXT better. 🎉
