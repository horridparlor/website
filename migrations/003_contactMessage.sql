-- Migration: Add contactMessage table
-- Date: 2026-06-27
-- Stores contact form submissions from the Keikkatarjoukset section.

CREATE TABLE IF NOT EXISTS contactMessage (
    id        INT          NOT NULL AUTO_INCREMENT,
    email     VARCHAR(255) NOT NULL,
    message   TEXT         NOT NULL,
    sendDate  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    senderIp  VARCHAR(45)  NOT NULL,
    isRead    TINYINT(1)   NOT NULL DEFAULT 0,
    isSpam    TINYINT(1)   NOT NULL DEFAULT 0,
    isStarred TINYINT(1)   NOT NULL DEFAULT 0,
    isDeleted TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    INDEX idx_contactMessage_sendDate (sendDate),
    INDEX idx_contactMessage_senderIp_sendDate (senderIp, sendDate)
);