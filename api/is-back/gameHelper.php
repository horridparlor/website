<?php

// Shared game helpers — included by matchQueue.php and gameAction.php

function getDeckCardIds(\system\Database $database, int $deckId): array
{
    $rows = $database->query(
        'SELECT cardId, quantity, artVersion FROM isBack_deckCard WHERE deckId = :deckId',
        ['deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT]]
    );
    $ids = [];
    $artVersionMap = [];
    foreach ($rows as $row) {
        $cardId = (int)$row['cardId'];
        $av = (int)($row['artVersion'] ?? 1);
        if ($av < 1) $av = 1;
        $artVersionMap[$cardId] = $av;
        for ($i = 0; $i < (int)$row['quantity']; $i++) {
            $ids[] = $cardId;
        }
    }
    return ['ids' => $ids, 'artVersionMap' => $artVersionMap];
}

function buildInitialGameState(array $p1, array $p2, array $p1DeckIds, array $p2DeckIds, array $p1ArtVersionMap = [], array $p2ArtVersionMap = []): string
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
                'artVersionMap'       => $p1ArtVersionMap,
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
                'artVersionMap'       => $p2ArtVersionMap,
            ],
        ],
        'pendingEffects' => [],
        'pendingEffect'  => null,
        'revealedPrizeBottom' => [null, null],
        'naturalSelectionAcks' => [0, 0],
        'lastCommunismUsed' => null,
        'lastCommunismUser' => null,
        'communismAcks' => [0, 0],
        'lastMagicPotionRoll' => null,
        'lastFacismDestroy' => null,
        'discardAnims' => [],
        'discardAnimAcks' => [0, 0],
        'cardPlayAnims' => [],
        'cardPlayAnimAcks' => [0, 0],
        'rpsChoices'    => [null, null],
        'rpsResult'     => null,
        'rpsRound'      => 1,
        'log'           => ['Match started! Play Rock Paper Scissors to decide who goes first.'],
    ];
    return json_encode($state);
}

function insertNextGame(\system\Database $database, int $matchId, array $deckRows, int $gameNumber): void
{
    $p1Result = getDeckCardIds($database, (int)$deckRows['player1DeckId']);
    $p2Result = getDeckCardIds($database, (int)$deckRows['player2DeckId']);

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
        $p1Result['ids'],
        $p2Result['ids'],
        $p1Result['artVersionMap'],
        $p2Result['artVersionMap']
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