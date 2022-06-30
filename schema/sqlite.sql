CREATE TABLE flysystem_files
(
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    path        TEXT    NOT NULL UNIQUE,
    type        TEXT    NOT NULL,
    contents    BLOB,
    size        INTEGER NOT NULL DEFAULT 0,
    level       INTEGER NOT NULL,
    mimetype    TEXT,
    visibility  TEXT    NOT NULL DEFAULT 'public',
    "timestamp" INTEGER NOT NULL DEFAULT 0
);
