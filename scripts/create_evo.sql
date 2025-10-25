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

CREATE TABLE IF NOT EXISTS Bilder (
    ID        INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_ID     INTEGER,
    Bildname  TEXT NOT NULL,
    md5       TEXT,
    "update"  INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    uploaded  INTEGER NOT NULL DEFAULT 0 CHECK (uploaded IN (0,1)),
    last_imported_hash TEXT,
    last_seen_hash TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_bilder_bildname ON Bilder(Bildname);
CREATE UNIQUE INDEX IF NOT EXISTS ux_bilder_md5 ON Bilder(md5) WHERE md5 IS NOT NULL;
CREATE INDEX IF NOT EXISTS ix_bilder_imported_hash ON Bilder(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_bilder_update ON Bilder("update") WHERE "update" = 1;
CREATE INDEX IF NOT EXISTS ix_bilder_xt_id ON Bilder(XT_ID);

CREATE TABLE IF NOT EXISTS Artikel_Bilder (
    ID           INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_ARTIKEL_ID INTEGER,
    XT_Bild_ID    INTEGER,
    Artikel_ID    INTEGER NOT NULL,
    Bild_ID       INTEGER NOT NULL,
    "update"      INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    FOREIGN KEY (Artikel_ID) REFERENCES Artikel(ID) ON DELETE CASCADE,
    FOREIGN KEY (Bild_ID)    REFERENCES Bilder(ID)  ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artikel_bilder_unique ON Artikel_Bilder(Artikel_ID, Bild_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_bilder_artikel ON Artikel_Bilder(Artikel_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_bilder_bild ON Artikel_Bilder(Bild_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_bilder_update ON Artikel_Bilder("update") WHERE "update" = 1;

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

CREATE TABLE IF NOT EXISTS Dokumente (
    ID        INTEGER PRIMARY KEY AUTOINCREMENT,
    XT_ID     INTEGER,
    Titel     TEXT NOT NULL,
    Dateiname TEXT,
    Art       INTEGER,
    md5       TEXT,
    "update"  INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    uploaded  INTEGER NOT NULL DEFAULT 0 CHECK (uploaded IN (0,1)),
    last_imported_hash TEXT,
    last_seen_hash TEXT
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_dokumente_titel ON Dokumente(Titel);
CREATE INDEX IF NOT EXISTS ix_dokumente_imported_hash ON Dokumente(last_imported_hash);
CREATE INDEX IF NOT EXISTS ix_dokumente_update ON Dokumente("update") WHERE "update" = 1;
CREATE INDEX IF NOT EXISTS ix_dokumente_xt_id ON Dokumente(XT_ID);

CREATE TABLE IF NOT EXISTS Artikel_Dokumente (
    ID          INTEGER PRIMARY KEY AUTOINCREMENT,
    Artikel_ID  INTEGER NOT NULL,
    Dokument_ID INTEGER NOT NULL,
    XT_ARTIKEL_ID INTEGER,
    XT_Dokument_ID INTEGER,
    "update"    INTEGER NOT NULL DEFAULT 0 CHECK ("update" IN (0,1)),
    FOREIGN KEY (Artikel_ID)  REFERENCES Artikel(ID)  ON DELETE CASCADE,
    FOREIGN KEY (Dokument_ID) REFERENCES Dokumente(ID) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_artikel_dokument_unique ON Artikel_Dokumente(Artikel_ID, Dokument_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_dokumente_artikel ON Artikel_Dokumente(Artikel_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_dokumente_dokument ON Artikel_Dokumente(Dokument_ID);
CREATE INDEX IF NOT EXISTS ix_artikel_dokumente_update ON Artikel_Dokumente("update") WHERE "update" = 1;

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
