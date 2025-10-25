# Makefile for AFS-MappingXT Development

.PHONY: help install

# Default target
help:
	@echo "AFS-MappingXT Development Commands"
	@echo "===================================="
	@echo ""
	@echo "Setup:"
	@echo "  make install      Install composer dependencies"
	@echo ""

# Install composer dependencies
install:
	composer install
