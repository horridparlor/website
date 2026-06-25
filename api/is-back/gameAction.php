<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include(__DIR__ . "/gameHelper.php");

// Card type that beats another: beater => loser
// Rock beats Scissors, Paper beats Rock, Scissors beats Paper
const BEATS = ['rock' => 'scissors', 'paper' => 'rock', 'scissors' => 'paper'];
// Weak type of each card: the type that BEATS it (what each card is weak against)
// Rock is weak to Paper, Paper is weak to Scissors, Scissors is weak to Rock
const WEAK_TYPE = ['rock' => 'paper', 'paper' => 'scissors', 'scissors' => 'rock'];

class GameEngine
{
    private Database $db;
    private array $cardCache = [];

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function getCard(int $cardId): array
    {
        if (isset($this->cardCache[$cardId])) return $this->cardCache[$cardId];
        $rows = $this->db->query(
            <<<SQL
                SELECT c.id, c.name, ct.name type, c.power,
                    k1.name kw1, k2.name kw2, k3.name kw3
                FROM isBack_card c
                JOIN isBack_cardType ct ON ct.id = c.cardTypeId
                LEFT JOIN isBack_keyword k1 ON k1.id = c.keywordId
                LEFT JOIN isBack_keyword k2 ON k2.id = c.keyword2Id
                LEFT JOIN isBack_keyword k3 ON k3.id = c.keyword3Id
                WHERE c.id = :id
            SQL,
            ['id' => ['value' => $cardId, 'type' => \PDO::PARAM_INT]]
        );
        if (!$rows) return [];
        $c = $rows[0];
        $c['keywords'] = array_values(array_filter([$c['kw1'], $c['kw2'], $c['kw3']]));
        $this->cardCache[$cardId] = $c;
        return $c;
    }

    public function hasKeyword(int $cardId, string $kw): bool
    {
        $c = $this->getCard($cardId);
        return in_array(strtolower($kw), array_map('strtolower', $c['keywords']));
    }

    public function getTopCard(array $stack): ?array
    {
        if (empty($stack)) return null;
        $top = end($stack);
        if (!$top || !isset($top['cardId'])) return null;
        $card = $this->getCard((int)$top['cardId']);
        $card['faceDown'] = $top['faceDown'];
        return $card;
    }

    private function drawOneFromDeckOwner(array &$state, int $drawingPlayerIndex, int $deckOwnerIndex): bool
    {
        $drawer = &$state['players'][$drawingPlayerIndex];
        $owner = &$state['players'][$deckOwnerIndex];
        if (empty($owner['deckIds'])) {
            if (empty($owner['graveyardIds'])) return false;
            // Reshuffle selected owner's graveyard into selected owner's deck.
            $owner['deckIds'] = $owner['graveyardIds'];
            shuffle($owner['deckIds']);
            $owner['graveyardIds'] = [];
            $state['log'][] = $owner['username'] . ' reshuffled graveyard into deck.';
        }

        $drawn = array_shift($owner['deckIds']);
        $drawer['handIds'][] = $drawn;
        return true;
    }

    private function hasCommunismOnBottomPrize(array &$state, int $playerIndex): bool
    {
        $p = &$state['players'][$playerIndex];
        if (empty($p['prizeIds'])) return false;
        $bottom = $p['prizeIds'][0] ?? null;
        if (!$bottom) return false;
        return $this->hasKeyword((int)$bottom, 'communism');
    }

    public function drawCards(array &$state, int $playerIndex, int $count, ?int $deckOwnerIndex = null, bool $allowCommunismChoice = true): int
    {
        $drawnCount = 0;

        // Communism replacement effect: for each draw, the player chooses whose deck to draw from.
        if ($allowCommunismChoice && $deckOwnerIndex === null && $count > 0 && $this->hasCommunismOnBottomPrize($state, $playerIndex)) {
            $effects = [];
            for ($i = 0; $i < $count; $i++) {
                $effects[] = ['type' => 'communism_draw', 'playerIndex' => $playerIndex];
            }
            enqueuePendingEffects($state, $effects);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' must choose draw source (Communism).';
            return 0;
        }

        $ownerIndex = $deckOwnerIndex ?? $playerIndex;
        for ($i = 0; $i < $count; $i++) {
            if ($this->drawOneFromDeckOwner($state, $playerIndex, $ownerIndex)) {
                $drawnCount++;
            } else {
                break;
            }
        }
        return $drawnCount;
    }

    public function discardFromHand(array &$state, int $playerIndex, int $cardId): bool
    {
        $p = &$state['players'][$playerIndex];
        $idx = array_search($cardId, $p['handIds']);
        if ($idx === false) return false;
        array_splice($p['handIds'], $idx, 1);
        $p['graveyardIds'][] = $cardId;
        return true;
    }

    public function discardFromField(array &$state, int $playerIndex, string $slot): void
    {
        $p = &$state['players'][$playerIndex];
        foreach (array_reverse($p['field'][$slot]) as $entry) {
            $p['graveyardIds'][] = $entry['cardId'];
        }
        $p['field'][$slot] = [];
    }

    public function removeTopFromStack(array &$state, int $playerIndex, string $slot): void
    {
        $p = &$state['players'][$playerIndex];
        if (empty($p['field'][$slot])) return;
        $top = array_pop($p['field'][$slot]);
        $p['graveyardIds'][] = $top['cardId'];
    }

    public function recalcDemocracy(array &$state, int $playerIndex): void
    {
        $p = &$state['players'][$playerIndex];
        $primaryTop = $this->getTopCard($p['field']['primary']);
        if (!$primaryTop || !$this->hasKeyword($primaryTop['id'], 'democracy')) {
            $p['effectivePowerBonus'] = 0;
            return;
        }
        $bonus = 0;
        foreach (['left', 'right'] as $slot) {
            $sup = $this->getTopCard($p['field'][$slot]);
            if ($sup) $bonus += (int)$sup['power'];
        }
        $p['effectivePowerBonus'] = $bonus;
    }

    public function getEffectivePower(array &$state, int $playerIndex): int
    {
        $p = &$state['players'][$playerIndex];
        $top = $this->getTopCard($p['field']['primary']);
        if (!$top) return 0;
        $base = (int)$top['power'];
        return $base + (int)($p['effectivePowerBonus'] ?? 0);
    }

    public function checkWinRound(array &$state, int $passerId): ?int
    {
        // Returns winner index (0 or 1), or null if no winner yet
        $oppIdx = 1 - $passerId;
        $passerField = $state['players'][$passerId]['field'];
        $oppField    = $state['players'][$oppIdx]['field'];

        $passerTop = $this->getTopCard($passerField['primary']);
        $oppTop    = $this->getTopCard($oppField['primary']);

        if (!$passerTop) return $oppIdx; // no primary = surrender/lose

        if (!$oppTop) return null; // opponent has no card, pass goes through

        // Face-down resolution / Divine interaction
        if ($passerTop['faceDown'] && $oppTop['faceDown']) {
            return null; // reveal sequence needed
        }

        if ($oppTop['faceDown']) {
            if (!$passerTop['faceDown'] && $this->hasKeyword($passerTop['id'], 'divine')) {
                return $passerId; // Divine beats face-down without reveal
            }
            return null; // reveal needed
        }

        if ($passerTop['faceDown']) {
            if (!$oppTop['faceDown'] && $this->hasKeyword($oppTop['id'], 'divine')) {
                return $oppIdx; // Divine beats face-down without reveal
            }
            return null; // reveal needed
        }

        // Both face-up
        $passerType  = strtolower($passerTop['type']);
        $oppType     = strtolower($oppTop['type']);
        $passerPower = $this->getEffectivePower($state, $passerId);
        $oppPower    = $this->getEffectivePower($state, $oppIdx);

        // Check Monarchy on opponent's card: defeats weak type regardless of power
        if ($this->hasKeyword($oppTop['id'], 'monarchy')) {
            if (WEAK_TYPE[$oppType] === $passerType) {
                return $oppIdx;
            }
        }

        if (BEATS[$oppType] === $passerType) return $oppIdx;
        if (BEATS[$passerType] === $oppType) return $passerId;

        // Same type
        if ($passerPower < $oppPower) return $oppIdx;
        if ($passerPower > $oppPower) return $passerId;
        // Same type, same power → passer loses
        return $oppIdx;
    }

    public function applyWhenPlayed(array &$state, int $playerIndex, string $slot, int $cardId, bool $isFaceDown = false): array
    {
        $pendingEffects = [];
        $card = $this->getCard($cardId);
        foreach ($card['keywords'] as $kw) {
            switch (strtolower($kw)) {
                case 'magic-potion':
                    $roll = rand(1, 6);
                    $state['lastMagicPotionRoll'] = [
                        'ts' => nextEventStamp(),
                        'playerIndex' => $playerIndex,
                        'cardId' => $cardId,
                        'roll' => $roll,
                    ];
                    $state['log'][] = $state['players'][$playerIndex]['username'] . ' rolled D6 for Magic Potion: ' . $roll;
                    if ($roll === 1) {
                        $this->removeTopFromStack($state, $playerIndex, $slot);
                        $state['log'][] = 'Magic Potion destroyed the card!';
                    } elseif ($roll === 6) {
                        $this->drawCards($state, $playerIndex, 1);
                        $state['log'][] = 'Magic Potion: drew a card!';
                    }
                    break;
                case 'necromancy':
                    // Handled via pendingEffect — prompt player to optionally play a zombie from grave
                    $zombiesInGrave = array_filter($state['players'][$playerIndex]['graveyardIds'], function($cid) {
                        return $this->hasKeyword($cid, 'zombie');
                    });
                    if (!empty($zombiesInGrave)) {
                        $pendingEffects[] = ['type' => 'necromancy', 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'zombies' => array_values($zombiesInGrave)];
                    }
                    break;
                case 'makkarajarvi':
                    if ($slot === 'primary' && !$isFaceDown) {
                        $pendingEffects[] = ['type' => 'makkarajarvi', 'playerIndex' => $playerIndex, 'cardId' => $cardId];
                    }
                    break;
                case 'sahkotalo':
                    if ($slot === 'primary' && !$isFaceDown) {
                        $pendingEffects[] = ['type' => 'sahkotalo', 'playerIndex' => $playerIndex, 'cardId' => $cardId];
                    }
                    break;
            }
        }
        // Wanderret: when supporting a wanderret
        if ($slot !== 'primary') {
            $primaryTop = $this->getTopCard($state['players'][$playerIndex]['field']['primary']);
            if ($primaryTop && $this->hasKeyword($primaryTop['id'], 'wanderret') && $this->hasKeyword($cardId, 'wanderret')) {
                $this->drawCards($state, $playerIndex, 1);
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' drew a card (Wanderret).';
            }
            // Cougar
            if ($this->hasKeyword($cardId, 'cougar') && $primaryTop) {
                if ((int)$primaryTop['power'] <= 5000) {
                    $this->drawCards($state, $playerIndex, 1);
                    $state['log'][] = $state['players'][$playerIndex]['username'] . ' drew a card (Cougar).';
                    // Cougar-Magnet on primary
                    if ($this->hasKeyword($primaryTop['id'], 'cougar-magnet')) {
                        $this->drawCards($state, $playerIndex, 1);
                        $state['log'][] = $state['players'][$playerIndex]['username'] . ' drew an additional card (Cougar-Magnet).';
                    }
                }
            }
        }
        $this->recalcDemocracy($state, $playerIndex);
        return $pendingEffects;
    }

    public function applyWhenEvolves(array &$state, int $playerIndex, string $slot, int $newCardId, int $oldCardId): void
    {
        // Elder-Slime: if evolved from a non-elder Slime card, opponent discards 2 (choice if hand > 2).
        $newCard = $this->getCard($newCardId);
        if (in_array('elder-slime', array_map('strtolower', $newCard['keywords']))) {
            $oldIsSlime = $this->hasKeyword($oldCardId, 'slime');
            $oldIsElderSlime = $this->hasKeyword($oldCardId, 'elder-slime');
            if ($oldIsSlime && !$oldIsElderSlime) {
                $oppIdx = 1 - $playerIndex;
                $oppHandCount = count($state['players'][$oppIdx]['handIds']);
                if ($oppHandCount <= 2) {
                    $discarded = 0;
                    while (!empty($state['players'][$oppIdx]['handIds'])) {
                        $last = array_pop($state['players'][$oppIdx]['handIds']);
                        $state['players'][$oppIdx]['graveyardIds'][] = $last;
                        pushDiscardAnim($state, (int)$last, $oppIdx, 'elder_slime');
                        $discarded++;
                    }
                    $state['log'][] = $state['players'][$oppIdx]['username'] . " discarded $discarded card(s) (Elder-Slime).";
                } else {
                    enqueuePendingEffects($state, [[
                        'type' => 'elder_slime_discard',
                        'playerIndex' => $oppIdx,
                        'discardCount' => 2,
                        'sourcePlayerIndex' => $playerIndex,
                    ]]);
                    $state['log'][] = $state['players'][$oppIdx]['username'] . ' must choose 2 cards to discard (Elder-Slime).';
                }
            }
        }
        $this->recalcDemocracy($state, $playerIndex);
    }

    public function clearField(array &$state, int $playerIndex): void
    {
        foreach (['primary', 'left', 'right'] as $slot) {
            $this->discardFromField($state, $playerIndex, $slot);
        }
    }

    public function startNewRound(array &$state, int $firstPlayer): void
    {
        $state['roundNumber']++;
        $state['phase']       = 'start_of_round';
        $state['firstPlayer'] = $firstPlayer;
        $state['turn']        = $firstPlayer;
        $state['naturalSelection'] = false;
        $state['naturalSelectionPlays'] = [0, 0];

        foreach ($state['players'] as &$p) {
            $p['diceRoll']         = null;
            $p['diceBonus']        = 0;
            $p['startOfRoundUsed'] = false;
            $p['surrendered']      = false;
            foreach (['primary', 'left', 'right'] as $slot) {
                foreach (array_reverse($p['field'][$slot]) as $entry) {
                    $p['graveyardIds'][] = $entry['cardId'];
                }
                $p['field'][$slot] = [];
            }
            $p['effectivePowerBonus'] = 0;
        }
        unset($p);

        $state['log'][] = '--- Round ' . $state['roundNumber'] . ' begins ---';
    }
}

function ensurePendingEffects(array &$state): void
{
    if (!isset($state['pendingEffects']) || !is_array($state['pendingEffects'])) {
        $state['pendingEffects'] = [];
    }

    // Backward-compat: migrate legacy single pending effect into queue.
    if (isset($state['pendingEffect']) && is_array($state['pendingEffect']) && empty($state['pendingEffects'])) {
        $state['pendingEffects'][] = $state['pendingEffect'];
    }

    $state['pendingEffect'] = $state['pendingEffects'][0] ?? null;
}

function enqueuePendingEffects(array &$state, array $effects): void
{
    ensurePendingEffects($state);
    foreach ($effects as $effect) {
        if (is_array($effect)) {
            $state['pendingEffects'][] = $effect;
        }
    }
    $state['pendingEffect'] = $state['pendingEffects'][0] ?? null;
}

function getCurrentPendingEffect(array &$state): ?array
{
    ensurePendingEffects($state);
    return $state['pendingEffects'][0] ?? null;
}

function consumePendingEffect(array &$state): void
{
    ensurePendingEffects($state);
    if (!empty($state['pendingEffects'])) {
        array_shift($state['pendingEffects']);
    }
    $state['pendingEffect'] = $state['pendingEffects'][0] ?? null;
}

function syncRevealedPrizeBottom(array &$state, GameEngine $engine): void
{
    if (!isset($state['revealedPrizeBottom']) || !is_array($state['revealedPrizeBottom'])) {
        $state['revealedPrizeBottom'] = [null, null];
    }

    foreach ([0, 1] as $idx) {
        $bottom = $state['players'][$idx]['prizeIds'][0] ?? null;
        $state['revealedPrizeBottom'][$idx] = ($bottom && $engine->hasKeyword((int)$bottom, 'communism')) ? (int)$bottom : null;
    }
}

function ensureNotificationState(array &$state): void
{
    if (!isset($state['naturalSelectionAcks']) || !is_array($state['naturalSelectionAcks'])) {
        $state['naturalSelectionAcks'] = [0, 0];
    }
    if (!isset($state['communismAcks']) || !is_array($state['communismAcks'])) {
        $state['communismAcks'] = [0, 0];
    }
}

function nextEventStamp(): int
{
    return (int) floor(microtime(true) * 1000);
}

function pushDiscardAnim(array &$state, int $cardId, int $playerIndex, string $cause): void
{
    if (!isset($state['discardAnims'])) $state['discardAnims'] = [];
    $state['_daCnt'] = ($state['_daCnt'] ?? 0) + 1;
    $state['discardAnims'][] = ['id' => $state['_daCnt'], 'cardId' => $cardId, 'playerIndex' => $playerIndex, 'cause' => $cause];
    if (count($state['discardAnims']) > 20) {
        $state['discardAnims'] = array_slice($state['discardAnims'], -20);
    }
}

function clearRoundScopedFlags(array &$state): void
{
    $state['naturalSelection'] = false;
    $state['naturalSelectionPlays'] = [0, 0];
    unset($state['revealQueueNextAt']);
}

function hasFaceDownInPrimary(array $state, int $playerIndex): bool
{
    $stack = $state['players'][$playerIndex]['field']['primary'] ?? [];
    foreach ($stack as $entry) {
        if (!empty($entry['faceDown'])) return true;
    }
    return false;
}

function revealNextFaceDownInPrimary(array &$state, int $playerIndex): bool
{
    $stack = &$state['players'][$playerIndex]['field']['primary'];
    if (!is_array($stack) || empty($stack)) return false;

    for ($i = count($stack) - 1; $i >= 0; $i--) {
        if (!empty($stack[$i]['faceDown'])) {
            $stack[$i]['faceDown'] = false;
            return true;
        }
    }
    return false;
}

function canTriggerFacismAgainstPasser(array &$state, int $passerId, string $destroyType, GameEngine $engine): bool
{
    foreach (['primary', 'left', 'right'] as $slot) {
        $top = $engine->getTopCard($state['players'][$passerId]['field'][$slot] ?? []);
        if (!$top || !empty($top['faceDown'])) continue;
        if (strtolower((string)($top['type'] ?? '')) !== $destroyType) continue;
        if ((int)($top['power'] ?? 0) <= 5000) return true;
    }
    return false;
}

function findFacismDestroyTarget(array &$state, string $destroyType, GameEngine $engine): ?array
{
    foreach ([0, 1] as $targetPlayerIndex) {
        foreach (['primary', 'left', 'right'] as $slot) {
            $top = $engine->getTopCard($state['players'][$targetPlayerIndex]['field'][$slot] ?? []);
            if (!$top || !empty($top['faceDown'])) continue;
            if (strtolower((string)($top['type'] ?? '')) === $destroyType) {
                return ['playerIndex' => $targetPlayerIndex, 'slot' => $slot, 'cardId' => (int)$top['id']];
            }
        }
    }
    return null;
}

// ── Action handlers ────────────────────────────────────────────────────────

function handleReady(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'setup') return 'Not in setup phase';
    $state['players'][$playerIndex]['ready'] = true;
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' is ready.';

    if ($state['players'][0]['ready'] && $state['players'][1]['ready']) {
        // Both ready — determine first player randomly and start round 1
        $first = rand(0, 1);
        $engine->startNewRound($state, $first);
        $state['log'][] = $state['players'][$first]['username'] . ' goes first (determined randomly).';
    }
    return null;
}

function handleRollDice(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'start_of_round') return 'Not in start-of-round phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';
    if ($state['players'][$playerIndex]['diceRoll'] !== null) return 'Already rolled';

    $d1 = rand(1, 6);
    $d2 = rand(1, 6);
    $total = $d1 + $d2;
    $bonus = (int)($state['players'][$playerIndex]['diceBonus'] ?? 0);
    $effective = min(12, max(2, $total + $bonus));

    $state['players'][$playerIndex]['diceRoll'] = [$d1, $d2, 'total' => $total, 'effective' => $effective];
    $state['log'][] = $state['players'][$playerIndex]['username'] . " rolled $d1+$d2=$total" . ($bonus ? " (+$bonus bonus) = $effective" : '') . '.';

    // Check if both players have rolled
    if ($state['players'][0]['diceRoll'] !== null && $state['players'][1]['diceRoll'] !== null) {
        // Both players check start-of-round cards (handled separately via playStartOfRound/skipStartOfRound)
        // Move to awaiting start-of-round actions
    }
    return null;
}

function handlePlayStartOfRound(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'start_of_round') return 'Not in start-of-round phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';
    if ($state['players'][$playerIndex]['startOfRoundUsed']) return 'Already used start-of-round';
    if ($state['players'][$playerIndex]['diceRoll'] === null) return 'Must roll dice first';

    $cardId = (int)($params['cardId'] ?? 0);
    if (!$cardId) return 'cardId required';

    // Verify card is in hand
    if (!in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';

    $card = $engine->getCard($cardId);
    $kwLower = array_map('strtolower', $card['keywords']);

    if (!in_array('greed', $kwLower) && !in_array('natural-selection', $kwLower)) {
        return 'Card has no [Start of round] keyword';
    }

    $engine->discardFromHand($state, $playerIndex, $cardId);
    $state['players'][$playerIndex]['startOfRoundUsed'] = true;

    // Pass turn to opponent if they haven't done their start-of-round yet
    $oppIdx = 1 - $playerIndex;
    if (!$state['players'][$oppIdx]['startOfRoundUsed']) {
        $state['turn'] = $oppIdx;
    }

    if (in_array('greed', $kwLower)) {
        $bonus = (int)($state['players'][$playerIndex]['diceBonus'] ?? 0) + 2;
        $state['players'][$playerIndex]['diceBonus'] = $bonus;
        // Recalculate effective roll
        $roll = $state['players'][$playerIndex]['diceRoll'];
        $effective = min(12, max(2, $roll['total'] + $bonus));
        $state['players'][$playerIndex]['diceRoll']['effective'] = $effective;
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Greed! Dice result +2 → ' . $effective . '.';
        $state['lastGreed'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'effective' => $effective];
    }

    if (in_array('natural-selection', $kwLower)) {
        $state['naturalSelection'] = true;
        $state['naturalSelectionPlays'] = [0, 0];
        $state['lastNaturalSelection'] = nextEventStamp();
        $state['naturalSelectionAcks'] = [0, 0];
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Natural Selection! Each player may only play one more card this round, face-down.';
        $state['log'][] = '⚠️ Natural Selection: each player may play one card this round, face-down!';
    }

    maybeStartMainPhase($state, $engine);
    return null;
}

function handleSkipStartOfRound(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'start_of_round') return 'Not in start-of-round phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';
    if ($state['players'][$playerIndex]['diceRoll'] === null) return 'Must roll dice first';
    $state['players'][$playerIndex]['startOfRoundUsed'] = true;
    // Pass turn to opponent if they haven't done their start-of-round yet
    $oppIdx = 1 - $playerIndex;
    if (!$state['players'][$oppIdx]['startOfRoundUsed']) {
        $state['turn'] = $oppIdx;
    }
    maybeStartMainPhase($state, $engine);
    return null;
}

function maybeStartMainPhase(array &$state, GameEngine $engine): void
{
    if (!$state['players'][0]['startOfRoundUsed'] || !$state['players'][1]['startOfRoundUsed']) return;

    // Draw cards for each player up to their effective roll
    foreach ([0, 1] as $idx) {
        $roll = $state['players'][$idx]['diceRoll'];
        if (!$roll) continue;
        $effective = (int)$roll['effective'];
        $current   = count($state['players'][$idx]['handIds']);
        $toDraw    = max(0, $effective - $current);
        if ($toDraw > 0) {
            $drawnNow = $engine->drawCards($state, $idx, $toDraw);
            if ($drawnNow > 0) {
                $state['log'][] = $state['players'][$idx]['username'] . " drew $drawnNow card(s).";
            }
        }
    }

    $state['phase'] = 'main_phase';
    $state['turn']  = $state['firstPlayer'];
    $state['log'][] = 'Main phase begins. ' . $state['players'][$state['firstPlayer']]['username'] . ' acts first.';
}

function handleDraw(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'draw') return 'Not in draw phase';

    $toDraw = (int)($state['players'][$playerIndex]['drawCount'] ?? 0);
    $engine->drawCards($state, $playerIndex, $toDraw);
    $state['players'][$playerIndex]['drawCount'] = 0;
    $state['players'][$playerIndex]['drawnThisRound'] = true;

    $state['log'][] = $state['players'][$playerIndex]['username'] . " drew $toDraw card(s).";

    if (($state['players'][0]['drawnThisRound'] ?? false) && ($state['players'][1]['drawnThisRound'] ?? false)) {
        $state['phase'] = 'main_phase';
        $state['turn']  = $state['firstPlayer'];
        foreach ($state['players'] as &$p) { $p['drawnThisRound'] = false; }
        unset($p);
        $state['log'][] = 'Main phase begins. ' . $state['players'][$state['firstPlayer']]['username'] . ' acts first.';
    }
    return null;
}

function handlePlayCard(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';

    $cardId  = (int)($params['cardId'] ?? 0);
    $slot    = $params['slot'] ?? 'primary';
    $faceDown = !empty($params['faceDown']);

    if (!$cardId) return 'cardId required';
    if (!in_array($slot, ['primary', 'left', 'right'])) return 'Invalid slot';

    $p = &$state['players'][$playerIndex];

    if (!in_array($cardId, $p['handIds'])) return 'Card not in hand';

    // Natural Selection: max 1 card per player, must be face-down
    if ($state['naturalSelection']) {
        if (($state['naturalSelectionPlays'][$playerIndex] ?? 0) >= 1) return 'Natural Selection: you may only play one more card this round';
        $faceDown = true;
    }

    // Supporting cards require a primary
    if ($slot !== 'primary' && empty($p['field']['primary'])) return 'Need a primary card first';

    // Max 2 supporting cards
    if ($slot !== 'primary') {
        $leftOccupied  = !empty($p['field']['left']);
        $rightOccupied = !empty($p['field']['right']);
        if ($slot === 'left' && $leftOccupied) return 'Left slot already occupied';
        if ($slot === 'right' && $rightOccupied) return 'Right slot already occupied';
        if ($leftOccupied && $rightOccupied) return 'Both supporting slots occupied';
    }

    // Face-down cards cannot evolve, but playing a new card to an empty slot is not evolving
    if (!empty($p['field'][$slot])) {
        return 'Slot occupied, use evolveCard to evolve';
    }

    // Remove from hand, add to field
    $idx = array_search($cardId, $p['handIds']);
    array_splice($p['handIds'], $idx, 1);
    $p['field'][$slot][] = ['cardId' => $cardId, 'faceDown' => $faceDown];

    if ($state['naturalSelection']) {
        $state['naturalSelectionPlays'][$playerIndex]++;
    }

    $card = $engine->getCard($cardId);
    $state['log'][] = $p['username'] . ' played ' . ($faceDown ? 'a face-down card' : $card['name']) . ' to ' . $slot . '.';

    if (!$faceDown) {
        $pending = $engine->applyWhenPlayed($state, $playerIndex, $slot, $cardId, $faceDown);
        if ($pending) enqueuePendingEffects($state, $pending);
    }

    return null;
}

function handleEvolveCard(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';

    $cardId   = (int)($params['cardId'] ?? 0);
    $slot     = $params['slot'] ?? 'primary';
    $faceDown = !empty($params['faceDown']);

    if (!$cardId) return 'cardId required';
    if (!in_array($slot, ['primary', 'left', 'right'])) return 'Invalid slot';

    $p = &$state['players'][$playerIndex];
    $inHand = in_array($cardId, $p['handIds']);
    $inGrave = in_array($cardId, $p['graveyardIds']);
    if (!$inHand && !$inGrave) return 'Card not in hand or graveyard';
    if (empty($p['field'][$slot])) return 'No card in slot to evolve';

    $currentTop = $engine->getTopCard($p['field'][$slot]);
    if (!$currentTop) return 'No active card';
    if ($currentTop['faceDown']) return 'Cannot evolve a face-down card';

    $newCard = $engine->getCard($cardId);
    $oldCard = $engine->getCard($currentTop['id']);

    // Wizard on the current top card allows any evolve, but the evolved card must be face-down.
    $isWizard = $engine->hasKeyword($currentTop['id'], 'wizard');

    // Check Autocracy on new card (allows devolve)
    $isAutocracy = $engine->hasKeyword($cardId, 'autocracy');

    // Check Sister Virus (can evolve into Little Sister from grave)
    $isSisterVirus = $engine->hasKeyword($currentTop['id'], 'sister-virus');
    $canSisterVirusGrave = $isSisterVirus && $inGrave && $engine->hasKeyword($cardId, 'little-sister');

    if ($isWizard) {
        if (!$inHand) return 'Wizard evolve requires a card from hand';
        $faceDown = true; // Wizard always plays face-down
    } else if ($canSisterVirusGrave) {
        // Sister Virus special path: evolve into a Little Sister from grave.
        // Remove from graveyard
        $gIdx = array_search($cardId, $p['graveyardIds']);
        array_splice($p['graveyardIds'], $gIdx, 1);
        // If the same card also exists in hand for any reason, remove it there too.
        $hIdx = array_search($cardId, $p['handIds']);
        if ($hIdx !== false) array_splice($p['handIds'], $hIdx, 1);
        // Don't re-remove from hand below
        goto evolveApply;
    } else {
        if (!$inHand) {
            if ($isSisterVirus && $inGrave) return 'Sister Virus: target from graveyard must have Little Sister title';
            return 'Normal evolve requires a card from hand';
        }
        // Normal evolve: new card base power must be higher (unless Autocracy)
        if (!$isAutocracy && (int)$newCard['power'] <= (int)$oldCard['power']) {
            return 'New card must have higher power to evolve (unless target has Autocracy)';
        }
        if ($isAutocracy && (int)$newCard['power'] === (int)$oldCard['power']) {
            return 'Autocracy requires different power (higher or lower)';
        }
        // Check Monarchy: cannot evolve
        if ($engine->hasKeyword($currentTop['id'], 'monarchy')) return 'Monarchy: cannot evolve this card';
    }

    // Remove card from hand
    {
        $idx = array_search($cardId, $p['handIds']);
        array_splice($p['handIds'], $idx, 1);
    }

    evolveApply:
    $p['field'][$slot][] = ['cardId' => $cardId, 'faceDown' => $faceDown];

    $state['log'][] = $p['username'] . ' evolved ' . $slot . ' to ' . ($faceDown ? 'face-down' : $newCard['name']) . '.';

    if (!$faceDown) {
        $engine->applyWhenEvolves($state, $playerIndex, $slot, $cardId, $currentTop['id']);
        $pending = $engine->applyWhenPlayed($state, $playerIndex, $slot, $cardId, $faceDown);
        if ($pending) enqueuePendingEffects($state, $pending);
    }

    if ($state['naturalSelection']) {
        $state['naturalSelectionPlays'][$playerIndex]++;
    }

    return null;
}

function handleUseKeyword(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    $keyword = strtolower($params['keyword'] ?? '');
    $cardId  = (int)($params['cardId'] ?? 0);

    switch ($keyword) {
        case 'communism': {
            if (!$cardId || !in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
            $p = &$state['players'][$playerIndex];
            if (empty($p['prizeIds'])) return 'No prize cards to replace';
            // Swap bottom prize card with this card
            $bottomPrize = array_shift($p['prizeIds']);
            $hIdx = array_search($cardId, $p['handIds']);
            array_splice($p['handIds'], $hIdx, 1);
            array_unshift($p['prizeIds'], $cardId);
            if ($bottomPrize !== null) $p['handIds'][] = $bottomPrize;
            $p['prizeCount'] = count($p['prizeIds']);
            $state['log'][] = $p['username'] . ' used Communism.';
            $state['log'][] = '⚠️ Communism: ' . $p['username'] . ' revealed their bottom prize card.';
            $state['lastCommunismUsed'] = nextEventStamp();
            $state['lastCommunismUser'] = $playerIndex;
            $state['communismAcks'] = [0, 0];
            return null;
        }
        case 'cultism': {
            $p = &$state['players'][$playerIndex];
            // Cultism card must be in graveyard
            $cultismInGrave = false;
            foreach ($p['graveyardIds'] as $cid) {
                if ($engine->hasKeyword($cid, 'Cultism')) { $cultismInGrave = true; break; }
            }
            if (!$cultismInGrave) return 'No Cultism card in graveyard';
            // Validate selected Little Sisters
            $selectedIds = array_map('intval', $params['selectedIds'] ?? []);
            if (count($selectedIds) !== 7) return 'Must select exactly 7 Little Sisters';
            $remaining = $p['graveyardIds'];
            foreach ($selectedIds as $sid) {
                if (!$engine->hasKeyword($sid, 'little-sister')) return 'Selected card is not a Little Sister';
                $pos = array_search($sid, $remaining);
                if ($pos === false) return 'Selected card not in graveyard';
                array_splice($remaining, $pos, 1); // consume one copy
            }
            // Move selected Little Sisters from graveyard to deck and shuffle
            $p['graveyardIds'] = array_values($remaining);
            foreach ($selectedIds as $sid) {
                $p['deckIds'][] = $sid;
            }
            shuffle($p['deckIds']);
            // Draw a prize card if available
            if (!empty($p['prizeIds'])) {
                $prize = array_shift($p['prizeIds']);
                $p['handIds'][] = $prize;
                $p['prizeCount'] = count($p['prizeIds']);
                $state['log'][] = $p['username'] . ' used Cultism! Drew a prize card.';
            } else {
                $state['log'][] = $p['username'] . ' used Cultism! Reshuffled 7 Little Sisters into deck.';
            }
            return null;
        }
        case 'rizz': {
            if (!$cardId || !in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
            $oppIdx = 1 - $playerIndex;
            enqueuePendingEffects($state, [[
                'type'         => 'rizz',
                'playerIndex'  => $playerIndex,
                'cardId'       => $cardId,
                'awaitingOpp'  => true,
            ]]);
            $engine->discardFromHand($state, $playerIndex, $cardId);
            pushDiscardAnim($state, $cardId, $playerIndex, 'rizz');
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Rizz! Opponent may discard to negate.';
            return null;
        }
        case 'rizz_respond': {
            // Opponent responds to Rizz
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'rizz') return 'No Rizz pending';
            $oppIdx = 1 - $pending['playerIndex'];
            if ($playerIndex !== $oppIdx) return 'Not your response';
            $negate  = !empty($params['negate']);
            $negateCardId = (int)($params['negateCardId'] ?? 0);
            if ($negate && $negateCardId && in_array($negateCardId, $state['players'][$playerIndex]['handIds'])) {
                $engine->discardFromHand($state, $playerIndex, $negateCardId);
                pushDiscardAnim($state, $negateCardId, $playerIndex, 'rizz');
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' negated Rizz.';
            } else {
                // Rizz resolves: reveal one face-down card of opponent
                $rizzPlayer = $pending['playerIndex'];
                $revealTarget = $params['revealSlot'] ?? null;
                if ($revealTarget) {
                    $field = $state['players'][$playerIndex]['field'];
                    $slot  = $revealTarget;
                    if (!empty($field[$slot])) {
                        $topIdx = count($field[$slot]) - 1;
                        if ($state['players'][$playerIndex]['field'][$slot][$topIdx]['faceDown']) {
                            $state['players'][$playerIndex]['field'][$slot][$topIdx]['faceDown'] = false;
                            $state['log'][] = 'Rizz revealed a face-down card!';
                        }
                    }
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'necromancy_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'necromancy') return 'No Necromancy pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $zombieId = (int)($params['zombieId'] ?? 0);
            $slot     = $params['slot'] ?? 'left';
            if ($zombieId) {
                $p = &$state['players'][$playerIndex];
                $gIdx = array_search($zombieId, $p['graveyardIds']);
                if ($gIdx !== false) {
                    array_splice($p['graveyardIds'], $gIdx, 1);
                    if (!in_array($slot, ['left', 'right'])) $slot = 'left';
                    if (!empty($p['field'][$slot])) $slot = ($slot === 'left' ? 'right' : 'left');
                    $p['field'][$slot][] = ['cardId' => $zombieId, 'faceDown' => false];
                    $engine->recalcDemocracy($state, $playerIndex);
                    $state['log'][] = $p['username'] . ' played a Zombie from graveyard (Necromancy).';
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'makkarajarvi_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'makkarajarvi') return 'No Makkarajärvi pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $returnCardId = (int)($params['returnCardId'] ?? 0);
            $p = &$state['players'][$playerIndex];
            if ($returnCardId) {
                // Find in supporting slots
                foreach (['left', 'right'] as $s) {
                    if (!empty($p['field'][$s])) {
                        $topIdx = count($p['field'][$s]) - 1;
                        if ($p['field'][$s][$topIdx]['cardId'] == $returnCardId) {
                            array_pop($p['field'][$s]);
                            $returnedCard = $engine->getCard($returnCardId);
                            // Check if it's makkara (name contains makkara)
                            if (stripos($returnedCard['name'], 'makkara') !== false) {
                                $p['graveyardIds'][] = $returnCardId;
                                $engine->drawCards($state, $playerIndex, 1);
                                $state['log'][] = $p['username'] . ' discarded a Makkara and drew a card.';
                            } else {
                                $p['handIds'][] = $returnCardId;
                                $state['log'][] = $p['username'] . ' returned a card to hand.';
                            }
                            $engine->recalcDemocracy($state, $playerIndex);
                            break;
                        }
                    }
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'sahkotalo_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'sahkotalo') return 'No Sähkötalo pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $discardIds   = array_map('intval', $params['discardIds'] ?? []);
            $retriggerIds = array_values(array_unique(array_map('intval', $params['retriggerIds'] ?? [])));
            $retriggerSlots = array_values(array_unique(array_map(function($s) {
                return strtolower((string)$s);
            }, $params['retriggerSlots'] ?? [])));
            $p = &$state['players'][$playerIndex];
            $discarded = 0;
            foreach (array_slice($discardIds, 0, 2) as $dId) {
                if ($engine->discardFromHand($state, $playerIndex, (int)$dId)) {
                    pushDiscardAnim($state, (int)$dId, $playerIndex, 'sahkotalo');
                    $discarded++;
                }
            }

            // Build selectable support tops from actual board state.
            $supportTopBySlot = [];
            foreach (['left', 'right'] as $slotName) {
                $stack = $p['field'][$slotName] ?? [];
                if (empty($stack)) continue;
                $topEntry = $stack[count($stack) - 1];
                $topId = (int)($topEntry['cardId'] ?? 0);
                if ($topId > 0) {
                    $supportTopBySlot[$slotName] = $topId;
                }
            }

            // Backward compatibility: if slots are not provided, map retrigger card IDs to current support slots.
            if (empty($retriggerSlots) && !empty($retriggerIds)) {
                foreach ($retriggerIds as $rId) {
                    foreach ($supportTopBySlot as $slotName => $topId) {
                        if ($topId === (int)$rId) {
                            $retriggerSlots[] = $slotName;
                            break;
                        }
                    }
                }
                $retriggerSlots = array_values(array_unique($retriggerSlots));
            }

            // Retrigger up to $discarded chosen support slots.
            foreach (array_slice($retriggerSlots, 0, $discarded) as $slotName) {
                if (!isset($supportTopBySlot[$slotName])) continue;
                $rId = (int)$supportTopBySlot[$slotName];
                $newPending = $engine->applyWhenPlayed($state, $playerIndex, $slotName, (int)$rId);
                if (!empty($newPending)) enqueuePendingEffects($state, $newPending);

                // If this top card is evolved (has underlying card), retrigger evolve effects too.
                $stack = $p['field'][$slotName] ?? [];
                if (count($stack) >= 2) {
                    $oldCardId = (int)$stack[count($stack) - 2]['cardId'];
                    $engine->applyWhenEvolves($state, $playerIndex, $slotName, (int)$rId, $oldCardId);
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'herwood_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'herwood') return 'No Herwood pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $chosenId = (int)($params['chosenId'] ?? 0);
            $top3     = $pending['top3'];
            $slot     = $pending['slot'];
            $p = &$state['players'][$playerIndex];
            if ($chosenId && in_array($chosenId, $top3)) {
                // Devolve (the herwood card is the current primary/slot top — replace with chosen)
                $herwoodId = (int)$pending['herwoodCardId'];
                $herwoodCard = $engine->getCard($herwoodId);
                $chosenCard = $engine->getCard($chosenId);
                if ($chosenCard && $herwoodCard && (int)$chosenCard['power'] < (int)$herwoodCard['power']) {
                    $engine->removeTopFromStack($state, $playerIndex, $slot);
                    $p['field'][$slot][] = ['cardId' => $chosenId, 'faceDown' => false];
                    // Others go to hand
                    foreach ($top3 as $t) {
                        if ($t !== $chosenId) $p['handIds'][] = $t;
                    }
                    $state['log'][] = $p['username'] . ' devolved via Herwood.';
                } else {
                    // Invalid devolve target (not lower power) -> fail and return cards in order.
                    $p['deckIds'] = array_merge($top3, $p['deckIds']);
                }
            } else {
                // Return top3 to deck in same order
                $p['deckIds'] = array_merge($top3, $p['deckIds']);
            }
            $passerId = (int)($pending['passerId'] ?? (1 - $playerIndex));
            consumePendingEffect($state);

            // Re-check pass after Herwood resolves.
            $winner = $engine->checkWinRound($state, $passerId);
            if ($winner === $playerIndex) {
                $state['phase'] = 'main_phase';
                $state['turn']  = $passerId;
                $state['log'][] = 'Pass countered — main phase resumes.';
            }
            return null;
        }
        case 'elder_slime_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'elder_slime_discard') return 'No Elder-Slime discard pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $p = &$state['players'][$playerIndex];
            $handCount = count($p['handIds']);
            $discardCount = min((int)($pending['discardCount'] ?? 2), 2);
            if ($handCount <= $discardCount) {
                $discardIds = $p['handIds'];
            } else {
                $discardIds = array_values(array_unique(array_map('intval', $params['discardIds'] ?? [])));
                if (count($discardIds) !== $discardCount) return 'Choose exactly ' . $discardCount . ' cards to discard';
            }

            $discarded = 0;
            foreach ($discardIds as $dId) {
                if ($engine->discardFromHand($state, $playerIndex, (int)$dId)) {
                    pushDiscardAnim($state, (int)$dId, $playerIndex, 'elder_slime');
                    $discarded++;
                }
            }
            if ($discarded < min($discardCount, $handCount)) return 'Invalid discard selection';

            $state['log'][] = $p['username'] . ' discarded ' . $discarded . ' card(s) (Elder-Slime).';
            consumePendingEffect($state);
            return null;
        }
        case 'mikontalo_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'mikontalo_discard') return 'No Mikontalo discard pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $discardId = (int)($params['discardId'] ?? 0);
            $handIds = $state['players'][$playerIndex]['handIds'];
            if (!$discardId && count($handIds) === 1) {
                $discardId = (int)$handIds[0];
            }
            if (!$discardId || !in_array($discardId, $handIds)) return 'Must discard a card from hand';

            $engine->discardFromHand($state, $playerIndex, $discardId);
            pushDiscardAnim($state, $discardId, $playerIndex, 'mikontalo');
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' discarded a card (Mikontalo).';
            $passerId = (int)($pending['passerId'] ?? (1 - $playerIndex));
            consumePendingEffect($state);

            // Check if pass is now countered
            $winner = $engine->checkWinRound($state, $passerId);
            if ($winner === $playerIndex) {
                $state['phase'] = 'main_phase';
                $state['turn']  = $passerId;
                $state['log'][] = 'Pass countered — main phase resumes.';
            }
            return null;
        }
        case 'communism_draw_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'communism_draw') return 'No Communism draw pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $drawFrom = strtolower((string)($params['drawFrom'] ?? 'self'));
            $deckOwner = $drawFrom === 'opponent' ? (1 - $playerIndex) : $playerIndex;
            $drawn = $engine->drawCards($state, $playerIndex, 1, $deckOwner, false);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' drew ' . ($drawn > 0 ? 'a card' : 'no card') . ' from ' . ($deckOwner === $playerIndex ? 'their own deck' : 'opponent\'s deck') . ' (Communism).';
            consumePendingEffect($state);
            return null;
        }
        case 'facism_resolve_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'facism_resolve') return 'No Facism pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $now = nextEventStamp();
            $nextAt = (int)($pending['nextAt'] ?? 0);
            if ($now < $nextAt) {
                return null;
            }

            $facismPasserId = (int)($pending['passerId'] ?? (1 - $playerIndex));
            $destroyType = strtolower((string)($pending['destroyType'] ?? ''));
            $target = findFacismDestroyTarget($state, $destroyType, $engine);
            if (!$target) {
                $state['log'][] = 'Facism finished destroying all ' . $destroyType . ' tops.';
                consumePendingEffect($state);
                // Re-evaluate: if passer is no longer winning, negate the pass.
                $winner = $engine->checkWinRound($state, $facismPasserId);
                if ($winner === null || $winner !== $facismPasserId) {
                    $state['phase'] = 'main_phase';
                    $state['turn']  = $facismPasserId;
                    $state['log'][] = 'Facism negated the pass — ' . $state['players'][$facismPasserId]['username'] . ' continues their turn.';
                }
                return null;
            }

            $targetPlayerIndex = (int)$target['playerIndex'];
            $targetSlot = $target['slot'];
            $engine->removeTopFromStack($state, $targetPlayerIndex, $targetSlot);

            // If a player's primary stack is gone, their supporting stacks are discarded immediately.
            if (empty($state['players'][$targetPlayerIndex]['field']['primary'])) {
                $engine->discardFromField($state, $targetPlayerIndex, 'left');
                $engine->discardFromField($state, $targetPlayerIndex, 'right');
            }

            $engine->recalcDemocracy($state, 0);
            $engine->recalcDemocracy($state, 1);

            $state['lastFacismDestroy'] = [
                'ts' => nextEventStamp(),
                'byPlayerIndex' => $playerIndex,
                'targetPlayerIndex' => $targetPlayerIndex,
                'slot' => $targetSlot,
                'destroyType' => $destroyType,
            ];
            $state['log'][] = 'Facism destroyed a ' . ucfirst($destroyType) . ' from ' . $state['players'][$targetPlayerIndex]['username'] . ' (' . $targetSlot . ').';

            $nextTarget = findFacismDestroyTarget($state, $destroyType, $engine);
            if (!$nextTarget) {
                $state['log'][] = 'Facism finished destroying all ' . $destroyType . ' tops.';
                consumePendingEffect($state);
                // Re-evaluate: if passer is no longer winning, negate the pass.
                $winner = $engine->checkWinRound($state, $facismPasserId);
                if ($winner === null || $winner !== $facismPasserId) {
                    $state['phase'] = 'main_phase';
                    $state['turn']  = $facismPasserId;
                    $state['log'][] = 'Facism negated the pass — ' . $state['players'][$facismPasserId]['username'] . ' continues their turn.';
                }
                return null;
            }

            ensurePendingEffects($state);
            if (!empty($state['pendingEffects'][0]) && ($state['pendingEffects'][0]['type'] ?? '') === 'facism_resolve') {
                $state['pendingEffects'][0]['nextAt'] = $now + 1000;
                $state['pendingEffect'] = $state['pendingEffects'][0];
            }
            return null;
        }
    }
    return 'Unknown keyword: ' . $keyword;
}

function getTriggerableOpponentPassesEffects(array &$state, int $responderIndex, int $passerId, GameEngine $engine): array
{
    $effects = [];
    $responder = $state['players'][$responderIndex] ?? null;
    if (!$responder) return $effects;

    foreach (['primary', 'left', 'right'] as $slot) {
        $myTop = $engine->getTopCard($responder['field'][$slot] ?? []);
        if (!$myTop) continue;

        if ($engine->hasKeyword($myTop['id'], 'facism')) {
            // Facism is triggerable if passer has any face-up target type (top of any stack) at <= 5000.
            $myType = strtolower((string)($myTop['type'] ?? ''));
            $destroyType = WEAK_TYPE[$myType] ?? null;
            if ($destroyType && canTriggerFacismAgainstPasser($state, $passerId, $destroyType, $engine)) {
                $effects[] = ['keyword' => 'facism', 'slot' => $slot];
            }
        }
        if ($engine->hasKeyword($myTop['id'], 'herwood')) {
            $effects[] = ['keyword' => 'herwood', 'slot' => $slot];
        }
        if ($engine->hasKeyword($myTop['id'], 'mikontalo')) {
            $effects[] = ['keyword' => 'mikontalo', 'slot' => $slot];
        }
    }

    return $effects;
}

function handleAcknowledgeNotification(array &$state, int $playerIndex, array $params): ?string
{
    ensureNotificationState($state);
    $kind = strtolower((string)($params['kind'] ?? ''));
    $stamp = (int)($params['stamp'] ?? 0);

    if ($kind === 'natural_selection') {
        $current = (int)($state['lastNaturalSelection'] ?? 0);
        if ($current && $stamp === $current) {
            $state['naturalSelectionAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'communism') {
        $current = (int)($state['lastCommunismUsed'] ?? 0);
        if ($current && $stamp === $current) {
            $state['communismAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    return 'Unknown notification kind';
}

function handlePass(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';

    $p = $state['players'][$playerIndex];
    if (empty($p['field']['primary'])) return 'Need a primary card to pass';

    $state['log'][] = $state['players'][$playerIndex]['username'] . ' passes.';

    $oppIdx = 1 - $playerIndex;
    $opp    = $state['players'][$oppIdx];

    // If opponent has no primary card, they get another turn
    if (empty($opp['field']['primary'])) {
        $state['turn'] = $oppIdx;
        $state['log'][] = $state['players'][$oppIdx]['username'] . ' has no card — their main phase continues.';
        return null;
    }

    // Move to passing phase only when there is at least one triggerable response.
    $triggerable = getTriggerableOpponentPassesEffects($state, $oppIdx, $playerIndex, $engine);
    if (empty($triggerable)) {
        $state['passerId'] = $playerIndex;
        resolvePassingPhase($state, $engine);
        return null;
    }

    // Opponent has at least one triggerable [Opponent passes] effect.
    $state['phase']    = 'passing_phase';
    $state['passerId'] = $playerIndex;
    $state['log'][] = 'Passing phase — opponent may respond.';
    return null;
}

function handleSurrender(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    $state['players'][$playerIndex]['surrendered'] = true;
    clearRoundScopedFlags($state);
    $state['phase']         = 'end_of_round';
    $state['roundWinnerId'] = 1 - $playerIndex;
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' surrendered.';
    return null;
}

function handleGameSurrender(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    $state['players'][$playerIndex]['surrendered'] = true;
    clearRoundScopedFlags($state);
    $state['phase']      = 'game_over';
    $state['gameWinner'] = 1 - $playerIndex;
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' surrendered the match!';
    return null;
}

function handleOpponentPassesResponse(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'passing_phase') return 'Not in passing phase';
    $passerId  = $state['passerId'];
    $oppIdx    = 1 - $passerId;
    if ($playerIndex !== $oppIdx) return 'Only the non-passing player responds';

    $keyword = strtolower($params['keyword'] ?? '');
    $slot    = $params['slot'] ?? 'primary';

    switch ($keyword) {
        case 'facism': {
            $p = $state['players'][$playerIndex];
            // Trigger: passing player has target type on top of any stack at <= 5000 power.
            $myTop    = $engine->getTopCard($p['field'][$slot]);
            if (!$myTop) return 'No card in that slot';
            if (!$engine->hasKeyword($myTop['id'], 'facism')) return 'Card does not have Facism';
            $myType   = strtolower((string)($myTop['type'] ?? ''));
            $destroyType = WEAK_TYPE[$myType] ?? null;
            if (!$destroyType) return 'Facism trigger condition not met';
            if (!canTriggerFacismAgainstPasser($state, $passerId, $destroyType, $engine)) {
                return 'Facism trigger condition not met';
            }

            // Reveal this response card if it was face-down.
            if (!empty($myTop['faceDown'])) {
                $topIdx = count($state['players'][$playerIndex]['field'][$slot]) - 1;
                if ($topIdx >= 0) $state['players'][$playerIndex]['field'][$slot][$topIdx]['faceDown'] = false;
            }

            enqueuePendingEffects($state, [[
                'type' => 'facism_resolve',
                'playerIndex' => $playerIndex,
                'passerId' => $passerId,
                'destroyType' => $destroyType,
                'nextAt' => nextEventStamp(),
            ]]);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' triggered Facism (' . ucfirst($destroyType) . ').';
            return null;
        }
        case 'herwood': {
            $myTop = $engine->getTopCard($state['players'][$playerIndex]['field'][$slot]);
            if (!$myTop) return 'No card in that slot';
            if (!$engine->hasKeyword($myTop['id'], 'herwood')) return 'Card does not have Herwood';
            // Look at top 3 of deck
            $p   = &$state['players'][$playerIndex];
            $top3 = array_splice($p['deckIds'], 0, 3);
            enqueuePendingEffects($state, [[
                'type'         => 'herwood',
                'playerIndex'  => $playerIndex,
                'passerId'     => $passerId,
                'slot'         => $slot,
                'herwoodCardId' => $myTop['id'],
                'top3'         => $top3,
            ]]);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' uses Herwood — looking at top 3 cards.';
            return null;
        }
        case 'mikontalo': {
            $myTop = $engine->getTopCard($state['players'][$playerIndex]['field'][$slot]);
            if (!$myTop) return 'No card in that slot';
            if (!$engine->hasKeyword($myTop['id'], 'mikontalo')) return 'Card does not have Mikontalo';

            // Return this card from field to hand.
            $stack = &$state['players'][$playerIndex]['field'][$slot];
            if (empty($stack)) return 'No card in that slot';
            $returned = array_pop($stack);
            $returnedId = (int)$returned['cardId'];
            $state['players'][$playerIndex]['handIds'][] = $returnedId;
            $engine->recalcDemocracy($state, $playerIndex);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' returned card via Mikontalo.';

            enqueuePendingEffects($state, [[
                'type' => 'mikontalo_discard',
                'playerIndex' => $playerIndex,
                'passerId' => $passerId,
            ]]);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' must discard a card (Mikontalo).';
            return null;
        }
        case 'skip': {
            // No response, proceed to end of round
            break;
        }
    }

    resolvePassingPhase($state, $engine);
    return null;
}

function resolvePassingPhase(array &$state, GameEngine $engine): void
{
    $passerId = $state['passerId'];
    $oppIdx = 1 - $passerId;

    $passerTop = $engine->getTopCard($state['players'][$passerId]['field']['primary']);
    $oppTop = $engine->getTopCard($state['players'][$oppIdx]['field']['primary']);

    // If passer's primary is face-down, do not decide winner yet unless opponent has Divine
    // face-up or opponent's primary is also face-down (reveal flow).
    if ($passerTop && !empty($passerTop['faceDown'])) {
        $oppTopFaceDown = $oppTop && !empty($oppTop['faceDown']);
        $oppTopDivineFaceUp = $oppTop && empty($oppTop['faceDown']) && $engine->hasKeyword($oppTop['id'], 'divine');

        if ($oppTopDivineFaceUp) {
            clearRoundScopedFlags($state);
            $state['phase'] = 'end_of_round';
            $state['roundWinnerId'] = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . ' wins: Divine defeats the passing face-down primary.';
            return;
        }

        if (!$oppTopFaceDown) {
            $state['phase'] = 'main_phase';
            $state['turn']  = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . " gets a turn after a face-down pass.";
            return;
        }
    }

    // Passer face-up Divine vs. opponent face-down → passer wins immediately (no reveal needed)
    if ($oppTop && !empty($oppTop['faceDown']) && $passerTop && empty($passerTop['faceDown']) && $engine->hasKeyword($passerTop['id'], 'divine')) {
        clearRoundScopedFlags($state);
        $state['phase'] = 'end_of_round';
        $state['roundWinnerId'] = $passerId;
        $state['log'][] = $state['players'][$passerId]['username'] . ' wins: Divine defeats the face-down primary.';
        return;
    }

    // Non-passing player has face-down primary → enter reveal phase
    if ($oppTop && !empty($oppTop['faceDown'])) {
        enterRevealPhase($state, $engine);
        return;
    }

    // Fallback: only passer has hidden cards in stack (edge case)
    if (hasFaceDownInPrimary($state, $passerId)) {
        clearRoundScopedFlags($state);
        $state['phase'] = 'end_of_round';
        return;
    }

    $winner   = $engine->checkWinRound($state, $passerId);

    if ($winner === $passerId) {
        // Otherwise the passer is currently ahead; opponent gets another main phase.
        $state['phase'] = 'main_phase';
        $state['turn']  = $oppIdx;
        $state['log'][] = $state['players'][$oppIdx]['username'] . "'s card is beaten — they get another turn.";
        return;
    }

    if ($winner === null) {
        // Face-down involved — go to end_of_round for reveal
        clearRoundScopedFlags($state);
        $state['phase'] = 'end_of_round';
        return;
    }

    clearRoundScopedFlags($state);
    $state['phase']         = 'end_of_round';
    $state['roundWinnerId'] = $winner;
}

function enterRevealPhase(array &$state, GameEngine $engine): void
{
    $passerId = $state['passerId'];
    $oppIdx   = 1 - $passerId;

    clearRoundScopedFlags($state);
    $state['phase'] = 'reveal_phase';

    // Reveal the non-passing player's top face-down primary card
    revealNextFaceDownInPrimary($state, $oppIdx);

    $oppTop      = $engine->getTopCard($state['players'][$oppIdx]['field']['primary']);
    $oppCardId   = $oppTop ? (int)$oppTop['id'] : null;
    $oppCard     = $oppCardId ? $engine->getCard($oppCardId) : [];
    $oppCardName = $oppCard['name'] ?? '?';

    $passerHasFaceDown = hasFaceDownInPrimary($state, $passerId);
    $divineWin = $oppCardId && $engine->hasKeyword($oppCardId, 'divine') && $passerHasFaceDown;

    $stamp = nextEventStamp();
    $state['revealPhaseStamp']          = $stamp;
    $state['revealPhaseAcks']           = [0, 0];
    $state['revealPhaseStep']           = 'opp_revealed';
    $state['revealPhaseRevealedCardId'] = $oppCardId;
    $state['revealPhaseDivineWin']      = $divineWin;

    $msgs = ['', ''];
    if ($divineWin) {
        $msgs[$oppIdx]   = 'Your ' . $oppCardName . ' has Divine — it defeats the face-down card. You win the round!';
        $msgs[$passerId] = 'Opponent\'s ' . $oppCardName . ' has Divine — it defeats your face-down card. Opponent wins the round!';
        $state['log'][] = $oppCardName . ' has Divine — defeats the passing player\'s face-down card!';
    } else {
        $msgs[$oppIdx]   = 'You revealed: ' . $oppCardName . '.';
        $msgs[$passerId] = 'Opponent revealed: ' . $oppCardName . '.';
    }
    $state['revealPhaseMessages'] = $msgs;

    $state['log'][] = $state['players'][$oppIdx]['username'] . ' revealed their primary card: ' . $oppCardName . '.';
}

function maybeFinishRevealPhase(array &$state, GameEngine $engine): void
{
    $passerId = $state['passerId'];
    $oppIdx   = 1 - $passerId;

    if (!empty($state['revealPhaseDivineWin'])) {
        $state['phase']         = 'end_of_round';
        $state['roundWinnerId'] = $oppIdx;
        $state['log'][] = $state['players'][$oppIdx]['username'] . ' wins the round! (Divine)';
        return;
    }

    if (($state['revealPhaseStep'] ?? '') === 'opp_revealed') {
        $passerTop = $engine->getTopCard($state['players'][$passerId]['field']['primary']);
        if ($passerTop && !empty($passerTop['faceDown'])) {
            // Reveal passing player's card
            revealNextFaceDownInPrimary($state, $passerId);
            $passerTop      = $engine->getTopCard($state['players'][$passerId]['field']['primary']);
            $passerCardId   = $passerTop ? (int)$passerTop['id'] : null;
            $passerCard     = $passerCardId ? $engine->getCard($passerCardId) : [];
            $passerCardName = $passerCard['name'] ?? '?';

            $stamp = nextEventStamp();
            $state['revealPhaseStamp']          = $stamp;
            $state['revealPhaseAcks']           = [0, 0];
            $state['revealPhaseStep']           = 'passer_revealed';
            $state['revealPhaseRevealedCardId'] = $passerCardId;
            $state['revealPhaseDivineWin']      = false;
            $pMsgs = ['', ''];
            $pMsgs[$passerId] = 'You revealed: ' . $passerCardName . '.';
            $pMsgs[$oppIdx]   = 'Opponent revealed: ' . $passerCardName . '.';
            $state['revealPhaseMessages']       = $pMsgs;
            $state['log'][] = $state['players'][$passerId]['username'] . ' revealed their primary card: ' . $passerCardName . '.';
            return;
        }
    }

    // All reveals done — determine winner from now-face-up cards
    $winner = $engine->checkWinRound($state, $passerId);
    $state['phase']         = 'end_of_round';
    $state['roundWinnerId'] = $winner ?? $oppIdx;
    $state['log'][] = 'Reveal phase complete.';
}

function handleRevealPhaseAck(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if (($state['phase'] ?? '') !== 'reveal_phase') return 'Not in reveal phase';
    $stamp         = (int)($params['stamp'] ?? 0);
    $expectedStamp = (int)($state['revealPhaseStamp'] ?? 0);
    if (!$stamp || $stamp !== $expectedStamp) return null; // stale ack, ignore silently

    if (!isset($state['revealPhaseAcks']) || !is_array($state['revealPhaseAcks'])) {
        $state['revealPhaseAcks'] = [0, 0];
    }
    $state['revealPhaseAcks'][$playerIndex] = $stamp;

    if ($state['revealPhaseAcks'][0] === $expectedStamp && $state['revealPhaseAcks'][1] === $expectedStamp) {
        maybeFinishRevealPhase($state, $engine);
    }
    return null;
}

function handleConfirmPassingPhase(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'passing_phase') return 'Not in passing phase';
    resolvePassingPhase($state, $engine);
    return null;
}

function handleRevealFaceDown(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if (($state['phase'] ?? '') !== 'end_of_round') return 'Not in end_of_round phase';
    if (isset($state['roundWinnerId'])) return null;

    $nowMs = nextEventStamp();
    $nextAllowedAt = (int)($state['revealQueueNextAt'] ?? 0);
    if ($nowMs < $nextAllowedAt) {
        return null;
    }

    $passerId = $state['passerId'] ?? 0;
    $oppIdx   = 1 - $passerId;

    $passerPrimary = $state['players'][$passerId]['field']['primary'] ?? [];
    $oppPrimary = $state['players'][$oppIdx]['field']['primary'] ?? [];
    if (empty($passerPrimary) || empty($oppPrimary)) {
        $winner = $engine->checkWinRound($state, $passerId);
        $state['roundWinnerId'] = $winner ?? $oppIdx;
        unset($state['revealQueueNextAt']);
        return null;
    }

    // Reveal queue order: passing player first, then opponent, one card per step.
    if (revealNextFaceDownInPrimary($state, $passerId)) {
        $state['log'][] = $state['players'][$passerId]['username'] . ' revealed one primary card.';
        $state['revealQueueNextAt'] = $nowMs + 1000;
        return null;
    }

    if (revealNextFaceDownInPrimary($state, $oppIdx)) {
        $state['log'][] = $state['players'][$oppIdx]['username'] . ' revealed one primary card.';
        $state['revealQueueNextAt'] = $nowMs + 1000;
        return null;
    }

    // Safety: do not declare winner until all primary face-down cards are revealed.
    if (hasFaceDownInPrimary($state, $passerId) || hasFaceDownInPrimary($state, $oppIdx)) {
        $state['revealQueueNextAt'] = $nowMs + 1000;
        return null;
    }

    // All primary cards are face-up -> finalize winner.
    $winner = $engine->checkWinRound($state, $passerId);
    $state['roundWinnerId'] = $winner ?? $oppIdx;
    $state['log'][] = 'Primary reveal complete.';
    unset($state['revealQueueNextAt']);
    return null;
}

function handleDrawPrize(array &$state, int $playerIndex, array $params, Database $db): ?string
{
    $winnerId = $state['roundWinnerId'] ?? null;
    if ($winnerId === null) return 'No round winner determined';
    if ($playerIndex !== $winnerId) return 'You did not win this round';

    $p = &$state['players'][$winnerId];
    $loserId = 1 - $winnerId;

    if (empty($p['prizeIds'])) {
        // Already at 0 prize cards → WIN
        $state['phase']     = 'game_over';
        $state['gameWinner'] = $winnerId;
        $state['log'][] = $p['username'] . ' wins the game!';
        return null;
    }

    // Draw one prize card
    $prize = array_shift($p['prizeIds']);
    $p['handIds'][]  = $prize;
    $p['prizeCount'] = count($p['prizeIds']);
    $state['log'][] = $p['username'] . ' drew a prize card. ' . $p['prizeCount'] . ' remaining.';

    // Check win condition
    if (empty($p['prizeIds'])) {
        // Next time they win a round they win the game
        $state['log'][] = $p['username'] . ' has 0 prize cards left!';
    }

    // Start next round — loser goes first
    $engine = new GameEngine($db);
    $engine->startNewRound($state, $loserId);
    return null;
}

// ── Auto-resolve end of round ────────────────────────────────────────────────

function autoResolveEndOfRound(array &$state, GameEngine $engine): void
{
    $winnerId = $state['roundWinnerId'] ?? null;
    if ($winnerId === null) return;
    $loser = 1 - $winnerId;
    $p = &$state['players'][$winnerId];

    $state['log'][] = $state['players'][$winnerId]['username'] . ' wins the round!';

    if (empty($p['prizeIds'])) {
        $state['phase']      = 'game_over';
        $state['gameWinner'] = $winnerId;
        $state['log'][] = $p['username'] . ' wins the game!';
        return;
    }

    $prize = array_shift($p['prizeIds']);
    $p['handIds'][]  = $prize;
    $p['prizeCount'] = count($p['prizeIds']);
    $state['log'][] = $p['username'] . ' drew a prize card. ' . $p['prizeCount'] . ' remaining.';
    if (empty($p['prizeIds'])) {
        $state['log'][] = $p['username'] . ' has 0 prize cards left!';
    }

    $engine->startNewRound($state, $loser);
}

function handleNextRound(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'end_of_round') return 'Not in end_of_round phase';
    if (!isset($state['roundWinnerId'])) {
        handleRevealFaceDown($state, $playerIndex, $params, $engine);
        return null;
    }
    autoResolveEndOfRound($state, $engine);
    return null;
}

function handleSubmitRPS(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'setup') return 'Not in setup phase';
    $choice = strtolower($params['choice'] ?? '');
    if (!in_array($choice, ['rock', 'paper', 'scissors'])) return 'Invalid choice';
    if ($state['rpsChoices'][$playerIndex] !== null) return 'Already submitted';

    $state['rpsChoices'][$playerIndex] = $choice;
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' chose.';

    if ($state['rpsChoices'][0] !== null && $state['rpsChoices'][1] !== null) {
        $c0 = $state['rpsChoices'][0];
        $c1 = $state['rpsChoices'][1];
        $beats = ['rock' => 'scissors', 'scissors' => 'paper', 'paper' => 'rock'];

        if ($c0 === $c1) {
            $state['rpsResult']  = ['p0' => $c0, 'p1' => $c1, 'winner' => 'tie', 'round' => (int)($state['rpsRound'] ?? 1)];
            $state['rpsChoices'] = [null, null];
            $state['rpsRound']   = ($state['rpsRound'] ?? 1) + 1;
            $state['log'][] = 'RPS tie! Choose again.';
        } elseif ($beats[$c0] === $c1) {
            $state['rpsResult']        = ['p0' => $c0, 'p1' => $c1, 'winner' => 0, 'round' => (int)($state['rpsRound'] ?? 1)];
            $state['rpsAwaitingOrder'] = true;
            $state['rpsWinner']        = 0;
            $state['log'][] = $state['players'][0]['username'] . ' wins RPS! Choosing turn order…';
        } else {
            $state['rpsResult']        = ['p0' => $c0, 'p1' => $c1, 'winner' => 1, 'round' => (int)($state['rpsRound'] ?? 1)];
            $state['rpsAwaitingOrder'] = true;
            $state['rpsWinner']        = 1;
            $state['log'][] = $state['players'][1]['username'] . ' wins RPS! Choosing turn order…';
        }
    }
    return null;
}

function handleChooseOrder(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'setup') return 'Not in setup phase';
    if (!($state['rpsAwaitingOrder'] ?? false)) return 'No turn order choice pending';
    if (($state['rpsWinner'] ?? -1) !== $playerIndex) return 'Not your choice';

    $goFirst = $params['goFirst'] ?? true;
    $firstPlayer = $goFirst ? $playerIndex : (1 - $playerIndex);
    $state['rpsAwaitingOrder'] = false;
    $state['rpsOrderChosen']   = $firstPlayer;
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' chose to go ' . ($goFirst ? 'first' : 'second') . '.';
    $engine->startNewRound($state, $firstPlayer);
    return null;
}

// ── Main entry point ────────────────────────────────────────────────────────

function performAction(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $matchId  = $database->getIntParam('matchId');
    $action   = $database->getStringParam('action');
    $raw      = $database->getRequestData();
    $rawArr   = json_decode(json_encode($raw), true) ?? [];
    // Frontend sends { matchId, action, params: { ... } } — unwrap the nested params
    $paramsArr = isset($rawArr['params']) && is_array($rawArr['params']) ? $rawArr['params'] : $rawArr;

    if (!$matchId) return Database::responseBadRequest('matchId required');
    if (!$action)  return Database::responseBadRequest('action required');

    // Load match + verify user
    $match = $database->query(
        'SELECT id, player1Id, player2Id, status FROM isBack_match WHERE id = :matchId',
        ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$match) return Database::responseNotFound();
    $match = $match[0];
    $userId = $user->getId();
    if ($match['player1Id'] != $userId && $match['player2Id'] != $userId) {
        return Database::responseForbidden(['error' => 'You are not in this match']);
    }
    if ($match['status'] !== 'active') return Database::responseBadRequest('Match is not active');

    $playerIndex = $match['player1Id'] == $userId ? 0 : 1;

    // Load game state with lock
    $gsRows = $database->query(
        "SELECT id, stateJson, version FROM isBack_gameState WHERE matchId = :matchId AND status = 'active' ORDER BY gameNumber DESC LIMIT 1",
        ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$gsRows) return Database::responseNotFound();
    $gs      = $gsRows[0];
    $gsId    = (int)$gs['id'];
    $version = (int)$gs['version'];
    $state   = json_decode($gs['stateJson'], true);
    ensurePendingEffects($state);
    ensureNotificationState($state);

    $hasPendingEffect = getCurrentPendingEffect($state) !== null;
    if ($hasPendingEffect && !in_array($action, ['useKeyword', 'surrender', 'gameSurrender', 'acknowledgeNotification', 'revealPhaseAck'], true)) {
        return Database::responseBadRequest('Resolve pending effect first');
    }
    if ($hasPendingEffect && $action === 'useKeyword') {
        $kw = strtolower($paramsArr['keyword'] ?? '');
        if (!str_ends_with($kw, '_respond')) {
            return Database::responseBadRequest('Resolve pending effect first');
        }
    }

    $engine = new GameEngine($database);
    syncRevealedPrizeBottom($state, $engine);

    // Dispatch action
    $error = match($action) {
        // Setup / RPS
        'submitRPS'                => handleSubmitRPS($state, $playerIndex, $paramsArr, $engine),
        'chooseOrder'              => handleChooseOrder($state, $playerIndex, $paramsArr, $engine),
        'ready'                    => null, // no-op (replaced by RPS)
        // Start of round
        'rollDice'                 => handleRollDice($state, $playerIndex, $paramsArr, $engine),
        'playStartOfRound',
        'useStartOfRound'          => handlePlayStartOfRound($state, $playerIndex, $paramsArr, $engine),
        'skipStartOfRound',
        'doneStartOfRound'         => handleSkipStartOfRound($state, $playerIndex, $paramsArr, $engine),
        // Main phase
        'playCard'                 => handlePlayCard($state, $playerIndex, $paramsArr, $engine),
        'evolveCard'               => handleEvolveCard($state, $playerIndex, $paramsArr, $engine),
        'useKeyword'               => handleUseKeyword($state, $playerIndex, $paramsArr, $engine),
        'acknowledgeNotification'  => handleAcknowledgeNotification($state, $playerIndex, $paramsArr),
        'playCommunism'            => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'communism']), $engine),
        'useRizz'                  => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'rizz']), $engine),
        'activateCultism'          => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'cultism']), $engine),
        'pass'                     => handlePass($state, $playerIndex, $paramsArr, $engine),
        'surrender'                => handleSurrender($state, $playerIndex, $paramsArr, $engine),
        'gameSurrender'            => handleGameSurrender($state, $playerIndex, $paramsArr, $engine),
        // Passing phase
        'opponentPassesResponse',
        'triggerOpponentPasses'    => handleOpponentPassesResponse($state, $playerIndex, $paramsArr, $engine),
        'confirmPassingPhase',
        'confirmEndOfRound'        => handleConfirmPassingPhase($state, $playerIndex, $paramsArr, $engine),
        'revealFaceDown'           => handleRevealFaceDown($state, $playerIndex, $paramsArr, $engine),
        'revealPhaseAck'           => handleRevealPhaseAck($state, $playerIndex, $paramsArr, $engine),
        // End of round
        'nextRound',
        'drawPrize'                => handleNextRound($state, $playerIndex, $paramsArr, $engine),
        default                    => 'Unknown action: ' . $action,
    };

    if ($error) return Database::responseBadRequest($error);

    syncRevealedPrizeBottom($state, $engine);

    // Handle game over
    if (($state['phase'] ?? '') === 'game_over') {
        $database->query(
            "UPDATE isBack_gameState SET stateJson = :json, version = version + 1, status = 'completed', winnerId = :winnerId WHERE id = :id",
            [
                'json'     => ['value' => json_encode($state), 'type' => \PDO::PARAM_STR],
                'winnerId' => ['value' => (int)($match[$state['gameWinner'] === 0 ? 'player1Id' : 'player2Id']), 'type' => \PDO::PARAM_INT],
                'id'       => ['value' => $gsId, 'type' => \PDO::PARAM_INT],
            ]
        );
        $winnerUserId = (int)$match[$state['gameWinner'] === 0 ? 'player1Id' : 'player2Id'];
        $p1Wins = $state['gameWinner'] === 0 ? 1 : 0;
        $p2Wins = $state['gameWinner'] === 1 ? 1 : 0;
        $database->query(
            'UPDATE isBack_match SET player1Wins = player1Wins + :p1w, player2Wins = player2Wins + :p2w WHERE id = :id',
            [
                'p1w' => ['value' => $p1Wins, 'type' => \PDO::PARAM_INT],
                'p2w' => ['value' => $p2Wins, 'type' => \PDO::PARAM_INT],
                'id'  => ['value' => $matchId, 'type' => \PDO::PARAM_INT],
            ]
        );
        // Check if match is over (bo1 or bo3 win condition)
        $matchRow = $database->query('SELECT format, player1Wins, player2Wins FROM isBack_match WHERE id = :id', [
            'id' => ['value' => $matchId, 'type' => \PDO::PARAM_INT],
        ]);
        $mRow = $matchRow[0];
        $winsNeeded = $mRow['format'] === 'bo3' ? 2 : 1;
        if ($mRow['player1Wins'] >= $winsNeeded || $mRow['player2Wins'] >= $winsNeeded) {
            $matchWinner = $mRow['player1Wins'] >= $winsNeeded ? $match['player1Id'] : $match['player2Id'];
            $database->query(
                "UPDATE isBack_match SET status = 'completed', winnerId = :wid WHERE id = :id",
                [
                    'wid' => ['value' => $matchWinner, 'type' => \PDO::PARAM_INT],
                    'id'  => ['value' => $matchId, 'type' => \PDO::PARAM_INT],
                ]
            );
        } else {
            // Start next game in match
            $gameNumber = (int)$database->query(
                'SELECT MAX(gameNumber) gn FROM isBack_gameState WHERE matchId = :mid',
                ['mid' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
            )[0]['gn'];
            $loserIdx = 1 - $state['gameWinner'];
            // Reload decks from match
            $deckRows = $database->query(
                'SELECT player1DeckId, player2DeckId, player1Id, player2Id FROM isBack_match WHERE id = :id',
                ['id' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
            )[0];
            // Build fresh game state for next game in the series
            insertNextGame($database, $matchId, $deckRows, $gameNumber + 1);
        }
    } else {
        // Save updated state (optimistic locking via version)
        $database->query(
            'UPDATE isBack_gameState SET stateJson = :json, version = version + 1 WHERE id = :id AND version = :version',
            [
                'json'    => ['value' => json_encode($state), 'type' => \PDO::PARAM_STR],
                'id'      => ['value' => $gsId, 'type' => \PDO::PARAM_INT],
                'version' => ['value' => $version, 'type' => \PDO::PARAM_INT],
            ]
        );
    }

    return Database::responseSuccess(['ok' => true, 'version' => $version + 1, 'phase' => $state['phase']]);
}

$database = new Database();
$database->handleRequest(null, 'performAction');