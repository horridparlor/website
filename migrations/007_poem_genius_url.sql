-- Migration: Add geniusUrl to poem
-- Date: 2026-07-19

ALTER TABLE poem
    ADD COLUMN geniusUrl VARCHAR(500) NULL AFTER writtenDate;intro 2 poems