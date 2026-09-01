-- Migration: Expansion sub-releases (batch releases within an expansion)
-- Date: 2026-09-01
-- Adds releaseDate to isBack_expansion, and a new isBack_subRelease table
-- (linked to isBack_expansion) plus isBack_subExpansionCard to assign
-- individual cards to a sub-release, so cards can be released in batches
-- before the whole expansion is released.

ALTER TABLE isBack_expansion
    ADD COLUMN releaseDate DATE NULL DEFAULT NULL AFTER lastCardId;

CREATE TABLE IF NOT EXISTS isBack_subRelease (
    id                         INT          NOT NULL AUTO_INCREMENT,
    expansionId                INT          NOT NULL,
    name                       VARCHAR(100) NULL,
    releaseDate                DATE         NULL     DEFAULT NULL,
    isReleased                 TINYINT(1)   NOT NULL DEFAULT 0,
    showExpansionInCardGallery  TINYINT(1)   NOT NULL DEFAULT 0,
    showInDeckBuilder          TINYINT(1)   NULL     DEFAULT NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_isBack_subRelease_expansionId (expansionId),
    CONSTRAINT fk_isBack_subRelease_expansion
        FOREIGN KEY (expansionId) REFERENCES isBack_expansion(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS isBack_subExpansionCard (
    id           INT NOT NULL AUTO_INCREMENT,
    subReleaseId INT NOT NULL,
    cardId       INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_isBack_subExpansionCard (subReleaseId, cardId),
    INDEX idx_isBack_subExpansionCard_cardId (cardId),
    CONSTRAINT fk_isBack_subExpansionCard_subRelease
        FOREIGN KEY (subReleaseId) REFERENCES isBack_subRelease(id) ON DELETE CASCADE,
    CONSTRAINT fk_isBack_subExpansionCard_card
        FOREIGN KEY (cardId) REFERENCES isBack_card(id) ON DELETE CASCADE
);
