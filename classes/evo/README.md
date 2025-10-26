# EVO Classes

This directory contains classes for handling the EVO intermediate database (SQLite).

## Purpose
The EVO classes are responsible for:
- Managing the intermediate SQLite database (`db/evo.db`)
- Synchronizing data from various sources (e.g., AFS-ERP)
- Providing data to target systems (e.g., xt:Commerce)

## Classes
- `AFS_Evo`: Main orchestration class for EVO synchronization
- `AFS_Evo_ArticleSync`: Article synchronization logic
- `AFS_Evo_CategorySync`: Category/Warengruppen synchronization
- `AFS_Evo_ImageSync`: Image management and synchronization
- `AFS_Evo_DocumentSync`: Document management and synchronization
- `AFS_Evo_AttributeSync`: Attribute synchronization
- `AFS_Evo_StatusTracker`: Status tracking for synchronization progress
- `AFS_Evo_DeltaExporter`: Export changed records to delta database
- `AFS_Evo_Reset`: Utility to clear EVO database tables
- `AFS_Evo_Base`: Base class with common utilities

## Usage
The EVO classes work as an intermediate layer between source systems (like AFS) and target systems (like xt:Commerce). They provide a normalized data structure that can be easily mapped to different target systems.
