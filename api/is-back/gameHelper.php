<?php

// Shared game helpers — included by matchQueue.php and gameAction.php

function getDeckCardIds(\system\Database $database, int $deckId): array
{
    $rows = $database->query(
        'SELECT cardId, quantity FROM isBack_deckCard WHERE deckId = :deckId',
        ['deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT]]
    );
    $ids = [];
    foreach ($rows as $row) {
        for ($i = 0; $i < (int)$row['quantity']; $i++) {
            $ids[] = (int)$row['cardId'];
        }
    }
    return $ids;
}

function buildInitialGameState(array $p1, array $p2, array $p1DeckIds, array $p2DeckIds): string
{
    shuffle($p1DeckIds);
    shuffle($p2DeckIds);

    $p1Prizes = array_splice($p1DeckIds, 0, 5);
    $p2Prizes = array_splice($p2DeckIds, 0, 5);

    $state = [
        'phase'                   => 'setup',
        'roundNumber'             => 0,
        'turn'                    => 0,
        'firstPlayer'             => -1,
        'naturalSelection'        => false,
        'naturalSelectionPlays'   => [0, 0],
        'players' => [
            [
                'userId'              => $p1['userId'],
                'username'            => $p1['username'],
                'ready'               => false,
                'deckIds'             => $p1DeckIds,
                'handIds'             => [],
                'graveyardIds'        => [],
                'purgedIds'           => [],
                'prizeIds'            => $p1Prizes,
                'prizeCount'          => count($p1Prizes),
                'diceRoll'            => null,
                'diceBonus'           => 0,
                'field'               => ['primary' => [], 'left' => [], 'right' => []],
                'startOfRoundUsed'    => false,
                'surrendered'         => false,
                'effectivePowerBonus' => 0,
            ],
            [
                'userId'              => $p2['userId'],
                'username'            => $p2['username'],
                'ready'               => false,
                'deckIds'             => $p2DeckIds,
                'handIds'             => [],
                'graveyardIds'        => [],
                'purgedIds'           => [],
                'prizeIds'            => $p2Prizes,
                'prizeCount'          => count($p2Prizes),
                'diceRoll'            => null,
                'diceBonus'           => 0,
                'field'               => ['primary' => [], 'left' => [], 'right' => []],
                'startOfRoundUsed'    => false,
                'surrendered'         => false,
                'effectivePowerBonus' => 0,
            ],
        ],
        'pendingEffect' => null,
        'rpsChoices'    => [null, null],
        'rpsResult'     => null,
        'rpsRound'      => 1,
        'log'           => ['Match started! Play Rock Paper Scissors to decide who goes first.'],
    ];
    return json_encode($state);
}

function insertNextGame(\system\Database $database, int $matchId, array $deckRows, int $gameNumber): void
{
    $p1DeckIds = getDeckCardIds($database, (int)$deckRows['player1DeckId']);
    $p2DeckIds = getDeckCardIds($database, (int)$deckRows['player2DeckId']);

    // Get usernames
    $users = $database->query(
        'SELECT u.id, TRIM(CONCAT(u.firstname, " ", u.lastname)) AS username
         FROM user u WHERE u.id IN (:p1, :p2)',
        [
            'p1' => ['value' => (int)$deckRows['player1Id'], 'type' => \PDO::PARAM_INT],
            'p2' => ['value' => (int)$deckRows['player2Id'], 'type' => \PDO::PARAM_INT],
        ]
    );
    $userMap = [];
    foreach ($users as $u) $userMap[$u['id']] = $u['username'];

    $stateJson = buildInitialGameState(
        ['userId' => (int)$deckRows['player1Id'], 'username' => $userMap[$deckRows['player1Id']] ?? 'Player 1'],
        ['userId' => (int)$deckRows['player2Id'], 'username' => $userMap[$deckRows['player2Id']] ?? 'Player 2'],
        $p1DeckIds,
        $p2DeckIds
    );

    $database->query(
        'INSERT INTO isBack_gameState (matchId, gameNumber, stateJson) VALUES (:matchId, :gameNum, :stateJson)',
        [
            'matchId'   => ['value' => $matchId,    'type' => \PDO::PARAM_INT],
            'gameNum'   => ['value' => $gameNumber,  'type' => \PDO::PARAM_INT],
            'stateJson' => ['value' => $stateJson,   'type' => \PDO::PARAM_STR],
        ]
    );
}