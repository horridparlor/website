-- Migration: Add backup table for server-stored backups (e.g. Poem Center)
-- Date: 2026-09-14
-- Generic table so other features can reuse it later — backupType is currently
-- always 'poemsBackup'.

CREATE TABLE IF NOT EXISTS backup (
    id INT NOT NULL AUTO_INCREMENT,
    backupType VARCHAR(32) NOT NULL,
    path VARCHAR(500) NOT NULL,
    isValid BOOLEAN NOT NULL DEFAULT 1,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_backup_type (backupType)
);
