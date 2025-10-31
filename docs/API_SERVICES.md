# API Services

Stand: 2025-10-30

Die API wurde auf Service-Klassen umgestellt (Mapping-only Architektur):
- classes/api/SyncService: führt die Synchronisation anhand der YAML-Dateien aus (mappings/afs.yml, mappings/evo.yml, mappings/afs_evo.yml).
- classes/api/HealthService: Health-Check für SQLite/MSSQL.
- classes/api/SetupService: Initialisiert/aktualisiert SQLite (evo.db, status.db).
- classes/api/MigrateService: Einfache Schema-Migrationen (Meta-/Update-Spalten).
- classes/api/RemoteStatusService: Aggregiert Remote-Server-Status.
- classes/api/StatusService: Liefert den aktuellen Mapping-Status (Job "mapping").

Endpoints wurden angepasst, um diese Services zu nutzen (z. B. api/sync_start.php).
