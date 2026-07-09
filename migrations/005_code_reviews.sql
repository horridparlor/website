-- Migration: Add code_reviews, code_review_findings, code_review_comments tables
-- Date: 2026-07-09

CREATE TABLE IF NOT EXISTS code_reviews (
    id INT NOT NULL AUTO_INCREMENT,
    userId INT NOT NULL,
    identifier VARCHAR(64) NULL,
    title VARCHAR(255) NOT NULL,
    rawMarkdown MEDIUMTEXT NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    isDeleted BOOLEAN NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_code_reviews_userId (userId),
    CONSTRAINT fk_code_reviews_user FOREIGN KEY (userId) REFERENCES user(id)
);

CREATE TABLE IF NOT EXISTS code_review_findings (
    id INT NOT NULL AUTO_INCREMENT,
    codeReviewId INT NOT NULL,
    sortOrder INT NOT NULL DEFAULT 0,
    sectionTitle VARCHAR(255) NULL,
    severity VARCHAR(32) NULL,
    number VARCHAR(16) NULL,
    title VARCHAR(500) NOT NULL,
    filePath VARCHAR(500) NULL,
    bodyMarkdown MEDIUMTEXT NOT NULL,
    isCheckable BOOLEAN NOT NULL DEFAULT 1,
    checked BOOLEAN NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_code_review_findings_codeReviewId (codeReviewId),
    CONSTRAINT fk_code_review_findings_review FOREIGN KEY (codeReviewId) REFERENCES code_reviews(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS code_review_comments (
    id INT NOT NULL AUTO_INCREMENT,
    findingId INT NOT NULL,
    body TEXT NOT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_code_review_comments_findingId (findingId),
    CONSTRAINT fk_code_review_comments_finding FOREIGN KEY (findingId) REFERENCES code_review_findings(id) ON DELETE CASCADE
);
