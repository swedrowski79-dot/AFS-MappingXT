# File Classes

This directory contains classes for file-based data structures.

## Purpose
The File classes provide:
- File system-based data storage and retrieval
- Metadata management from file structures
- Support for file-based data sources

## Current Implementation
- Metadata loading from filesystem directories (meta_title, meta_description)
- Article metadata organized by article number
- Category metadata organized by category name

## Usage
File classes enable the system to work with data stored in the filesystem, such as:
- Article metadata in `/srcFiles/Data/Artikel/{ArticleNumber}/`
- Category metadata in `/srcFiles/Data/Warengruppen/{CategoryName}/`
- Other structured data in file format

This allows for flexible data enrichment from file-based sources without requiring a database.

## Example Structure
```
srcFiles/Data/
├── Artikel/
│   ├── 12345/
│   │   ├── meta_title.txt
│   │   └── meta_description.txt
│   └── 67890/
│       ├── meta_title.txt
│       └── meta_description.txt
└── Warengruppen/
    ├── CategoryName1/
    │   ├── meta_title.txt
    │   └── meta_description.txt
    └── CategoryName2/
        ├── meta_title.txt
        └── meta_description.txt
```
