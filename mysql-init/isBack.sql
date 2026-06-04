CREATE TABLE isBack_cardType (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_isBack_cardType_name (name)
);

CREATE TABLE isBack_keyword (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    displayName VARCHAR(128) NOT NULL,
    description TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_isBack_keyword_name (name),
    INDEX idx_isBack_keyword_name (name)
);

CREATE TABLE isBack_card (
    id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    cardTypeId INT NOT NULL,
    power INT NOT NULL,

    keywordId INT NULL,
    keyword2Id INT NULL,
    keyword3Id INT NULL,

    reminderVisible BOOLEAN NULL,
    reminder2Visible BOOLEAN NULL,
    reminder3Visible BOOLEAN NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    CONSTRAINT fk_isBack_card_cardType
        FOREIGN KEY (cardTypeId) REFERENCES isBack_cardType(id),

    CONSTRAINT fk_isBack_card_keyword1
        FOREIGN KEY (keywordId) REFERENCES isBack_keyword(id),

    CONSTRAINT fk_isBack_card_keyword2
        FOREIGN KEY (keyword2Id) REFERENCES isBack_keyword(id),

    CONSTRAINT fk_isBack_card_keyword3
        FOREIGN KEY (keyword3Id) REFERENCES isBack_keyword(id)
);

INSERT INTO isBack_cardType (id, name) VALUES
(1, 'rock'),
(2, 'paper'),
(3, 'scissors')
ON DUPLICATE KEY UPDATE name = VALUES(name);
