-- Migration: Add poem_book, poem, poem_history tables
-- Date: 2026-07-10

CREATE TABLE IF NOT EXISTS poem_book (
    id INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    sortOrder INT NOT NULL DEFAULT 0,
    isPublished BOOLEAN NOT NULL DEFAULT 0,
    publishedAt DATETIME NULL,
    isDeleted BOOLEAN NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS poem (
    id INT NOT NULL AUTO_INCREMENT,
    bookId INT NULL,
    originalPoemId INT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL DEFAULT 'Eero Laine',
    content MEDIUMTEXT NOT NULL,
    sortOrder INT NOT NULL DEFAULT 0,
    writtenDate DATE NULL,
    isPublished BOOLEAN NOT NULL DEFAULT 0,
    publishedAt DATETIME NULL,
    isDeleted BOOLEAN NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_poem_bookId (bookId),
    INDEX idx_poem_originalPoemId (originalPoemId),
    CONSTRAINT fk_poem_book FOREIGN KEY (bookId) REFERENCES poem_book(id) ON DELETE SET NULL,
    CONSTRAINT fk_poem_original FOREIGN KEY (originalPoemId) REFERENCES poem(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS poem_history (
    id INT NOT NULL AUTO_INCREMENT,
    poemId INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    writtenDate DATE NULL,
    snapshotAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_poem_history_poemId (poemId),
    CONSTRAINT fk_poem_history_poem FOREIGN KEY (poemId) REFERENCES poem(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS poem_tag (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    color VARCHAR(16) NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_poem_tag_name (name)
);

CREATE TABLE IF NOT EXISTS poem_tag_link (
    poemId INT NOT NULL,
    tagId INT NOT NULL,
    PRIMARY KEY (poemId, tagId),
    INDEX idx_poem_tag_link_tagId (tagId),
    CONSTRAINT fk_poem_tag_link_poem FOREIGN KEY (poemId) REFERENCES poem(id) ON DELETE CASCADE,
    CONSTRAINT fk_poem_tag_link_tag FOREIGN KEY (tagId) REFERENCES poem_tag(id) ON DELETE CASCADE
);
