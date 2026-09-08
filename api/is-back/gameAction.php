<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include(__DIR__ . "/gameHelper.php");

// Card type that beats another: beater => loser
// Rock beats Scissors, Paper beats Rock, Scissors beats Paper
// (used by keyword mechanics like Infernoid — unaffected by Gun, stays on the classic 3-way cycle)
const BEATS = ['rock' => 'scissors', 'paper' => 'rock', 'scissors' => 'paper'];
// Weak type of each card: the type that BEATS it (what each card is weak against)
// Rock is weak to Paper, Paper is weak to Scissors, Scissors is weak to Rock
// (used by keyword mechanics like Monarchy, Facism, Teleportation — unaffected by Gun)
const WEAK_TYPE = ['rock' => 'paper', 'paper' => 'scissors', 'scissors' => 'rock'];
// Combat resolution only: Gun beats Rock, Paper, and Scissors; nothing beats Gun.
const COMBAT_BEATS = ['rock' => ['scissors'], 'paper' => ['rock'], 'scissors' => ['paper'], 'gun' => ['rock', 'paper', 'scissors']];

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
            // Check for just-ok cards before reshuffling
            $justOkCards = array_filter($owner['graveyardIds'], fn($cid) => $this->hasKeyword((int)$cid, 'just-ok'));
            // Reshuffle selected owner's graveyard into selected owner's deck.
            $owner['deckIds'] = $owner['graveyardIds'];
            shuffle($owner['deckIds']);
            $owner['graveyardIds'] = [];
            $state['log'][] = $owner['username'] . ' reshuffled graveyard into deck.';
            // Trigger just-ok for each just-ok card being reshuffled: draw immediately so modal shows updated hand
            foreach ($justOkCards as $joId) {
                $this->drawCards($state, $deckOwnerIndex, 1);
                enqueuePendingEffects($state, [['type' => 'just_ok', 'playerIndex' => $deckOwnerIndex, 'cardId' => (int)$joId]]);
            }
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
        if (!$this->hasKeyword((int)$bottom, 'communism')) return false;
        // Trump Card negates this specific placement of Communism until a new card takes the bottom slot.
        if (($state['communismNegatedIds'][$playerIndex] ?? null) === (int)$bottom) return false;
        return true;
    }

    // Treasure: [If milled] Draw a card.
    public function applyTreasureIfMilled(array &$state, int $playerIndex, int $cardId): void
    {
        if (!$this->hasKeyword($cardId, 'treasure')) return;
        $this->drawCards($state, $playerIndex, 1);
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' drew a card (Treasure).';
    }

    // Farming: grow the farm — draw a card face-down from the deck into the farm zone.
    // Nobody, including the owner, may look at cards in the farm.
    public function growFarm(array &$state, int $playerIndex, int $count = 1): int
    {
        $grown = 0;
        for ($i = 0; $i < $count; $i++) {
            $p = &$state['players'][$playerIndex];
            if (empty($p['deckIds'])) {
                if (empty($p['graveyardIds'])) break;
                $justOkCards = array_filter($p['graveyardIds'], fn($cid) => $this->hasKeyword((int)$cid, 'just-ok'));
                $p['deckIds'] = $p['graveyardIds'];
                shuffle($p['deckIds']);
                $p['graveyardIds'] = [];
                $state['log'][] = $p['username'] . ' reshuffled graveyard into deck.';
                foreach ($justOkCards as $joId) {
                    $this->drawCards($state, $playerIndex, 1);
                    enqueuePendingEffects($state, [['type' => 'just_ok', 'playerIndex' => $playerIndex, 'cardId' => (int)$joId]]);
                }
            }
            if (empty($p['deckIds'])) break;
            $drawn = array_shift($p['deckIds']);
            if (!isset($p['farmIds']) || !is_array($p['farmIds'])) $p['farmIds'] = [];
            $p['farmIds'][] = $drawn;
            $grown++;
        }
        return $grown;
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
        // Infinity: fully supported (left AND right occupied) → infinite power
        if ($this->hasKeyword($top['id'], 'infinity')) {
            $leftOk = !empty($p['field']['left']);
            $rightOk = !empty($p['field']['right']);
            if ($leftOk && $rightOk) return 999999999;
        }
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

        if (in_array($passerType, COMBAT_BEATS[$oppType] ?? [])) return $oppIdx;
        if (in_array($oppType, COMBAT_BEATS[$passerType] ?? [])) return $passerId;

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
                    $state['lastMagicPotionAcks'] = [0, 0];
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
                case 'exam':
                    if (!$isFaceDown) {
                        $oppIdx = 1 - $playerIndex;
                        if (!empty($state['players'][$playerIndex]['deckIds'])) {
                            $state['lastExamEffect'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'slot' => $slot];
                            $state['lastExamEffectAcks'] = [0, 0];
                            $pendingEffects[] = ['type' => 'exam_guess', 'playerIndex' => $oppIdx, 'sourcePlayerIndex' => $playerIndex, 'cardId' => $cardId];
                        }
                    }
                    break;
                case 'infernoid':
                    if ($slot !== 'primary' && !$isFaceDown) {
                        $primaryTop = $this->getTopCard($state['players'][$playerIndex]['field']['primary']);
                        if ($primaryTop && strtolower($primaryTop['type']) === strtolower($card['type'])) {
                            $strongType = BEATS[strtolower($card['type'])] ?? null;
                            if ($strongType) {
                                $purgeTargets = [];
                                foreach ([0, 1] as $pIdx) {
                                    foreach ($state['players'][$pIdx]['graveyardIds'] as $gid) {
                                        $gc = $this->getCard((int)$gid);
                                        if (strtolower($gc['type'] ?? '') === $strongType) {
                                            $purgeTargets[] = ['playerIndex' => $pIdx, 'cardId' => (int)$gid];
                                        }
                                    }
                                }
                                if (!empty($purgeTargets)) {
                                    $pendingEffects[] = ['type' => 'infernoid_purge', 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'strongType' => $strongType, 'purgeTargets' => $purgeTargets];
                                }
                            }
                        }
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

        // Sinful: if evolved from a card with 2 000 or less power, mill 3 and opponent discards 1 per scissors milled.
        if (in_array('sinful', array_map('strtolower', $newCard['keywords']))) {
            $oldCard = $this->getCard($oldCardId);
            if ((int)($oldCard['power'] ?? 0) <= 2000) {
                $p = &$state['players'][$playerIndex];
                $milledIds = [];
                $treasureCardIds = [];
                $scissorsCount = 0;
                for ($i = 0; $i < 3; $i++) {
                    if (empty($p['deckIds'])) break;
                    $milledId = (int)array_shift($p['deckIds']);
                    $p['graveyardIds'][] = $milledId;
                    $milledIds[] = $milledId;
                    $milledCard = $this->getCard($milledId);
                    if (strtolower($milledCard['type'] ?? '') === 'scissors') $scissorsCount++;
                    if ($this->hasKeyword($milledId, 'treasure')) $treasureCardIds[] = $milledId;
                }

                $state['lastSinfulEffect'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'cardId' => $newCardId, 'slot' => $slot];
                $state['lastSinfulEffectAcks'] = [0, 0];
                $state['lastSinfulMill'] = [
                    'ts' => nextEventStamp(),
                    'playerIndex' => $playerIndex,
                    'cardId' => $newCardId,
                    'milledCardIds' => $milledIds,
                    'treasureCardIds' => $treasureCardIds,
                    'scissorsCount' => $scissorsCount,
                ];
                $state['lastSinfulMillAcks'] = [0, 0];
                $state['log'][] = $p['username'] . ' milled ' . count($milledIds) . ' card(s) (Sinful).';

                foreach ($treasureCardIds as $tId) {
                    $this->applyTreasureIfMilled($state, $playerIndex, $tId);
                }

                if ($scissorsCount > 0) {
                    $oppIdx = 1 - $playerIndex;
                    $oppHandCount = count($state['players'][$oppIdx]['handIds']);
                    if ($oppHandCount <= $scissorsCount) {
                        $discarded = 0;
                        while (!empty($state['players'][$oppIdx]['handIds'])) {
                            $last = array_pop($state['players'][$oppIdx]['handIds']);
                            $state['players'][$oppIdx]['graveyardIds'][] = $last;
                            pushDiscardAnim($state, (int)$last, $oppIdx, 'sinful');
                            $discarded++;
                        }
                        $state['log'][] = $state['players'][$oppIdx]['username'] . " discarded $discarded card(s) (Sinful).";
                    } else {
                        enqueuePendingEffects($state, [[
                            'type' => 'sinful_discard',
                            'playerIndex' => $oppIdx,
                            'discardCount' => $scissorsCount,
                            'sourcePlayerIndex' => $playerIndex,
                        ]]);
                        $state['log'][] = $state['players'][$oppIdx]['username'] . ' must choose ' . $scissorsCount . ' card(s) to discard (Sinful).';
                    }
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
        // Record field state before clearing for exit animation
        $fieldCards = [];
        foreach ($state['players'] as $pIdx => $p) {
            foreach (['primary', 'left', 'right'] as $slot) {
                if (!empty($p['field'][$slot])) {
                    $top = end($p['field'][$slot]);
                    $fieldCards[] = ['playerIndex' => $pIdx, 'slot' => $slot, 'cardId' => (int)$top['cardId'], 'faceDown' => $top['faceDown'] ?? false];
                }
            }
        }
        if (!empty($fieldCards)) {
            $state['lastFieldExit'] = ['ts' => nextEventStamp(), 'cards' => $fieldCards];
            $state['lastFieldExitAcks'] = [0, 0];
        }

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

        // Farming: at the end of each round, every existing farm grows by 1.
        $farmGrows = [];
        foreach ($state['players'] as $pIdx => $pData) {
            if (!empty($pData['farmIds'])) {
                $grown = $this->growFarm($state, $pIdx, 1);
                if ($grown > 0) {
                    $farmGrows[] = ['playerIndex' => $pIdx, 'amount' => $grown];
                    $state['log'][] = $state['players'][$pIdx]['username'] . "'s farm grew.";
                }
            }
        }
        if (!empty($farmGrows)) {
            $state['lastFarmGrow'] = ['ts' => nextEventStamp(), 'grows' => $farmGrows];
            $state['lastFarmGrowAcks'] = [0, 0];
        }
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
    if (!isset($state['communismNegatedIds']) || !is_array($state['communismNegatedIds'])) {
        $state['communismNegatedIds'] = [null, null];
    }

    foreach ([0, 1] as $idx) {
        $bottom = $state['players'][$idx]['prizeIds'][0] ?? null;
        $negated = $state['communismNegatedIds'][$idx] ?? null;
        $state['revealedPrizeBottom'][$idx] = ($bottom && $engine->hasKeyword((int)$bottom, 'communism') && $negated !== (int)$bottom) ? (int)$bottom : null;
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
    if (!isset($state['discardAnimAcks']) || !is_array($state['discardAnimAcks'])) {
        $state['discardAnimAcks'] = [0, 0];
    }
    if (!isset($state['cardPlayAnimAcks']) || !is_array($state['cardPlayAnimAcks'])) {
        $state['cardPlayAnimAcks'] = [0, 0];
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

function addCardPlayAnim(array &$state, int $playerIndex, int $cardId, string $slot, string $animType): void
{
    if (!isset($state['cardPlayAnims'])) $state['cardPlayAnims'] = [];
    $state['_cpaCnt'] = ($state['_cpaCnt'] ?? 0) + 1;
    $state['cardPlayAnims'][] = [
        'id'          => $state['_cpaCnt'],
        'playerIndex' => $playerIndex,
        'cardId'      => $cardId,
        'slot'        => $slot,
        'animType'    => $animType,
    ];
    if (count($state['cardPlayAnims']) > 30) {
        $state['cardPlayAnims'] = array_slice($state['cardPlayAnims'], -30);
    }
}

function clearRoundScopedFlags(array &$state): void
{
    $state['naturalSelection'] = false;
    $state['naturalSelectionPlays'] = [0, 0];
    unset($state['revealQueueNextAt']);
}

function checkAndApplyEqualExchange(array &$state, int $discardingPlayer, int $cardId, GameEngine $engine): void
{
    if (!$engine->hasKeyword($cardId, 'equal-exchange')) return;
    $oppIdx = 1 - $discardingPlayer;
    if (empty($state['players'][$oppIdx]['handIds'])) return;
    if (count($state['players'][$oppIdx]['handIds']) === 1) {
        $toDiscard = $state['players'][$oppIdx]['handIds'][0];
        $engine->discardFromHand($state, $oppIdx, $toDiscard);
        pushDiscardAnim($state, $toDiscard, $oppIdx, 'equal_exchange');
        $state['log'][] = $state['players'][$oppIdx]['username'] . ' discarded a card (Equal Exchange).';
    } else {
        enqueuePendingEffects($state, [[
            'type' => 'equal_exchange',
            'playerIndex' => $oppIdx,
            'sourceCardId' => $cardId,
        ]]);
        $state['log'][] = $state['players'][$oppIdx]['username'] . ' must discard a card (Equal Exchange).';
    }
}

function handleSuck(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';
    $cardId = (int)($params['cardId'] ?? 0);
    if (!$cardId || !in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
    if (!$engine->hasKeyword($cardId, 'suck')) return 'Card does not have Suck';
    $oppIdx = 1 - $playerIndex;
    $oppField = $state['players'][$oppIdx]['field'];
    if (empty($oppField['primary']) || empty($oppField['left']) || empty($oppField['right'])) {
        return 'Opponent does not have a full field (primary + left + right)';
    }
    // Record field state before destroying for animation
    $fieldCards = [];
    foreach ([0, 1] as $pIdx) {
        foreach (['primary', 'left', 'right'] as $slot) {
            if (!empty($state['players'][$pIdx]['field'][$slot])) {
                $top = end($state['players'][$pIdx]['field'][$slot]);
                $fieldCards[] = ['playerIndex' => $pIdx, 'slot' => $slot, 'cardId' => (int)$top['cardId'], 'faceDown' => $top['faceDown'] ?? false];
            }
        }
    }
    // Discard suck card from hand
    $engine->discardFromHand($state, $playerIndex, $cardId);
    pushDiscardAnim($state, $cardId, $playerIndex, 'suck');
    checkAndApplyEqualExchange($state, $playerIndex, $cardId, $engine);
    // Destroy all field cards for both players
    $engine->clearField($state, 0);
    $engine->clearField($state, 1);
    $engine->recalcDemocracy($state, 0);
    $engine->recalcDemocracy($state, 1);
    $ts = nextEventStamp();
    $state['lastSuckDestroy'] = ['ts' => $ts, 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'fieldCards' => $fieldCards];
    $state['lastSuckDestroyAcks'] = [0, 0];
    $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Suck! All field cards destroyed.';
    return null;
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

    if (!in_array('greed', $kwLower) && !in_array('natural-selection', $kwLower) && !in_array('mega-greed', $kwLower) && !in_array('tutor', $kwLower)) {
        return 'Card has no [Start of round] keyword';
    }

    $engine->discardFromHand($state, $playerIndex, $cardId);
    $state['players'][$playerIndex]['startOfRoundUsed'] = true;

    if (in_array('greed', $kwLower)) {
        $bonus = (int)($state['players'][$playerIndex]['diceBonus'] ?? 0) + 2;
        $state['players'][$playerIndex]['diceBonus'] = $bonus;
        // Recalculate effective roll
        $roll = $state['players'][$playerIndex]['diceRoll'];
        $effective = min(12, max(2, $roll['total'] + $bonus));
        $state['players'][$playerIndex]['diceRoll']['effective'] = $effective;
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Greed! Dice result +2 → ' . $effective . '.';
        $state['lastGreed'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'effective' => $effective];
        $state['lastGreedAcks'] = [0, 0];
    }

    if (in_array('mega-greed', $kwLower)) {
        // Discard entire hand (card itself already discarded above), +1 dice per 2 discarded
        $discardCount = 1; // mega-greed card itself
        while (!empty($state['players'][$playerIndex]['handIds'])) {
            $toDiscard = array_shift($state['players'][$playerIndex]['handIds']);
            $state['players'][$playerIndex]['graveyardIds'][] = $toDiscard;
            pushDiscardAnim($state, $toDiscard, $playerIndex, 'mega_greed');
            checkAndApplyEqualExchange($state, $playerIndex, $toDiscard, $engine);
            $discardCount++;
        }
        $bonusGain = (int)floor($discardCount / 2);
        $state['players'][$playerIndex]['diceBonus'] = ($state['players'][$playerIndex]['diceBonus'] ?? 0) + $bonusGain;
        $roll = $state['players'][$playerIndex]['diceRoll'];
        $effective = min(12, max(2, $roll['total'] + $state['players'][$playerIndex]['diceBonus']));
        $state['players'][$playerIndex]['diceRoll']['effective'] = $effective;
        $state['lastGreed'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'cardId' => $cardId, 'effective' => $effective];
        $state['lastGreedAcks'] = [0, 0];
        $state['log'][] = $state['players'][$playerIndex]['username'] . " used Mega-Greed! Discarded $discardCount cards (+$bonusGain). Dice → $effective.";
    }

    if (in_array('natural-selection', $kwLower)) {
        $state['naturalSelection'] = true;
        $state['naturalSelectionPlays'] = [0, 0];
        $state['lastNaturalSelection'] = nextEventStamp();
        $state['naturalSelectionAcks'] = [0, 0];
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Natural Selection! Each player may only play one more card this round, face-down.';
        $state['log'][] = '⚠️ Natural Selection: each player may play one card this round, face-down!';
    }

    if (in_array('tutor', $kwLower)) {
        $threshold = max((int)$card['power'] - 1000, 0);
        $wantType  = strtolower($card['type'] ?? '');
        $matches   = [];
        foreach ($state['players'][$playerIndex]['deckIds'] as $did) {
            $dc = $engine->getCard((int)$did);
            if (strtolower($dc['type'] ?? '') === $wantType && (int)$dc['power'] <= $threshold) {
                $matches[] = (int)$did;
            }
        }
        enqueuePendingEffects($state, [[
            'type'          => 'tutor_search',
            'playerIndex'   => $playerIndex,
            'cardType'      => $wantType,
            'powerThreshold' => $threshold,
            'matches'       => $matches,
            // The player's own deckIds are normally redacted to nulls for the client (you can't
            // normally see your deck's contents) — Tutor needs a real snapshot so "view all" works.
            'deckIds'       => array_values(array_map('intval', $state['players'][$playerIndex]['deckIds'])),
        ]]);
        $state['log'][] = $state['players'][$playerIndex]['username'] . ' uses Tutor — searching their deck.';
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
    maybeStartMainPhase($state, $engine);
    return null;
}

function maybeStartMainPhase(array &$state, GameEngine $engine): void
{
    $playerIndex = $state['turn'];
    if (!$state['players'][$playerIndex]['startOfRoundUsed']) return;

    // Draw cards for this player up to their effective roll, then start their main phase
    $roll = $state['players'][$playerIndex]['diceRoll'];
    if ($roll) {
        $effective = (int)$roll['effective'];
        $current   = count($state['players'][$playerIndex]['handIds']);
        $toDraw    = max(0, $effective - $current);
        if ($toDraw > 0) {
            $drawnNow = $engine->drawCards($state, $playerIndex, $toDraw);
            if ($drawnNow > 0) {
                $state['log'][] = $state['players'][$playerIndex]['username'] . " drew $drawnNow card(s).";
            }
        }
    }

    $state['phase'] = 'main_phase';
    // Turn stays with the current player — they go right into their main phase
    $state['log'][] = 'Main phase begins. ' . $state['players'][$playerIndex]['username'] . ' goes first.';
}

function handleReapFarm(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';

    $p = &$state['players'][$playerIndex];
    if (empty($p['farmIds'])) return 'Farm is empty';

    $reaped = $p['farmIds'];
    $p['farmIds'] = [];
    foreach ($reaped as $rId) {
        $p['handIds'][] = $rId;
    }
    $state['log'][] = $p['username'] . ' reaped the farm (' . count($reaped) . ' card(s)).';
    return null;
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

    addCardPlayAnim($state, $playerIndex, $faceDown ? 0 : $cardId, $slot, $faceDown ? 'facedown' : 'play');
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
        // Poverty: evolving FROM this card (the current top) requires discarding a card from hand
        if ($engine->hasKeyword((int)$currentTop['id'], 'poverty')) {
            $discardId = (int)($params['discardId'] ?? 0);
            if (!$discardId || !in_array($discardId, $p['handIds'])) {
                return 'Poverty: must provide a card to discard from hand when evolving';
            }
            $engine->discardFromHand($state, $playerIndex, $discardId);
            pushDiscardAnim($state, $discardId, $playerIndex, 'poverty');
            checkAndApplyEqualExchange($state, $playerIndex, $discardId, $engine);
            $state['log'][] = $p['username'] . ' discarded a card (Poverty).';
        }
    }

    if ($state['naturalSelection']) {
        $state['naturalSelectionPlays'][$playerIndex]++;
    }

    $evolveAnimType = 'evolve';
    if ($faceDown) {
        $evolveAnimType = 'evolve_facedown';
    } elseif (!empty($newCard) && !empty($oldCard) && (int)$newCard['power'] < (int)$oldCard['power']) {
        $evolveAnimType = 'devolve';
    }
    addCardPlayAnim($state, $playerIndex, $faceDown ? 0 : $cardId, $slot, $evolveAnimType);
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
        case 'trump-card': {
            if (!$cardId || !in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
            if (!$engine->hasKeyword($cardId, 'trump-card')) return 'Card does not have Trump Card';
            $p = &$state['players'][$playerIndex];
            if (count($p['handIds']) > 5) return 'Trump Card requires 5 or fewer cards in hand';

            $negatedEffects = [];
            if ($state['naturalSelection'] ?? false) {
                $negatedEffects[] = 'natural-selection';
                $state['naturalSelection'] = false;
                $state['naturalSelectionPlays'] = [0, 0];
            }

            if (!isset($state['communismNegatedIds']) || !is_array($state['communismNegatedIds'])) {
                $state['communismNegatedIds'] = [null, null];
            }
            $negatedPrizes = [];
            foreach ([0, 1] as $pIdx) {
                $bottom = $state['players'][$pIdx]['prizeIds'][0] ?? null;
                if ($bottom && $engine->hasKeyword((int)$bottom, 'communism') && $state['communismNegatedIds'][$pIdx] !== (int)$bottom) {
                    $state['communismNegatedIds'][$pIdx] = (int)$bottom;
                    $negatedPrizes[] = ['playerIndex' => $pIdx, 'cardId' => (int)$bottom];
                }
            }

            $engine->discardFromHand($state, $playerIndex, $cardId);
            pushDiscardAnim($state, $cardId, $playerIndex, 'trump_card');

            $state['log'][] = $p['username'] . ' used Trump Card!';
            foreach ($negatedEffects as $eff) {
                $state['log'][] = 'Trump Card negated ' . $eff . '.';
            }
            foreach ($negatedPrizes as $np) {
                $state['log'][] = "Trump Card hid " . $state['players'][$np['playerIndex']]['username'] . "'s prize card.";
            }

            $state['lastTrumpCard'] = [
                'ts' => nextEventStamp(),
                'playerIndex' => $playerIndex,
                'cardId' => $cardId,
                'negatedEffects' => $negatedEffects,
                'negatedPrizes' => $negatedPrizes,
            ];
            $state['lastTrumpCardAcks'] = [0, 0];
            return null;
        }
        case 'farming': {
            if (!$cardId || !in_array($cardId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
            if (!$engine->hasKeyword($cardId, 'farming')) return 'Card does not have Farming';
            $p = &$state['players'][$playerIndex];

            // Duplicate card IDs are valid (discarding 2 copies of the same card) — validate
            // against a working copy of the hand so we don't over-count availability.
            $extraIds = array_values(array_map('intval', $params['extraDiscardIds'] ?? []));
            $workingHand = $p['handIds'];
            $farmIdx = array_search($cardId, $workingHand);
            if ($farmIdx === false) return 'Card not in hand';
            array_splice($workingHand, $farmIdx, 1);
            foreach ($extraIds as $eid) {
                $idx = array_search($eid, $workingHand);
                if ($idx === false) return 'Invalid discard selection';
                array_splice($workingHand, $idx, 1);
            }

            $engine->discardFromHand($state, $playerIndex, $cardId);
            pushDiscardAnim($state, $cardId, $playerIndex, 'farming');
            checkAndApplyEqualExchange($state, $playerIndex, $cardId, $engine);
            foreach ($extraIds as $eid) {
                $engine->discardFromHand($state, $playerIndex, $eid);
                pushDiscardAnim($state, $eid, $playerIndex, 'farming');
                checkAndApplyEqualExchange($state, $playerIndex, $eid, $engine);
            }

            $totalDiscarded = 1 + count($extraIds);
            $grows = intdiv($totalDiscarded, 2);
            $state['log'][] = $p['username'] . ' used Farming! Discarded ' . $totalDiscarded . ' card(s).';

            if ($grows > 0) {
                $grown = $engine->growFarm($state, $playerIndex, $grows);
                if ($grown > 0) {
                    $state['log'][] = $p['username'] . "'s farm grew by $grown.";
                    $state['lastFarmGrow'] = ['ts' => nextEventStamp(), 'grows' => [['playerIndex' => $playerIndex, 'amount' => $grown]]];
                    $state['lastFarmGrowAcks'] = [0, 0];
                }
            } else {
                $state['log'][] = $p['username'] . ' did not grow the farm.';
            }
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
            // Cultism animation notification
            $cultismCardId = null;
            foreach ($p['graveyardIds'] as $cid) {
                if ($engine->hasKeyword((int)$cid, 'Cultism')) { $cultismCardId = (int)$cid; break; }
            }
            $state['lastCultism'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'selectedIds' => $selectedIds, 'cultismCardId' => $cultismCardId];
            $state['lastCultismAcks'] = [0, 0];
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
        case 'watch-quick': {
            $p = &$state['players'][$playerIndex];
            // Watch Quick card must be in graveyard
            $watchQuickCardId = null;
            foreach ($p['graveyardIds'] as $cid) {
                if ($engine->hasKeyword((int)$cid, 'watch-quick')) { $watchQuickCardId = (int)$cid; break; }
            }
            if ($watchQuickCardId === null) return 'No Watch Quick card in graveyard';
            // Find all face-down cards on the field
            $faceDownTargets = [];
            foreach ([0, 1] as $pIdx) {
                foreach (['primary', 'left', 'right'] as $s) {
                    $stack = $state['players'][$pIdx]['field'][$s] ?? [];
                    if (!empty($stack)) {
                        $top = end($stack);
                        if (!empty($top['faceDown'])) {
                            $faceDownTargets[] = ['playerIndex' => $pIdx, 'slot' => $s];
                        }
                    }
                }
            }
            if (empty($faceDownTargets)) return 'No face-down cards on the field';
            // Reshuffle the Watch Quick card from graveyard into deck
            $pos = array_search($watchQuickCardId, $p['graveyardIds']);
            if ($pos !== false) array_splice($p['graveyardIds'], $pos, 1);
            $p['deckIds'][] = $watchQuickCardId;
            shuffle($p['deckIds']);
            $state['log'][] = $p['username'] . ' used Watch Quick! Reshuffled the card into their deck.';
            enqueuePendingEffects($state, [['type' => 'watch_quick', 'playerIndex' => $playerIndex, 'cardId' => $watchQuickCardId, 'targets' => $faceDownTargets]]);
            return null;
        }
        case 'exam_guess_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'exam_guess') return 'No Exam guess pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $guess = strtolower((string)($params['guess'] ?? ''));
            if (!in_array($guess, ['rock', 'paper', 'scissors', 'gun'])) return 'Invalid guess';
            $examPlayerIndex = (int)($pending['sourcePlayerIndex'] ?? -1);
            $examCardId = (int)($pending['cardId'] ?? 0);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' guessed ' . ucfirst($guess) . ' (Exam).';
            consumePendingEffect($state);
            enqueuePendingEffects($state, [[
                'type' => 'exam_reveal',
                'playerIndex' => $examPlayerIndex,
                'sourcePlayerIndex' => $playerIndex,
                'cardId' => $examCardId,
                'guess' => $guess,
            ]]);
            return null;
        }
        case 'exam_reveal_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'exam_reveal') return 'No Exam reveal pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $oppIdx = (int)($pending['sourcePlayerIndex'] ?? -1);
            $guess = strtolower((string)($pending['guess'] ?? ''));
            $examCardId = (int)($pending['cardId'] ?? 0);
            $p = &$state['players'][$playerIndex];
            consumePendingEffect($state);
            if (empty($p['deckIds'])) {
                $state['log'][] = 'Exam: no card left to mill.';
                return null;
            }
            $milledId = (int)array_shift($p['deckIds']);
            $p['graveyardIds'][] = $milledId;
            $milledCard = $engine->getCard($milledId);
            $actualType = strtolower($milledCard['type'] ?? '');
            $correct = ($guess === $actualType);
            $state['lastExamMill'] = [
                'ts' => nextEventStamp(),
                'playerIndex' => $playerIndex,
                'opponentIndex' => $oppIdx,
                'cardId' => $examCardId,
                'milledCardId' => $milledId,
                'guess' => $guess,
                'actualType' => $actualType,
                'correct' => $correct,
                'treasure' => $engine->hasKeyword($milledId, 'treasure'),
            ];
            $state['lastExamMillAcks'] = [0, 0];
            $state['log'][] = $p['username'] . "'s top deck card was milled: " . ($milledCard['name'] ?? '?') . '. Guess was ' . ($correct ? 'correct!' : 'wrong.');
            $engine->applyTreasureIfMilled($state, $playerIndex, $milledId);
            if ($correct) {
                $engine->drawCards($state, $oppIdx, 1);
                $state['log'][] = $state['players'][$oppIdx]['username'] . ' drew a card (Exam).';
            } else {
                $oppHandCount = count($state['players'][$oppIdx]['handIds']);
                if ($oppHandCount <= 1) {
                    if ($oppHandCount === 1) {
                        $last = array_pop($state['players'][$oppIdx]['handIds']);
                        $state['players'][$oppIdx]['graveyardIds'][] = $last;
                        pushDiscardAnim($state, (int)$last, $oppIdx, 'exam');
                        $state['log'][] = $state['players'][$oppIdx]['username'] . ' discarded a card (Exam).';
                    }
                } else {
                    enqueuePendingEffects($state, [[
                        'type' => 'exam_discard',
                        'playerIndex' => $oppIdx,
                        'discardCount' => 1,
                        'sourcePlayerIndex' => $playerIndex,
                    ]]);
                    $state['log'][] = $state['players'][$oppIdx]['username'] . ' must choose a card to discard (Exam).';
                }
            }
            return null;
        }
        case 'exam_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'exam_discard') return 'No Exam discard pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $discardIds = array_map('intval', $params['discardIds'] ?? []);
            $required = (int)($pending['discardCount'] ?? 1);
            if (count($discardIds) !== $required) return "Must select $required card(s) to discard";
            foreach ($discardIds as $did) {
                if (!$engine->discardFromHand($state, $playerIndex, $did)) return 'Card not in hand';
                pushDiscardAnim($state, $did, $playerIndex, 'exam');
            }
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' discarded ' . count($discardIds) . ' card(s) (Exam).';
            consumePendingEffect($state);
            return null;
        }
        case 'tutor_search_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'tutor_search') return 'No Tutor search pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $p = &$state['players'][$playerIndex];
            $chosenId = (int)($params['chosenId'] ?? 0);
            $matches  = $pending['matches'] ?? [];
            consumePendingEffect($state);

            if ($chosenId && in_array($chosenId, $matches) && in_array($chosenId, $p['deckIds'])) {
                $dIdx = array_search($chosenId, $p['deckIds']);
                array_splice($p['deckIds'], $dIdx, 1);
                $p['handIds'][] = $chosenId;
                shuffle($p['deckIds']);
                $state['log'][] = $p['username'] . ' found a card with Tutor.';
                // Tutor's found card must be revealed — it's the only way the opponent can verify
                // the type/power condition was actually honored.
                $state['lastTutorReveal'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex, 'foundCardId' => $chosenId];
                $state['lastTutorRevealAcks'] = [0, 0];
            } else {
                shuffle($p['deckIds']);
                $state['log'][] = $p['username'] . ' failed to find a card with Tutor.';
                $state['lastTutorFail'] = ['ts' => nextEventStamp(), 'playerIndex' => $playerIndex];
                $state['lastTutorFailAcks'] = [0, 0];
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
                $revealTarget = $params['revealSlot'] ?? null;
                if ($revealTarget) {
                    $field = &$state['players'][$playerIndex]['field'];
                    $rSlot = $revealTarget;
                    if (!empty($field[$rSlot])) {
                        $topIdx = count($field[$rSlot]) - 1;
                        if ($state['players'][$playerIndex]['field'][$rSlot][$topIdx]['faceDown']) {
                            // Check for sus
                            $susCardId = null;
                            foreach ($state['players'][$playerIndex]['handIds'] as $hid) {
                                if ($engine->hasKeyword((int)$hid, 'sus')) { $susCardId = (int)$hid; break; }
                            }
                            if ($susCardId) {
                                consumePendingEffect($state);
                                enqueuePendingEffects($state, [[
                                    'type' => 'sus_respond',
                                    'playerIndex' => $playerIndex,
                                    'susCardId' => $susCardId,
                                    'revealPlayerIndex' => $playerIndex,
                                    'revealSlot' => $rSlot,
                                    'revealer' => 'rizz',
                                ]]);
                                $state['log'][] = $state['players'][$playerIndex]['username'] . ' has Sus — may intercept the reveal!';
                                return null;
                            }
                            $state['players'][$playerIndex]['field'][$rSlot][$topIdx]['faceDown'] = false;
                            $revealedId = (int)$state['players'][$playerIndex]['field'][$rSlot][$topIdx]['cardId'];
                            $state['lastWatchQuickReveal'] = ['ts' => nextEventStamp(), 'revealedCardId' => $revealedId, 'revealedPlayerIndex' => $playerIndex, 'revealedSlot' => $rSlot];
                            $state['lastWatchQuickRevealAcks'] = [0, 0];
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
                    // Occupied slot = evolve (stack on top); empty slot = fresh play.
                    $faceDown      = false;
                    $knownFaceDown = false;
                    $isEvolve      = false;
                    $prevTopId     = null;
                    if (!empty($p['field'][$slot])) {
                        $slotTop = end($p['field'][$slot]);
                        if (!$slotTop['faceDown']) {
                            // Monarchy cannot be evolved — skip placement.
                            if ($engine->hasKeyword($slotTop['cardId'], 'monarchy')) {
                                consumePendingEffect($state);
                                return null;
                            }
                            // Wizard: evolution comes in face-down, but opponent already saw it.
                            if ($engine->hasKeyword($slotTop['cardId'], 'wizard')) {
                                $faceDown      = true;
                                $knownFaceDown = true;
                            }
                            $isEvolve  = true;
                            $prevTopId = (int)$slotTop['cardId'];
                        }
                    }
                    $entry = ['cardId' => $zombieId, 'faceDown' => $faceDown];
                    if ($knownFaceDown) $entry['knownFaceDown'] = true;
                    $p['field'][$slot][] = $entry;
                    $engine->recalcDemocracy($state, $playerIndex);
                    $zombieCard = $engine->getCard($zombieId);
                    $zombieName = $zombieCard ? $zombieCard['name'] : 'Zombie';
                    if ($knownFaceDown) {
                        $state['log'][] = $p['username'] . ' played ' . $zombieName . ' face-down over Wizard (Necromancy).';
                    } else {
                        $state['log'][] = $p['username'] . ' played a Zombie from graveyard (Necromancy).';
                    }
                    if (!$faceDown) {
                        if ($isEvolve && $prevTopId !== null) {
                            $engine->applyWhenEvolves($state, $playerIndex, $slot, $zombieId, $prevTopId);
                        }
                        $newPending = $engine->applyWhenPlayed($state, $playerIndex, $slot, $zombieId);
                        if ($newPending) enqueuePendingEffects($state, $newPending);
                    }
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
                                addCardPlayAnim($state, $playerIndex, $returnCardId, $s, 'return');
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
        case 'sinful_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'sinful_discard') return 'No Sinful discard pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';

            $p = &$state['players'][$playerIndex];
            $handCount = count($p['handIds']);
            $discardCount = (int)($pending['discardCount'] ?? 1);
            if ($handCount <= $discardCount) {
                $discardIds = $p['handIds'];
            } else {
                $discardIds = array_values(array_unique(array_map('intval', $params['discardIds'] ?? [])));
                if (count($discardIds) !== $discardCount) return 'Choose exactly ' . $discardCount . ' cards to discard';
            }

            $discarded = 0;
            foreach ($discardIds as $dId) {
                if ($engine->discardFromHand($state, $playerIndex, (int)$dId)) {
                    pushDiscardAnim($state, (int)$dId, $playerIndex, 'sinful');
                    $discarded++;
                }
            }
            if ($discarded < min($discardCount, $handCount)) return 'Invalid discard selection';

            $state['log'][] = $p['username'] . ' discarded ' . $discarded . ' card(s) (Sinful).';
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
        case 'watch_quick_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'watch_quick') return 'No Watch Quick pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $targetPlayerIndex = (int)($params['targetPlayerIndex'] ?? -1);
            $targetSlot = $params['targetSlot'] ?? '';
            if ($targetPlayerIndex >= 0 && in_array($targetSlot, ['primary', 'left', 'right'])) {
                $targetStack = &$state['players'][$targetPlayerIndex]['field'][$targetSlot];
                if (!empty($targetStack)) {
                    $topIdx = count($targetStack) - 1;
                    if (!empty($targetStack[$topIdx]['faceDown'])) {
                        // Check for sus on the targeted player
                        $susCardId = null;
                        foreach ($state['players'][$targetPlayerIndex]['handIds'] as $hid) {
                            if ($engine->hasKeyword((int)$hid, 'sus')) { $susCardId = (int)$hid; break; }
                        }
                        if ($susCardId) {
                            consumePendingEffect($state);
                            enqueuePendingEffects($state, [[
                                'type' => 'sus_respond',
                                'playerIndex' => $targetPlayerIndex,
                                'susCardId' => $susCardId,
                                'revealPlayerIndex' => $targetPlayerIndex,
                                'revealSlot' => $targetSlot,
                                'revealer' => 'watch_quick',
                            ]]);
                            $state['log'][] = $state['players'][$targetPlayerIndex]['username'] . ' has Sus — may intercept!';
                            return null;
                        }
                        $targetStack[$topIdx]['faceDown'] = false;
                        $revealedId = (int)$targetStack[$topIdx]['cardId'];
                        $state['lastWatchQuickReveal'] = ['ts' => nextEventStamp(), 'revealedCardId' => $revealedId, 'revealedPlayerIndex' => $targetPlayerIndex, 'revealedSlot' => $targetSlot];
                        $state['lastWatchQuickRevealAcks'] = [0, 0];
                        $revCard = $engine->getCard($revealedId);
                        $state['log'][] = $state['players'][$playerIndex]['username'] . ' revealed ' . ($revCard['name'] ?? '?') . ' (Watch Quick)!';
                    }
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'sus_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'sus_respond') return 'No Sus pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $useSus = !empty($params['useSus']);
            $susCardId = (int)($pending['susCardId'] ?? 0);
            if ($useSus && $susCardId && in_array($susCardId, $state['players'][$playerIndex]['handIds'])) {
                $engine->discardFromHand($state, $playerIndex, $susCardId);
                pushDiscardAnim($state, $susCardId, $playerIndex, 'sus');
                $state['lastWatchQuickReveal'] = ['ts' => nextEventStamp(), 'revealedCardId' => $susCardId, 'revealedPlayerIndex' => $playerIndex, 'revealedSlot' => null, 'isSus' => true];
                $state['lastWatchQuickRevealAcks'] = [0, 0];
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' revealed Sus from hand — face-down card protected!';
            } else {
                // Normal reveal of the original target
                $revealSlot = $pending['revealSlot'] ?? null;
                $revealPlayerIndex = (int)($pending['revealPlayerIndex'] ?? $playerIndex);
                if ($revealSlot && !empty($state['players'][$revealPlayerIndex]['field'][$revealSlot])) {
                    $stack = &$state['players'][$revealPlayerIndex]['field'][$revealSlot];
                    $topIdx = count($stack) - 1;
                    if (!empty($stack[$topIdx]['faceDown'])) {
                        $stack[$topIdx]['faceDown'] = false;
                        $revealedId = (int)$stack[$topIdx]['cardId'];
                        $state['lastWatchQuickReveal'] = ['ts' => nextEventStamp(), 'revealedCardId' => $revealedId, 'revealedPlayerIndex' => $revealPlayerIndex, 'revealedSlot' => $revealSlot];
                        $state['lastWatchQuickRevealAcks'] = [0, 0];
                        $revCard = $engine->getCard($revealedId);
                        $state['log'][] = $state['players'][$revealPlayerIndex]['username'] . "'s face-down card was revealed: " . ($revCard['name'] ?? '?') . '.';
                    }
                }
            }
            consumePendingEffect($state);
            return null;
        }
        case 'infernoid_purge_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'infernoid_purge') return 'No Infernoid Purge pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $purgeCardId = (int)($params['purgeCardId'] ?? 0);
            $fromPlayerIndex = isset($params['fromPlayerIndex']) ? (int)$params['fromPlayerIndex'] : null;
            if ($purgeCardId) {
                $found = false;
                $searchOrder = ($fromPlayerIndex !== null && in_array($fromPlayerIndex, [0, 1]))
                    ? [$fromPlayerIndex]
                    : [0, 1];
                foreach ($searchOrder as $pIdx) {
                    $gIdx = array_search($purgeCardId, $state['players'][$pIdx]['graveyardIds']);
                    if ($gIdx !== false) {
                        array_splice($state['players'][$pIdx]['graveyardIds'], $gIdx, 1);
                        $state['players'][$pIdx]['purgedIds'][] = $purgeCardId;
                        $purgedCard = $engine->getCard($purgeCardId);
                        $state['log'][] = $state['players'][$playerIndex]['username'] . ' purged ' . ($purgedCard['name'] ?? '?') . ' (Infernoid)!';
                        $found = true;
                        break;
                    }
                }
                if (!$found) return 'Card not in any graveyard';
            } else {
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' skipped Infernoid purge.';
            }
            consumePendingEffect($state);
            return null;
        }
        case 'poverty_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'poverty_discard') return 'No Poverty pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $discardId = (int)($params['discardId'] ?? 0);
            $skip = !empty($params['skip']);
            if ($skip) {
                // Return the poverty card to hand, undo the evolve
                $pSlot = $pending['slot'] ?? 'primary';
                $pCardId = (int)($pending['cardId'] ?? 0);
                $stack = &$state['players'][$playerIndex]['field'][$pSlot];
                if (!empty($stack)) {
                    $topEntry = end($stack);
                    if ((int)($topEntry['cardId'] ?? 0) === $pCardId) {
                        array_pop($stack);
                        $state['players'][$playerIndex]['handIds'][] = $pCardId;
                        $engine->recalcDemocracy($state, $playerIndex);
                        $state['log'][] = $state['players'][$playerIndex]['username'] . ' cancelled evolution (Poverty) — card returned to hand.';
                    }
                }
            } else {
                if (!$discardId || !in_array($discardId, $state['players'][$playerIndex]['handIds'])) {
                    return 'Must discard a card from hand or skip';
                }
                $engine->discardFromHand($state, $playerIndex, $discardId);
                pushDiscardAnim($state, $discardId, $playerIndex, 'poverty');
                checkAndApplyEqualExchange($state, $playerIndex, $discardId, $engine);
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' discarded a card (Poverty).';
            }
            consumePendingEffect($state);
            return null;
        }
        case 'equal_exchange_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'equal_exchange') return 'No Equal Exchange pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $discardId = (int)($params['discardId'] ?? 0);
            $handIds = $state['players'][$playerIndex]['handIds'];
            if (!$discardId && count($handIds) === 1) $discardId = (int)$handIds[0];
            if (!$discardId || !in_array($discardId, $handIds)) return 'Must discard a card from hand';
            $engine->discardFromHand($state, $playerIndex, $discardId);
            pushDiscardAnim($state, $discardId, $playerIndex, 'equal_exchange');
            checkAndApplyEqualExchange($state, $playerIndex, $discardId, $engine);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' discarded a card (Equal Exchange).';
            consumePendingEffect($state);
            return null;
        }
        case 'just_ok_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'just_ok') return 'No Just-OK pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            // Card was already drawn when the effect was enqueued; now discard a chosen card
            $discardId = (int)($params['discardId'] ?? 0);
            if ($discardId && in_array($discardId, $state['players'][$playerIndex]['handIds'])) {
                $engine->discardFromHand($state, $playerIndex, $discardId);
                pushDiscardAnim($state, $discardId, $playerIndex, 'just_ok');
                checkAndApplyEqualExchange($state, $playerIndex, $discardId, $engine);
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Just-OK: drew and discarded.';
            } else if (!empty($state['players'][$playerIndex]['handIds'])) {
                return 'Must choose a card to discard';
            } else {
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Just-OK: drew a card (no card to discard).';
            }
            consumePendingEffect($state);
            return null;
        }
        case 'mic_pass_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'mic_pass') return 'No Mic Pass pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $reshuffleId = (int)($params['reshuffleId'] ?? 0);
            $sameTypeCards = $pending['sameTypeCards'] ?? [];
            if (!$reshuffleId || !in_array($reshuffleId, $sameTypeCards)) return 'Invalid card selection';
            if (!in_array($reshuffleId, $state['players'][$playerIndex]['graveyardIds'])) return 'Card not in graveyard';
            $gIdx = array_search($reshuffleId, $state['players'][$playerIndex]['graveyardIds']);
            array_splice($state['players'][$playerIndex]['graveyardIds'], $gIdx, 1);
            $state['players'][$playerIndex]['deckIds'][] = $reshuffleId;
            shuffle($state['players'][$playerIndex]['deckIds']);
            // Check just-ok: draw immediately so modal shows updated hand
            if ($engine->hasKeyword($reshuffleId, 'just-ok')) {
                $engine->drawCards($state, $playerIndex, 1);
                enqueuePendingEffects($state, [['type' => 'just_ok', 'playerIndex' => $playerIndex, 'cardId' => $reshuffleId]]);
            }
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' reshuffled a card to deck (Mic Pass).';
            $passerId2 = (int)($pending['passerId'] ?? (1 - $playerIndex));
            consumePendingEffect($state);
            // Re-check pass
            $winner = $engine->checkWinRound($state, $passerId2);
            if ($winner === $playerIndex) {
                $state['phase'] = 'main_phase';
                $state['turn']  = $passerId2;
                $state['log'][] = 'Mic Pass countered — main phase resumes.';
            }
            return null;
        }
        case 'teleportation_discard_respond': {
            $pending = getCurrentPendingEffect($state);
            if (!$pending || $pending['type'] !== 'teleportation_discard') return 'No Teleportation discard pending';
            if ((int)($pending['playerIndex'] ?? -1) !== $playerIndex) return 'Not your response';
            $discardIds = array_values(array_unique(array_map('intval', $params['discardIds'] ?? [])));
            $weakType = $pending['weakType'] ?? null;
            // Must discard: 1 weak-type card OR 2 cards of any type
            if (count($discardIds) === 0) return 'Must discard cards for Teleportation cost';
            $isWeakTypeDiscard = count($discardIds) === 1 && $weakType
                && in_array($discardIds[0], $state['players'][$playerIndex]['handIds'])
                && strtolower($engine->getCard($discardIds[0])['type'] ?? '') === $weakType;
            $isTwoCardDiscard = count($discardIds) === 2;
            if (!$isWeakTypeDiscard && !$isTwoCardDiscard) return 'Must discard 1 weak-type OR 2 cards (Teleportation)';
            foreach ($discardIds as $dId) {
                if (!in_array($dId, $state['players'][$playerIndex]['handIds'])) return 'Card not in hand';
                $engine->discardFromHand($state, $playerIndex, $dId);
                pushDiscardAnim($state, $dId, $playerIndex, 'teleportation');
                checkAndApplyEqualExchange($state, $playerIndex, $dId, $engine);
            }
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' paid Teleportation cost (' . count($discardIds) . ' card(s)).';
            $passerId3 = (int)($pending['passerId'] ?? (1 - $playerIndex));
            consumePendingEffect($state);
            // Re-check pass
            $winner = $engine->checkWinRound($state, $passerId3);
            if ($winner === $playerIndex) {
                $state['phase'] = 'main_phase';
                $state['turn']  = $passerId3;
                $state['log'][] = 'Teleportation countered the pass.';
            }
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
                'cardId' => (int)$target['cardId'],
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
        if (!($state['micPassUsedThisPass'] ?? false) && $engine->hasKeyword($myTop['id'], 'mic-pass')) {
            $myType = strtolower((string)($myTop['type'] ?? ''));
            $hasSameType = false;
            foreach ($responder['graveyardIds'] as $gid) {
                $gc = $engine->getCard((int)$gid);
                if (strtolower($gc['type'] ?? '') === $myType) { $hasSameType = true; break; }
            }
            if ($hasSameType) {
                $effects[] = ['keyword' => 'mic-pass', 'slot' => $slot];
            }
        }
    }

    // Check hand for Teleportation (requires primary card on field)
    if (!empty($responder['field']['primary'])) {
        $teleFound = false;
        foreach ($responder['handIds'] ?? [] as $handCardId) {
            if ($engine->hasKeyword((int)$handCardId, 'teleportation')) {
                $effects[] = ['keyword' => 'teleportation', 'slot' => 'hand', 'cardId' => (int)$handCardId];
                $teleFound = true;
                break;
            }
        }
        if (!$teleFound) {
            // Diagnostic: log what keywords hand cards have, to help detect missing DB keyword linkage
            $handKwSummary = [];
            foreach ($responder['handIds'] ?? [] as $hid) {
                $hc = $engine->getCard((int)$hid);
                $kws = implode('/', $hc['keywords'] ?? []);
                $handKwSummary[] = ($hc['name'] ?? '#' . $hid) . ($kws ? "[$kws]" : '[no-kw]');
            }
            if ($handKwSummary) {
                $state['log'][] = '[Debug] No Teleportation in hand. Cards: ' . implode(', ', $handKwSummary);
            }
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

    if ($kind === 'magic_potion') {
        $current = (int)($state['lastMagicPotionRoll']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastMagicPotionAcks']) || !is_array($state['lastMagicPotionAcks'])) {
                $state['lastMagicPotionAcks'] = [0, 0];
            }
            $state['lastMagicPotionAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'discard_anims') {
        // stamp = max discard anim ID the client has processed
        if (!isset($state['discardAnimAcks']) || !is_array($state['discardAnimAcks'])) {
            $state['discardAnimAcks'] = [0, 0];
        }
        $state['discardAnimAcks'][$playerIndex] = max((int)($state['discardAnimAcks'][$playerIndex] ?? 0), $stamp);
        return null;
    }

    if ($kind === 'prize_draw') {
        $current = (int)($state['lastPrizeDraw']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastPrizeDrawAcks']) || !is_array($state['lastPrizeDrawAcks'])) {
                $state['lastPrizeDrawAcks'] = [0, 0];
            }
            $state['lastPrizeDrawAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'greed') {
        $current = (int)($state['lastGreed']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastGreedAcks']) || !is_array($state['lastGreedAcks'])) {
                $state['lastGreedAcks'] = [0, 0];
            }
            $state['lastGreedAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'pass') {
        $current = (int)($state['lastPassTs'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['passAcks']) || !is_array($state['passAcks'])) {
                $state['passAcks'] = [0, 0];
            }
            $state['passAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'card_play_anims') {
        if (!isset($state['cardPlayAnimAcks']) || !is_array($state['cardPlayAnimAcks'])) {
            $state['cardPlayAnimAcks'] = [0, 0];
        }
        $state['cardPlayAnimAcks'][$playerIndex] = max((int)($state['cardPlayAnimAcks'][$playerIndex] ?? 0), $stamp);
        return null;
    }

    if ($kind === 'facism_destroy') {
        $current = (int)($state['lastFacismDestroy']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastFacismDestroyAcks']) || !is_array($state['lastFacismDestroyAcks'])) {
                $state['lastFacismDestroyAcks'] = [0, 0];
            }
            $state['lastFacismDestroyAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'divine_destroy') {
        $current = (int)($state['lastDivineDestroy']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastDivineDestroyAcks']) || !is_array($state['lastDivineDestroyAcks'])) {
                $state['lastDivineDestroyAcks'] = [0, 0];
            }
            $state['lastDivineDestroyAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'suck_destroy') {
        $current = (int)($state['lastSuckDestroy']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastSuckDestroyAcks']) || !is_array($state['lastSuckDestroyAcks'])) $state['lastSuckDestroyAcks'] = [0, 0];
            $state['lastSuckDestroyAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'field_exit') {
        $current = (int)($state['lastFieldExit']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastFieldExitAcks']) || !is_array($state['lastFieldExitAcks'])) $state['lastFieldExitAcks'] = [0, 0];
            $state['lastFieldExitAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'watch_quick_reveal') {
        $current = (int)($state['lastWatchQuickReveal']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastWatchQuickRevealAcks']) || !is_array($state['lastWatchQuickRevealAcks'])) $state['lastWatchQuickRevealAcks'] = [0, 0];
            $state['lastWatchQuickRevealAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'cultism') {
        $current = (int)($state['lastCultism']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastCultismAcks']) || !is_array($state['lastCultismAcks'])) $state['lastCultismAcks'] = [0, 0];
            $state['lastCultismAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'exam_effect') {
        $current = (int)($state['lastExamEffect']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastExamEffectAcks']) || !is_array($state['lastExamEffectAcks'])) $state['lastExamEffectAcks'] = [0, 0];
            $state['lastExamEffectAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'exam_mill') {
        $current = (int)($state['lastExamMill']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastExamMillAcks']) || !is_array($state['lastExamMillAcks'])) $state['lastExamMillAcks'] = [0, 0];
            $state['lastExamMillAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'sinful_effect') {
        $current = (int)($state['lastSinfulEffect']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastSinfulEffectAcks']) || !is_array($state['lastSinfulEffectAcks'])) $state['lastSinfulEffectAcks'] = [0, 0];
            $state['lastSinfulEffectAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'sinful_mill') {
        $current = (int)($state['lastSinfulMill']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastSinfulMillAcks']) || !is_array($state['lastSinfulMillAcks'])) $state['lastSinfulMillAcks'] = [0, 0];
            $state['lastSinfulMillAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'trump_card') {
        $current = (int)($state['lastTrumpCard']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastTrumpCardAcks']) || !is_array($state['lastTrumpCardAcks'])) $state['lastTrumpCardAcks'] = [0, 0];
            $state['lastTrumpCardAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'tutor_reveal') {
        $current = (int)($state['lastTutorReveal']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastTutorRevealAcks']) || !is_array($state['lastTutorRevealAcks'])) $state['lastTutorRevealAcks'] = [0, 0];
            $state['lastTutorRevealAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'tutor_fail') {
        $current = (int)($state['lastTutorFail']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastTutorFailAcks']) || !is_array($state['lastTutorFailAcks'])) $state['lastTutorFailAcks'] = [0, 0];
            $state['lastTutorFailAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    if ($kind === 'farm_grow') {
        $current = (int)($state['lastFarmGrow']['ts'] ?? 0);
        if ($current && $stamp === $current) {
            if (!isset($state['lastFarmGrowAcks']) || !is_array($state['lastFarmGrowAcks'])) $state['lastFarmGrowAcks'] = [0, 0];
            $state['lastFarmGrowAcks'][$playerIndex] = $stamp;
        }
        return null;
    }

    return 'Unknown notification kind';
}

function handleReorderHand(array &$state, int $playerIndex, array $params): ?string
{
    $newOrder = array_map('intval', $params['handIds'] ?? []);
    $p = &$state['players'][$playerIndex];
    $current = $p['handIds'] ?? [];
    $ns = $newOrder; sort($ns);
    $cs = $current; sort($cs);
    if ($ns !== $cs) return 'Card set mismatch';
    $p['handIds'] = array_values($newOrder);
    return null;
}

function handlePass(array &$state, int $playerIndex, array $params, GameEngine $engine): ?string
{
    if ($state['phase'] !== 'main_phase') return 'Not in main phase';
    if ($state['turn'] !== $playerIndex) return 'Not your turn';

    $p = $state['players'][$playerIndex];
    if (empty($p['field']['primary'])) return 'Need a primary card to pass';

    $state['log'][] = $state['players'][$playerIndex]['username'] . ' passes.';
    $state['lastPassTs'] = time();
    $state['lastPassBy'] = $playerIndex;

    $oppIdx = 1 - $playerIndex;
    $opp    = $state['players'][$oppIdx];

    // If opponent has no primary card, give them their start of round first (if not yet done)
    if (empty($opp['field']['primary'])) {
        if (!$state['players'][$oppIdx]['startOfRoundUsed']) {
            $state['phase'] = 'start_of_round';
            $state['turn']  = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . "'s start of round begins.";
        } else {
            $state['turn'] = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . ' has no card — their main phase continues.';
        }
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
    $state['micPassUsedThisPass'] = false;
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
            addCardPlayAnim($state, $playerIndex, $returnedId, $slot, 'return');

            enqueuePendingEffects($state, [[
                'type' => 'mikontalo_discard',
                'playerIndex' => $playerIndex,
                'passerId' => $passerId,
            ]]);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' must discard a card (Mikontalo).';
            return null;
        }
        case 'mic-pass': {
            if ($state['micPassUsedThisPass'] ?? false) return 'Mic Pass already used this pass phase';
            $myTop = $engine->getTopCard($state['players'][$playerIndex]['field'][$slot]);
            if (!$myTop) return 'No card in that slot';
            if (!$engine->hasKeyword($myTop['id'], 'mic-pass')) return 'Card does not have Mic Pass';
            $myType = strtolower((string)($myTop['type'] ?? ''));
            $sameTypeCards = [];
            foreach ($state['players'][$playerIndex]['graveyardIds'] as $gid) {
                $gc = $engine->getCard((int)$gid);
                if (strtolower($gc['type'] ?? '') === $myType) $sameTypeCards[] = (int)$gid;
            }
            if (empty($sameTypeCards)) return 'No same-type cards in graveyard';
            $state['micPassUsedThisPass'] = true;
            if (count($sameTypeCards) === 1) {
                // Auto-reshuffle
                $reshuffleId = $sameTypeCards[0];
                $gIdx = array_search($reshuffleId, $state['players'][$playerIndex]['graveyardIds']);
                if ($gIdx !== false) array_splice($state['players'][$playerIndex]['graveyardIds'], $gIdx, 1);
                $state['players'][$playerIndex]['deckIds'][] = $reshuffleId;
                shuffle($state['players'][$playerIndex]['deckIds']);
                if ($engine->hasKeyword($reshuffleId, 'just-ok')) {
                    enqueuePendingEffects($state, [['type' => 'just_ok', 'playerIndex' => $playerIndex, 'cardId' => $reshuffleId]]);
                }
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' reshuffled a card (Mic Pass).';
            } else {
                enqueuePendingEffects($state, [[
                    'type' => 'mic_pass',
                    'playerIndex' => $playerIndex,
                    'passerId' => $passerId,
                    'cardType' => $myType,
                    'sameTypeCards' => $sameTypeCards,
                ]]);
                $state['log'][] = $state['players'][$playerIndex]['username'] . ' uses Mic Pass — choose a card to reshuffle.';
            }
            return null;
        }
        case 'teleportation': {
            $tCardId = (int)($params['cardId'] ?? 0);
            if (!$tCardId || !in_array($tCardId, $state['players'][$playerIndex]['handIds'])) return 'Teleportation card not in hand';
            if (!$engine->hasKeyword($tCardId, 'teleportation')) return 'Card does not have Teleportation';
            if (empty($state['players'][$playerIndex]['field']['primary'])) return 'No primary card to replace';
            // Remove from hand
            $hIdx = array_search($tCardId, $state['players'][$playerIndex]['handIds']);
            array_splice($state['players'][$playerIndex]['handIds'], $hIdx, 1);
            // Push to primary stack face-up
            $state['players'][$playerIndex]['field']['primary'][] = ['cardId' => $tCardId, 'faceDown' => false];
            addCardPlayAnim($state, $playerIndex, $tCardId, 'primary', 'evolve');
            $engine->recalcDemocracy($state, $playerIndex);
            $tCard = $engine->getCard($tCardId);
            $weakType = WEAK_TYPE[strtolower($tCard['type'] ?? '')] ?? null;
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' used Teleportation!';
            enqueuePendingEffects($state, [[
                'type' => 'teleportation_discard',
                'playerIndex' => $playerIndex,
                'passerId' => $passerId,
                'cardId' => $tCardId,
                'weakType' => $weakType,
            ]]);
            $state['log'][] = $state['players'][$playerIndex]['username'] . ' must discard 1 ' . ucfirst($weakType ?? '?') . ' OR 2 cards (Teleportation cost).';
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
            $state['lastDivineDestroy'] = ['ts' => nextEventStamp(), 'divinePlayerIndex' => $oppIdx, 'faceDownPlayerIndex' => $passerId, 'divineCardId' => (int)$oppTop['id']];
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
        $state['lastDivineDestroy'] = ['ts' => nextEventStamp(), 'divinePlayerIndex' => $passerId, 'faceDownPlayerIndex' => $oppIdx, 'divineCardId' => (int)$passerTop['id']];
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

    // Opponent's primary was emptied by their own [Opponent passes] effect (e.g. Mikontalo
    // returning itself to hand). Treat this exactly like passing into an opponent who never had
    // a primary card to begin with — no one wins the round, opponent just takes their turn.
    if (!$oppTop) {
        if (!$state['players'][$oppIdx]['startOfRoundUsed']) {
            $state['phase'] = 'start_of_round';
            $state['turn']  = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . "'s start of round begins.";
        } else {
            $state['phase'] = 'main_phase';
            $state['turn']  = $oppIdx;
            $state['log'][] = $state['players'][$oppIdx]['username'] . ' has no card — their main phase continues.';
        }
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
    if ($divineWin) {
        $state['lastDivineDestroy'] = ['ts' => nextEventStamp(), 'divinePlayerIndex' => $oppIdx, 'faceDownPlayerIndex' => $passerId, 'divineCardId' => $oppCardId];
    }

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
    $state['lastPrizeDraw'] = ['ts' => nextEventStamp(), 'playerIndex' => $winnerId, 'remaining' => $p['prizeCount']];
    $state['lastPrizeDrawAcks'] = [0, 0];

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
    $state['lastPrizeDraw'] = ['ts' => nextEventStamp(), 'playerIndex' => $winnerId, 'remaining' => $p['prizeCount']];
    $state['lastPrizeDrawAcks'] = [0, 0];
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

    // acknowledgeNotification is small, pure, and idempotent (it only ever stamps an ack array),
    // so on an optimistic-locking conflict (another request — typically the other player's own
    // ack of the same broadcast event — saved first) it's safe to just reload and retry, rather
    // than silently dropping the ack the way a single unchecked write would.
    if ($action === 'acknowledgeNotification') {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $gsRows = $database->query(
                "SELECT id, stateJson, version FROM isBack_gameState WHERE matchId = :matchId AND status = 'active' ORDER BY gameNumber DESC LIMIT 1",
                ['matchId' => ['value' => $matchId, 'type' => \PDO::PARAM_INT]]
            );
            if (!$gsRows) return Database::responseNotFound();
            $gsId    = (int)$gsRows[0]['id'];
            $version = (int)$gsRows[0]['version'];
            $state   = json_decode($gsRows[0]['stateJson'], true);
            ensureNotificationState($state);

            $ackError = handleAcknowledgeNotification($state, $playerIndex, $paramsArr);
            if ($ackError) return Database::responseBadRequest($ackError);

            $result = $database->query(
                'UPDATE isBack_gameState SET stateJson = :json, version = version + 1 WHERE id = :id AND version = :version',
                [
                    'json'    => ['value' => json_encode($state), 'type' => \PDO::PARAM_STR],
                    'id'      => ['value' => $gsId, 'type' => \PDO::PARAM_INT],
                    'version' => ['value' => $version, 'type' => \PDO::PARAM_INT],
                ]
            );
            if ((int)($result['affected_rows'] ?? 0) > 0) {
                return Database::responseSuccess(['ok' => true, 'version' => $version + 1]);
            }
            // Lost the race to a concurrent write — reload fresh state and try again.
        }
        return Database::responseBadRequest('Could not save acknowledgement, please retry');
    }

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
        'useSuck'                  => handleSuck($state, $playerIndex, $paramsArr, $engine),
        'useKeyword'               => handleUseKeyword($state, $playerIndex, $paramsArr, $engine),
        'acknowledgeNotification'  => handleAcknowledgeNotification($state, $playerIndex, $paramsArr),
        'reorderHand'              => handleReorderHand($state, $playerIndex, $paramsArr),
        'playCommunism'            => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'communism']), $engine),
        'useRizz'                  => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'rizz']), $engine),
        'useTrumpCard'             => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'trump-card']), $engine),
        'reapFarm'                 => handleReapFarm($state, $playerIndex, $paramsArr, $engine),
        'activateCultism'          => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'cultism']), $engine),
        'activateWatchQuick'       => handleUseKeyword($state, $playerIndex, array_merge($paramsArr, ['keyword' => 'watch-quick']), $engine),
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