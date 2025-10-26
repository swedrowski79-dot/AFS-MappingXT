# XT-Commerce Classes

This directory contains classes for XT-Commerce database operations.

## Purpose
The XT classes provide:
- Database connection and query execution for XT-Commerce
- Support for XT-Commerce as source or target database
- Bidirectional synchronization with EVO intermediate database

## Future Development
This directory is prepared for XT-Commerce database support, enabling the system to:
- Read data from XT-Commerce databases as a source (e.g., orders, articles)
- Write data to XT-Commerce databases as a target
- Support multiple XT-Commerce database connections for different entities

## Usage
XT classes will allow the system to work with XT-Commerce databases in addition to AFS and EVO. This enables scenarios like:
- Source: XT-Commerce Orders → Target: EVO
- Source: EVO Articles → Target: XT-Commerce
- Bidirectional sync between AFS, EVO, and XT-Commerce

## Configuration
XT database mappings are configured via YAML files in the `mappings/` directory:
- `xt-order.yaml` - XT-Commerce order database structure
- `xt-artikel.yaml` - XT-Commerce article database structure
- `orders-evo.yaml` - EVO orders target mapping
- `evo-artikel.yaml` - EVO articles source mapping
