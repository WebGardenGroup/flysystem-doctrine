CREATE TABLE flysystem_files
(
    id          BIGINT                     NOT NULL AUTO_INCREMENT,
    path        VARCHAR(255)               NOT NULL,
    type        ENUM ('file', 'dir')       NOT NULL,
    contents    LONGBLOB,
    size        INTEGER                    NOT NULL DEFAULT 0,
    level       INTEGER                    NOT NULL,
    mimetype    VARCHAR(127),
    visibility  ENUM ('public', 'private') NOT NULL DEFAULT 'public',
    "timestamp" INTEGER                    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY path_unique (path)
);
