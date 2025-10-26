# EVO Classes

This directory contains classes for handling the EVO intermediate database (SQLite).

## Purpose
The EVO classes are responsible for:
- Managing the intermediate SQLite database (`db/evo.db`)
- Synchronizing data from various sources (e.g., AFS-ERP)
- Providing data to target systems (e.g., xt:Commerce)

## Classes
- `EVO`: Main orchestration class for EVO synchronization
- `EVO_ArticleSync`: Article synchronization logic
- `EVO_CategorySync`: Category/Warengruppen synchronization
- `EVO_ImageSync`: Image management and synchronization
- `EVO_DocumentSync`: Document management and synchronization
- `EVO_AttributeSync`: Attribute synchronization
- `EVO_DeltaExporter`: Export changed records to delta database
- `EVO_Reset`: Utility to clear EVO database tables
- `EVO_Base`: Base class with common utilities

Note: Status tracking has been moved to the `STATUS_Tracker` class in the `classes/status/` directory.

## Usage
The EVO classes work as an intermediate layer between source systems (like AFS) and target systems (like xt:Commerce). They provide a normalized data structure that can be easily mapped to different target systems.
