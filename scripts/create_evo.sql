-- scripts/create_evo.sql
PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA temp_store = MEMORY;

CREATE TABLE IF NOT EXISTS Artikel (
    ID            INTEGER PRIMARY KEY AUTOINCREMENT,
    AFS_ID        INTEGER,
    XT_ID         INTEGER,
    Art           TEXT,
    Artikelnummer TEXT NOT NULL,
    Bezeichnung   TEXT NOT NULL,
    EANNummer     TEXT,
    Bestand       INTEGER,
    Preis         REAL,
    AFS_Warengruppe_ID INTEGER,
    XT_Category_ID INTEGER,
    Category      INTEGER,
    Master        INTEGER NOT NULL DEFAULT 0 CHECK (Master IN (0,1)),
    Masterartikel TEXT,
    Mindestmenge  INTEGER,
    Gewicht       REAL,
    Online        INTEGER NOT NULL DEFAULT 0 CHECK (Online IN (0,1)),
    Einheit       TEXT,
    Langtext      TEXT,
    Werbetext     TEXT,
    Meta_Title    TEXT,
    Meta_Description TEXT,
    Bemerkung     TEXT,
    Hinweis       TEXT,
    "update"      INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    last_update   TEXT,
    last_imported_hash TEXT,
    last_seen_hash TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artikel_artikelnummer ON Artikel(Artikelnummer);
CREATE UNIQUE INDEX IF NOT EXISTS ux_artikel_ean ON Artikel(EANNummer) WHERE EANNummer IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_artikel_afs_id ON Artikel(AFS_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_online ON Artikel(Online);
CREATE INDEX IF NOT EXISTS ix_artikel_imported_hash ON Artikel(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_artikel_update ON Artikel("update") WHERE "update" = 1;
CREATE INDEX IF NOT EXISTS ix_artikel_xt_id ON Artikel(XT_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_category ON Artikel(Category);

CREATE TABLE IF NOT EXISTS Attribute (
    ID          INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_Attrib_ID INTEGER,
    Attribname  TEXT NOT NULL,
    "update"   INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    last_imported_hash TEXT,
    last_seen_hash TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_attribute_name ON Attribute(Attribname);
CREATE INDEX IF NOT EXISTS ix_attribute_imported_hash ON Attribute(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_attribute_update ON Attribute("update") WHERE "update" = 1;
CREATE INDEX IF NOT EXISTS ix_attribute_xt_id ON Attribute(XT_Attrib_ID);

CREATE TABLE IF NOT EXISTS Attrib_Artikel (
    ID            INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_Attrib_ID  INTEGER,
    XT_Artikel_ID INTEGER,
    Attribute_ID  INTEGER NOT NULL,
    Artikel_ID    INTEGER NOT NULL,
    Atrribvalue   TEXT,
    "update"      INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    FOREIGN KEY (Attribute_ID) REFERENCES Attribute(ID) ON DELETE CASCADE,
    FOREIGN KEY (Artikel_ID)   REFERENCES Artikel(ID)   ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_attrib_artikel_unique ON Attrib_Artikel(Attribute_ID, Artikel_ID);
CREATE INDEX IF NOT EXISTS ix_attrib_artikel_artikel ON Attrib_Artikel(Artikel_ID);
CREATE INDEX IF NOT EXISTS ix_attrib_artikel_attribute ON Attrib_Artikel(Attribute_ID);
CREATE INDEX IF NOT EXISTS ix_attrib_artikel_update ON Attrib_Artikel("update") WHERE "update" = 1;

CREATE TABLE IF NOT EXISTS category (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    Parent INTEGER,
    afsid INTEGER,
    afsparent INTEGER,
    xtid INTEGER,
    name TEXT,
    online INTEGER DEFAULT 0,
    picture TEXT,
    picture_id INTEGER,
    picture_big TEXT,
    picture_big_id INTEGER,
    description TEXT,
    meta_title TEXT,
    meta_description TEXT,
    "update"    INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    last_imported_hash TEXT,
    last_seen_hash TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_category_afsid ON category(afsid);
CREATE INDEX IF NOT EXISTS ix_category_parent ON category(Parent);
CREATE INDEX IF NOT EXISTS ix_category_afsparent ON category(afsparent);
CREATE INDEX IF NOT EXISTS ix_category_imported_hash ON category(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_category_update ON category("update") WHERE "update" = 1;
CREATE INDEX IF NOT EXISTS ix_category_xtid ON category(xtid);
CREATE INDEX IF NOT EXISTS ix_category_online ON category(online);

CREATE TABLE IF NOT EXISTS media (
    media_id   INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name  TEXT NOT NULL,
    stored_file TEXT,
    stored_path TEXT,
    mime        TEXT,
    file_size   INTEGER,
    hash        TEXT NOT NULL,
    kind        TEXT NOT NULL,
    status      INTEGER NOT NULL DEFAULT 1 CHECK (status IN (0,1)),
    change      INTEGER NOT NULL DEFAULT 0 CHECK (change IN (0,1)),
    upload      INTEGER NOT NULL DEFAULT 0 CHECK (upload IN (0,1)),
    stored_at   TEXT,
    updated_at  TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_media_hash_kind ON media(hash, kind);
CREATE INDEX IF NOT EXISTS ix_media_file_name ON media(file_name);
CREATE INDEX IF NOT EXISTS ix_media_upload ON media(upload) WHERE upload = 1;

CREATE TABLE IF NOT EXISTS media_relation (
    relation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_name   TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id   TEXT NOT NULL,
    position    INTEGER NOT NULL DEFAULT 0,
    status      INTEGER NOT NULL DEFAULT 1 CHECK (status IN (0,1)),
    change      INTEGER NOT NULL DEFAULT 0 CHECK (change IN (0,1)),
    updated_at  TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_media_relation_unique ON media_relation(file_name, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS ix_media_relation_entity ON media_relation(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS ix_media_relation_status ON media_relation(status);
