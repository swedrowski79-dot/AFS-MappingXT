# Makefile for AFS-MappingXT Development

.PHONY: help install cs-check cs-fix stan test-style

# Default target
help:
	@echo "AFS-MappingXT Development Commands"
	@echo "===================================="
	@echo ""
	@echo "Setup:"
	@echo "  make install      Install composer dependencies"
	@echo ""
	@echo "Code Quality:"
	@echo "  make cs-check     Check code style (PSR-12)"
	@echo "  make cs-fix       Fix code style issues automatically"
	@echo "  make stan         Run static analysis (PHPStan)"
	@echo "  make test-style   Run all code quality checks"
	@echo ""

# Install composer dependencies
install:
	composer install

# Check code style
cs-check:
	composer cs:check

# Fix code style
cs-fix:
	composer cs:fix

# Run static analysis
stan:
	composer stan

# Run all code quality tests
test-style:
	composer test:style
