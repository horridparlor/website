-- Migration: Add "gun" card type
-- Date: 2026-09-02
-- Adds a 4th card type, "gun", after rock/paper/scissors.
-- Gun beats rock, paper, and scissors; nothing beats gun.

INSERT INTO isBack_cardType (id, name) VALUES
(4, 'gun')
ON DUPLICATE KEY UPDATE name = VALUES(name);
