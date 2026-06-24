-- Migration 001: Add heartbeatAt column to isBack_matchQueue
-- Run: mysql -u <user> -p <db> < mysql-migrations/001_matchqueue_heartbeat.sql

ALTER TABLE isBack_matchQueue
    ADD COLUMN heartbeatAt DATETIME NULL DEFAULT NULL;