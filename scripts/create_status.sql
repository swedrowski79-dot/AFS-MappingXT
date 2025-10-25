-- scripts/create_status.sql
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;

CREATE TABLE IF NOT EXISTS sync_status (
    job TEXT PRIMARY KEY,
    total INTEGER DEFAULT 0,
    processed INTEGER DEFAULT 0,
    state TEXT DEFAULT 'idle',
    stage TEXT,
    message TEXT,
    started_at TEXT,
    updated_at TEXT,
    finished_at TEXT
);

CREATE TABLE IF NOT EXISTS sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job TEXT NOT NULL,
    level TEXT NOT NULL DEFAULT 'info', -- info|warning|error
    stage TEXT,
    message TEXT NOT NULL,
    context TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS ix_sync_log_job_created ON sync_log(job, created_at DESC);
CREATE INDEX IF NOT EXISTS ix_sync_log_job_level ON sync_log(job, level);
CREATE INDEX IF NOT EXISTS ix_sync_log_level ON sync_log(level);
CREATE INDEX IF NOT EXISTS ix_sync_log_stage ON sync_log(stage);
CREATE INDEX IF NOT EXISTS ix_sync_log_created ON sync_log(created_at DESC);

INSERT OR IGNORE INTO sync_status (job, total, processed, state, stage, message, started_at, updated_at, finished_at)
VALUES ('categories', 0, 0, 'ready', NULL, NULL, NULL, NULL, NULL);
