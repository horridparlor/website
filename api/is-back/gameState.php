<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getGameState(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $matchId      = $database->getIntParam('matchId');
    $clientVersion = $database->getIntParam('version', -1);

    if (!$matchId) return Database::responseBadRequest('matchId required');

    // Verify user is in this match
    $match = $database->query(
        'SELECT id, player1Id, player2Id, player1DeckId, player2DeckId, format, status, winnerId, player1Wins, player2Wins FROM isBack_match WHERE id = :matchId',
        ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$match) return Database::responseNotFound();

    $match = $match[0];
    $userId = $user->getId();
    if ($match['player1Id'] != $userId && $match['player2Id'] != $userId) {
        return Database::responseForbidden(['error' => 'You are not in this match']);
    }

    $playerIndex = $match['player1Id'] == $userId ? 0 : 1;
    $opponentIndex = 1 - $playerIndex;

    // Get current active game state
    $gsRow = $database->query(
        "SELECT id, gameNumber, stateJson, status, winnerId, version FROM isBack_gameState WHERE matchId = :matchId AND status = 'active' ORDER BY gameNumber DESC LIMIT 1",
        ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
    );

    // If no active game, get latest completed
    if (!$gsRow) {
        $gsRow = $database->query(
            "SELECT id, gameNumber, stateJson, status, winnerId, version FROM isBack_gameState WHERE matchId = :matchId ORDER BY gameNumber DESC LIMIT 1",
            ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
        );
    }
    if (!$gsRow) return Database::responseNotFound();

    $gs = $gsRow[0];
    $version = (int)$gs['version'];

    // Return 304-like if version unchanged
    if ($clientVersion >= 0 && $clientVersion === $version) {
        return Database::responseSuccess(['unchanged' => true, 'version' => $version]);
    }

    $state = json_decode($gs['stateJson'], true);

    if (!isset($state['pendingEffects']) || !is_array($state['pendingEffects'])) {
        $state['pendingEffects'] = [];
    }
    if (isset($state['pendingEffect']) && is_array($state['pendingEffect']) && empty($state['pendingEffects'])) {
        $state['pendingEffects'][] = $state['pendingEffect'];
    }
    $state['pendingEffect'] = $state['pendingEffects'][0] ?? null;
    if (!isset($state['revealedPrizeBottom']) || !is_array($state['revealedPrizeBottom'])) {
        $state['revealedPrizeBottom'] = [null, null];
    }
    if (!isset($state['naturalSelectionAcks']) || !is_array($state['naturalSelectionAcks'])) {
        $state['naturalSelectionAcks'] = [0, 0];
    }
    if (!isset($state['communismAcks']) || !is_array($state['communismAcks'])) {
        $state['communismAcks'] = [0, 0];
    }

    // Redact opponent hidden info
    $state['players'][$opponentIndex]['handIds']  = array_fill(0, count($state['players'][$opponentIndex]['handIds']), null);
    $state['players'][$opponentIndex]['prizeIds'] = array_fill(0, count($state['players'][$opponentIndex]['prizeIds']), null);
    $state['players'][$opponentIndex]['deckIds']  = array_fill(0, count($state['players'][$opponentIndex]['deckIds']), null);

    // Also redact own prize IDs (they're face-down too, player just knows count)
    $state['players'][$playerIndex]['prizeIds'] = array_fill(0, count($state['players'][$playerIndex]['prizeIds']), null);
    $state['players'][$playerIndex]['deckIds']  = array_fill(0, count($state['players'][$playerIndex]['deckIds']), null);

    // Redact opponent's ongoing RPS choice (not yet revealed)
    if (isset($state['rpsChoices'])) {
        $state['rpsChoices'][$opponentIndex] = null;
    }

    return Database::responseSuccess([
        'matchId'      => $matchId,
        'gameStateId'  => (int)$gs['id'],
        'gameNumber'   => (int)$gs['gameNumber'],
        'gameStatus'   => $gs['status'],
        'version'      => $version,
        'playerIndex'  => $playerIndex,
        'match'        => [
            'format'      => $match['format'],
            'status'      => $match['status'],
            'winnerId'    => $match['winnerId'],
            'player1Wins' => (int)$match['player1Wins'],
            'player2Wins' => (int)$match['player2Wins'],
            'player1Id'   => (int)$match['player1Id'],
            'player2Id'   => (int)$match['player2Id'],
        ],
        'state'        => $state,
    ]);
}

$database = new Database();
$database->handleRequest('getGameState');