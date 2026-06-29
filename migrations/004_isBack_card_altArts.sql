-- Migration: Add altArts to isBack_card and artVersion to isBack_deckCard
-- Date: 2026-06-29

ALTER TABLE isBack_card
    ADD COLUMN altArts INT NULL DEFAULT NULL AFTER power;

ALTER TABLE isBack_deckCard
    ADD COLUMN artVersion INT NOT NULL DEFAULT 1 AFTER quantity;