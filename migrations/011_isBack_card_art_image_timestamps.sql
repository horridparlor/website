-- Migration: Add cardArtUpdatedAt and cardImageUpdatedAt to isBack_card
-- Date: 2026-09-02
-- Used to cache-bust the card art/image URLs when an admin uploads a new file.

ALTER TABLE isBack_card
    ADD COLUMN cardArtUpdatedAt DATETIME NULL DEFAULT NULL,
    ADD COLUMN cardImageUpdatedAt DATETIME NULL DEFAULT NULL;
