-- Migration: Add poem_language table and poem.languageId
-- Migration: Add poem_language table and poem.languageId

-- Date: 2026-07-19

CREATE TABLE IF NOT EXISTS poem_language (
                                             id INT NOT NULL AUTO_INCREMENT,
                                             code VARCHAR(8) NOT NULL,
    name VARCHAR(64) NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_poem_language_code (code)
    );

INSERT INTO poem_language (code, name) VALUES ('fi', 'Finnish'), ('en', 'English');

ALTER TABLE poem
    ADD COLUMN languageId INT NULL AFTER bookId,
    ADD INDEX idx_poem_languageId (languageId),
    ADD CONSTRAINT fk_poem_language FOREIGN KEY (languageId) REFERENCES poem_language(id) ON DELETE SET NULL;
-- Date: 2026-07-19

CREATE TABLE IF NOT EXISTS poem_language (
    id INT NOT NULL AUTO_INCREMENT,
    code VARCHAR(8) NOT NULL,
    name VARCHAR(64) NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_poem_language_code (code)
);

INSERT INTO poem_language (code, name) VALUES ('fi', 'Finnish'), ('en', 'English');

ALTER TABLE poem
    ADD COLUMN languageId INT NULL AFTER bookId,
    ADD INDEX idx_poem_languageId (languageId),
    ADD CONSTRAINT fk_poem_language FOREIGN KEY (languageId) REFERENCES poem_language(id) ON DELETE SET NULL;