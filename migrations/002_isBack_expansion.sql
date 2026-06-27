-- Migration: Add isBack_expansion table
-- Date: 2026-06-27
-- Replaces the .expansions flat-file approach with a database-managed table.

CREATE TABLE IF NOT EXISTS isBack_expansion (
    id                         INT          NOT NULL AUTO_INCREMENT,
    name                       VARCHAR(100) NULL,
    firstCardId                INT          NOT NULL,
    lastCardId                 INT          NOT NULL,
    isReleased                 TINYINT(1)   NOT NULL DEFAULT 0,
    showExpansionInCardGallery  TINYINT(1)   NOT NULL DEFAULT 0,
    showInDeckBuilder          TINYINT(1)   NULL     DEFAULT NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_isBack_expansion_range (firstCardId, lastCardId)
);