<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include(__DIR__ . "/gameHelper.php");


function createMatch(Database $database, array $waitingEntry, array $joiningUser, int $joiningDeckId): array
{
    $p1DeckIds = getDeckCardIds($database, $waitingEntry['deckId']);
    $p2DeckIds = getDeckCardIds($database, $joiningDeckId);

    // Create match record
    $database->query(
        <<<SQL
            INSERT INTO isBack_match (player1Id, player2Id, player1DeckId, player2DeckId, format)
            VALUES (:p1, :p2, :d1, :d2, :format)
        SQL,
        [
            'p1'     => ['value' => $waitingEntry['userId'], 'type' => \PDO::PARAM_INT],
            'p2'     => ['value' => $joiningUser['userId'], 'type' => \PDO::PARAM_INT],
            'd1'     => ['value' => $waitingEntry['deckId'], 'type' => \PDO::PARAM_INT],
            'd2'     => ['value' => $joiningDeckId, 'type' => \PDO::PARAM_INT],
            'format' => ['value' => $waitingEntry['format'], 'type' => \PDO::PARAM_STR],
        ]
    );
    $matchId = $database->getInsertId();

    $stateJson = buildInitialGameState(
        ['userId' => $waitingEntry['userId'], 'username' => $waitingEntry['username']],
        ['userId' => $joiningUser['userId'], 'username' => $joiningUser['username']],
        $p1DeckIds,
        $p2DeckIds
    );

    $database->query(
        'INSERT INTO isBack_gameState (matchId, gameNumber, stateJson) VALUES (:matchId, 1, :stateJson)',
        [
            'matchId'   => ['value' => $matchId, 'type' => \PDO::PARAM_INT],
            'stateJson' => ['value' => $stateJson, 'type' => \PDO::PARAM_STR],
        ]
    );

    // Update waiting entry
    $database->query(
        'UPDATE isBack_matchQueue SET status = :status, matchId = :matchId WHERE id = :id',
        [
            'status'  => ['value' => 'matched', 'type' => \PDO::PARAM_STR],
            'matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT],
            'id'      => ['value' => $waitingEntry['id'], 'type' => \PDO::PARAM_INT],
        ]
    );

    return ['matchId' => $matchId];
}

function cleanStaleEntries(Database $database): void
{
    $database->query(
        "UPDATE isBack_matchQueue
         SET status = 'cancelled'
         WHERE status = 'waiting'
           AND COALESCE(heartbeatAt, createdAt) < NOW() - INTERVAL 1 MINUTE",
        []
    );
}

function cleanOldData(Database $database): void
{
    $database->query(
        "DELETE gs FROM isBack_gameState gs
         JOIN isBack_match m ON m.id = gs.matchId
         WHERE m.createdAt < NOW() - INTERVAL 7 DAY",
        []
    );
    $database->query(
        "DELETE FROM isBack_matchQueue
         WHERE createdAt < NOW() - INTERVAL 7 DAY",
        []
    );
    $database->query(
        "DELETE FROM isBack_match
         WHERE createdAt < NOW() - INTERVAL 7 DAY",
        []
    );
}

function handleHeartbeat(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();
    $database->query(
        "UPDATE isBack_matchQueue SET heartbeatAt = NOW()
         WHERE userId = :userId AND status = 'waiting'",
        ['userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
    );
    return Database::responseSuccess(['ok' => true]);
}

function getQueue(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();
    cleanStaleEntries($database);

    $row = $database->query(
        'SELECT id, deckId, format, type, joinCode, status, matchId FROM isBack_matchQueue WHERE userId = :userId AND status = :status',
        [
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
            'status' => ['value' => 'waiting', 'type' => \PDO::PARAM_STR],
        ]
    );

    if (!$row) {
        // Redirect to an active match if one exists
        $matched = $database->query(
            <<<SQL
                SELECT q.matchId
                FROM isBack_matchQueue q
                JOIN isBack_match m ON m.id = q.matchId
                WHERE q.userId = :userId AND q.status = 'matched' AND m.status = 'active'
                ORDER BY q.createdAt DESC LIMIT 1
            SQL,
            ['userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
        );
        if ($matched) {
            return Database::responseSuccess(['status' => 'matched', 'matchId' => (int)$matched[0]['matchId']]);
        }
        return Database::responseSuccess(['status' => 'none']);
    }

    return Database::responseSuccess([
        'status'   => 'waiting',
        'id'       => (int)$row[0]['id'],
        'format'   => $row[0]['format'],
        'type'     => $row[0]['type'],
        'joinCode' => $row[0]['joinCode'],
    ]);
}

function joinQueue(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();
    cleanStaleEntries($database);
    cleanOldData($database);

    $action   = $database->getStringParam('action', '');
    $deckId   = $database->getIntParam('deckId');
    $format   = $database->getStringParam('format', 'bo1');
    $type     = $database->getStringParam('type', 'public');
    $joinCode = $database->getStringParam('joinCode');

    // Join by code
    if ($action === 'join' || ($joinCode && !$deckId)) {
        $joinCode = $database->getStringParam('joinCode');
        $deckId   = $database->getIntParam('deckId');
        if (!$joinCode) return Database::responseBadRequest('joinCode required');
        if (!$deckId)   return Database::responseBadRequest('deckId required');

        // Verify deck ownership
        $ownsDeck = $database->query(
            'SELECT id FROM isBack_deck WHERE id = :deckId AND userId = :userId',
            [
                'deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
                'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
            ]
        );
        if (!$ownsDeck) return Database::responseBadRequest('Deck not found');

        $waiting = $database->query(
            <<<SQL
                SELECT q.id, q.userId, q.deckId, q.format, u.username
                FROM isBack_matchQueue q
                JOIN user u ON u.id = q.userId
                WHERE q.joinCode = :code AND q.status = 'waiting'
                LIMIT 1
            SQL,
            ['code' => ['value' => strtoupper($joinCode), 'type' => \PDO::PARAM_STR]]
        );
        if (!$waiting) return Database::responseBadRequest('Match code not found or already started');
        if ($waiting[0]['userId'] == $user->getId()) return Database::responseBadRequest('Cannot join your own match');

        $result = createMatch($database, $waiting[0], ['userId' => $user->getId(), 'username' => $user->getUsername()], $deckId);

        // Insert joining player's queue entry as matched
        $database->query(
            'INSERT INTO isBack_matchQueue (userId, deckId, format, type, status, matchId) VALUES (:userId, :deckId, :format, :type, :status, :matchId)',
            [
                'userId'  => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
                'deckId'  => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
                'format'  => ['value' => $waiting[0]['format'], 'type' => \PDO::PARAM_STR],
                'type'    => ['value' => 'private', 'type' => \PDO::PARAM_STR],
                'status'  => ['value' => 'matched', 'type' => \PDO::PARAM_STR],
                'matchId' => ['value' => $result['matchId'], 'type' => \PDO::PARAM_INT],
            ]
        );
        return Database::responseSuccess(['matched' => true, 'matchId' => $result['matchId']]);
    }

    // Standard queue join
    if (!$deckId)   return Database::responseBadRequest('deckId required');
    if (!in_array($format, ['bo1', 'bo3'])) $format = 'bo1';
    if (!in_array($type,   ['public', 'private'])) $type = 'public';

    // Verify deck ownership + total cards = 60
    $ownsDeck = $database->query(
        'SELECT d.id FROM isBack_deck d WHERE d.id = :deckId AND d.userId = :userId',
        [
            'deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );
    if (!$ownsDeck) return Database::responseBadRequest('Deck not found');

    $totRow = $database->query(
        'SELECT COALESCE(SUM(quantity),0) total FROM isBack_deckCard WHERE deckId = :deckId',
        ['deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT]]
    );
    if ((int)$totRow[0]['total'] !== 60) return Database::responseBadRequest('Deck must have exactly 60 cards to queue');

    // Cancel any existing queue entry for this user
    $database->query(
        "UPDATE isBack_matchQueue SET status = 'cancelled' WHERE userId = :userId AND status = 'waiting'",
        ['userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
    );

    if ($type === 'private') {
        // Generate unique 8-char join code
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $exists = $database->query(
                "SELECT id FROM isBack_matchQueue WHERE joinCode = :code AND status = 'waiting'",
                ['code' => ['value' => $code, 'type' => \PDO::PARAM_STR]]
            );
        } while ($exists);

        $database->query(
            'INSERT INTO isBack_matchQueue (userId, deckId, format, type, joinCode, status) VALUES (:userId, :deckId, :format, :type, :code, :status)',
            [
                'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
                'deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
                'format' => ['value' => $format, 'type' => \PDO::PARAM_STR],
                'type'   => ['value' => 'private', 'type' => \PDO::PARAM_STR],
                'code'   => ['value' => $code, 'type' => \PDO::PARAM_STR],
                'status' => ['value' => 'waiting', 'type' => \PDO::PARAM_STR],
            ]
        );
        return Database::responseSuccess(['status' => 'waiting', 'type' => 'private', 'joinCode' => $code]);
    }

    // Public matching — look for an existing waiting opponent
    $waiting = $database->query(
        <<<SQL
            SELECT q.id, q.userId, q.deckId, q.format, u.username
            FROM isBack_matchQueue q
            JOIN user u ON u.id = q.userId
            WHERE q.status = 'waiting'
            AND q.type = 'public'
            AND q.format = :format
            AND q.userId != :userId
            ORDER BY q.createdAt ASC
            LIMIT 1
        SQL,
        [
            'format' => ['value' => $format, 'type' => \PDO::PARAM_STR],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );

    if ($waiting) {
        $result = createMatch($database, $waiting[0], ['userId' => $user->getId(), 'username' => $user->getUsername()], $deckId);
        $database->query(
            'INSERT INTO isBack_matchQueue (userId, deckId, format, type, status, matchId) VALUES (:userId, :deckId, :format, :type, :status, :matchId)',
            [
                'userId'  => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
                'deckId'  => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
                'format'  => ['value' => $format, 'type' => \PDO::PARAM_STR],
                'type'    => ['value' => 'public', 'type' => \PDO::PARAM_STR],
                'status'  => ['value' => 'matched', 'type' => \PDO::PARAM_STR],
                'matchId' => ['value' => $result['matchId'], 'type' => \PDO::PARAM_INT],
            ]
        );
        return Database::responseSuccess(['matched' => true, 'matchId' => $result['matchId']]);
    }

    // No match found — enter queue
    $database->query(
        'INSERT INTO isBack_matchQueue (userId, deckId, format, type, status) VALUES (:userId, :deckId, :format, :type, :status)',
        [
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
            'deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
            'format' => ['value' => $format, 'type' => \PDO::PARAM_STR],
            'type'   => ['value' => 'public', 'type' => \PDO::PARAM_STR],
            'status' => ['value' => 'waiting', 'type' => \PDO::PARAM_STR],
        ]
    );
    return Database::responseSuccess(['status' => 'waiting', 'type' => 'public']);
}

function leaveQueue(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $database->query(
        "UPDATE isBack_matchQueue SET status = 'cancelled' WHERE userId = :userId AND status = 'waiting'",
        ['userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
    );
    return Database::responseSuccess(['left' => true]);
}

function routeMatchQueue(Database $database): string
{
    $action = $database->getStringParam('action', '');
    if ($action === 'heartbeat') return handleHeartbeat($database);
    return joinQueue($database);
}

$database = new Database();
$database->handleRequest('getQueue', 'routeMatchQueue', null, 'leaveQueue');