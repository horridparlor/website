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

-- Deck builder
CREATE TABLE IF NOT EXISTS isBack_deck (
    id INT NOT NULL AUTO_INCREMENT,
    userId INT NOT NULL,
    name VARCHAR(128) NOT NULL DEFAULT 'My Deck',
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_isBack_deck_userId (userId),
    CONSTRAINT fk_isBack_deck_user FOREIGN KEY (userId) REFERENCES user(id)
);

CREATE TABLE IF NOT EXISTS isBack_deckCard (
    id INT NOT NULL AUTO_INCREMENT,
    deckId INT NOT NULL,
    cardId INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_isBack_deckCard (deckId, cardId),
    CONSTRAINT fk_isBack_deckCard_deck FOREIGN KEY (deckId) REFERENCES isBack_deck(id) ON DELETE CASCADE,
    CONSTRAINT fk_isBack_deckCard_card FOREIGN KEY (cardId) REFERENCES isBack_card(id)
);

-- Match queue
CREATE TABLE IF NOT EXISTS isBack_matchQueue (
    id INT NOT NULL AUTO_INCREMENT,
    userId INT NOT NULL,
    deckId INT NOT NULL,
    format ENUM('bo1', 'bo3') NOT NULL DEFAULT 'bo1',
    type ENUM('public', 'private') NOT NULL DEFAULT 'public',
    joinCode VARCHAR(8) NULL,
    status ENUM('waiting', 'matched', 'cancelled') NOT NULL DEFAULT 'waiting',
    matchId INT NULL,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_isBack_queue_status (status),
    CONSTRAINT fk_isBack_queue_user FOREIGN KEY (userId) REFERENCES user(id),
    CONSTRAINT fk_isBack_queue_deck FOREIGN KEY (deckId) REFERENCES isBack_deck(id)
);

-- Match
CREATE TABLE IF NOT EXISTS isBack_match (
    id INT NOT NULL AUTO_INCREMENT,
    player1Id INT NOT NULL,
    player2Id INT NOT NULL,
    player1DeckId INT NOT NULL,
    player2DeckId INT NOT NULL,
    format ENUM('bo1', 'bo3') NOT NULL DEFAULT 'bo1',
    status ENUM('active', 'completed', 'abandoned') NOT NULL DEFAULT 'active',
    winnerId INT NULL,
    player1Wins INT NOT NULL DEFAULT 0,
    player2Wins INT NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_isBack_match_p1 FOREIGN KEY (player1Id) REFERENCES user(id),
    CONSTRAINT fk_isBack_match_p2 FOREIGN KEY (player2Id) REFERENCES user(id)
);

-- Game state (one per game/set within a match)
CREATE TABLE IF NOT EXISTS isBack_gameState (
    id INT NOT NULL AUTO_INCREMENT,
    matchId INT NOT NULL,
    gameNumber INT NOT NULL DEFAULT 1,
    stateJson LONGTEXT NOT NULL,
    status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
    winnerId INT NULL,
    version INT NOT NULL DEFAULT 0,
    createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_isBack_gameState_matchId (matchId),
    CONSTRAINT fk_isBack_gameState_match FOREIGN KEY (matchId) REFERENCES isBack_match(id)
);
