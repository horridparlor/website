// engine.js — Rakuel Is Back client-side game logic
// Mirrors server-side validation; authoritative state comes from server.

const GameEngine = (() => {
  // ─── Type system ─────────────────────────────────────────────────────────────
  // Rock beats Scissors, Scissors beats Paper, Paper beats Rock
  const TYPE_BEATS = { rock: 'scissors', scissors: 'paper', paper: 'rock' };

  function compareTypes(t1, t2) {
    const a = t1.toLowerCase(), b = t2.toLowerCase();
    if (a === b) return 0;          // same type
    if (TYPE_BEATS[a] === b) return 1;   // t1 beats t2
    return -1;                      // t2 beats t1
  }

  function weakTypeFor(cardType) {
    // The type that this card's type beats
    return TYPE_BEATS[cardType.toLowerCase()] || null;
  }

  // ─── Card helpers ─────────────────────────────────────────────────────────────
  function cardHasKeyword(card, kw) {
    if (!card || !card.keywords) return false;
    return card.keywords.some(k => k && k.toLowerCase() === kw.toLowerCase());
  }

  function topOfStack(stack) {
    if (!stack || !stack.length) return null;
    return stack[stack.length - 1];
  }

  // Effective power = base power + Democracy bonus (sum of supporting card powers)
  function getEffectivePower(cardId, playerState, allCardsMap) {
    const card = allCardsMap[cardId];
    if (!card) return 0;
    let power = card.power;
    const field = playerState.field;
    // Check if this card is the top of the primary stack
    const primaryTop = topOfStack(field.primary);
    if (!primaryTop || primaryTop.cardId !== cardId) return power;
    if (!cardHasKeyword(card, 'Democracy')) return power;
    // Add supporting card powers
    const leftTop  = topOfStack(field.left);
    const rightTop = topOfStack(field.right);
    if (leftTop  && !leftTop.faceDown)  { const c = allCardsMap[leftTop.cardId];  if (c) power += c.power; }
    if (rightTop && !rightTop.faceDown) { const c = allCardsMap[rightTop.cardId]; if (c) power += c.power; }
    return power;
  }

  // ─── Field helpers ────────────────────────────────────────────────────────────
  function getFieldSlotTop(playerState, slot) {
    const s = playerState.field[slot];
    return topOfStack(s);
  }

  function hasWizardOnPrimary(playerState, allCardsMap) {
    const top = getFieldSlotTop(playerState, 'primary');
    if (!top || top.faceDown) return false;
    return cardHasKeyword(allCardsMap[top.cardId], 'Wizard');
  }

  function hasAutocracyOnField(targetPlayerState, allCardsMap) {
    // Any card on the field that has Autocracy
    const field = targetPlayerState.field;
    for (const slot of ['primary','left','right']) {
      const top = topOfStack(field[slot]);
      if (top && !top.faceDown && cardHasKeyword(allCardsMap[top.cardId], 'Autocracy')) return true;
    }
    return false;
  }

  // ─── Evolution rules ──────────────────────────────────────────────────────────
  // Returns { canEvolve: bool, mustFaceDown: bool, reason: string }
  function checkCanEvolve(newCardId, slotStack, playerState, allCardsMap, isForce) {
    const newCard = allCardsMap[newCardId];
    if (!newCard) return { canEvolve: false, reason: 'Unknown card' };

    const currentTop = topOfStack(slotStack);
    if (!currentTop) return { canEvolve: false, reason: 'Nothing to evolve' };
    if (currentTop.faceDown) return { canEvolve: false, reason: 'Cannot evolve a face-down card' };

    const currentCard = allCardsMap[currentTop.cardId];
    if (!currentCard) return { canEvolve: false, reason: 'Unknown base card' };

    const isPrimary = slotStack === playerState.field.primary;

    // Wizard: evolve into any card (but must be face-down)
    if (isPrimary && hasWizardOnPrimary(playerState, allCardsMap)) {
      return { canEvolve: true, mustFaceDown: true };
    }

    // Autocracy: any card can devolve INTO a card that has Autocracy
    if (cardHasKeyword(newCard, 'Autocracy')) {
      // Special: any power allowed (devolve), but must still be different card
      return { canEvolve: true, mustFaceDown: false };
    }

    // Monarchy: cannot evolve (the Monarchy card itself cannot be evolved from)
    if (cardHasKeyword(currentCard, 'Monarchy')) {
      return { canEvolve: false, reason: 'Monarchy cannot be evolved' };
    }

    // Sister Virus: can evolve into any little-sister in graveyard (any slot)
    if (cardHasKeyword(currentCard, 'sister-virus')) {
      const graveIds = playerState.graveyardIds || [];
      if (graveIds.includes(newCardId) && cardHasKeyword(newCard, 'little-sister')) {
        return { canEvolve: true, mustFaceDown: false };
      }
    }

    // Normal: new card base power > current card base power
    if (newCard.power > currentCard.power) {
      return { canEvolve: true, mustFaceDown: false };
    }

    // Autocracy on THIS field (any card can devolve into the field target)
    if (hasAutocracyOnField({ field: { primary: slotStack, left: [], right: [] } }, allCardsMap)) {
      return { canEvolve: true, mustFaceDown: false };
    }

    return { canEvolve: false, reason: `New card (${newCard.power}) must have higher base power than ${currentCard.power}` };
  }

  // ─── Can play to a slot? ──────────────────────────────────────────────────────
  // Returns { canPlay: bool, asEvolve: bool, mustFaceDown: bool, reason: string }
  function canPlayCard(state, playerIndex, cardId, slot, faceDown) {
    const player = state.players[playerIndex];
    const allCardsMap = state._cardsMap || {};
    const card = allCardsMap[cardId];
    if (!card) return { canPlay: false, reason: 'Card not found' };

    // Check card is in hand
    if (!player.handIds || !player.handIds.includes(cardId)) {
      return { canPlay: false, reason: 'Card not in hand' };
    }

    // Natural Selection: if active, only 1 more card per player, must be face-down
    if (state.naturalSelection) {
      const plays = state.naturalSelectionPlays || [0, 0];
      if (plays[playerIndex] >= 1) return { canPlay: false, reason: 'Natural Selection: already played one card this round' };
      faceDown = true; // must be face-down
    }

    const field = player.field;
    const slotStack = field[slot] || [];

    if (slot === 'primary') {
      if (slotStack.length === 0) {
        // Play fresh primary card
        return { canPlay: true, asEvolve: false, mustFaceDown: !!faceDown };
      } else {
        // Try evolve
        const ev = checkCanEvolve(cardId, slotStack, player, allCardsMap, false);
        return { canPlay: ev.canEvolve, asEvolve: true, mustFaceDown: ev.mustFaceDown || !!faceDown, reason: ev.reason };
      }
    }

    if (slot === 'left' || slot === 'right') {
      // Must have primary first
      if (field.primary.length === 0) return { canPlay: false, reason: 'Need a primary card first' };
      if (slotStack.length === 0) {
        return { canPlay: true, asEvolve: false, mustFaceDown: !!faceDown };
      } else {
        const ev = checkCanEvolve(cardId, slotStack, player, allCardsMap, false);
        return { canPlay: ev.canEvolve, asEvolve: true, mustFaceDown: ev.mustFaceDown || !!faceDown, reason: ev.reason };
      }
    }

    return { canPlay: false, reason: 'Invalid slot' };
  }

  // ─── Can pass? ────────────────────────────────────────────────────────────────
  function canPass(state, playerIndex) {
    const player = state.players[playerIndex];
    return player.field.primary.length > 0;
  }

  // ─── Evaluate pass result ──────────────────────────────────────────────────────
  // Returns: 'passing_loses' | 'passing_wins' | 'opponent_gets_turn' | 'end_of_round_facedown' | 'no_opponent_card'
  function evaluatePass(state, passingPlayerIndex) {
    const oppIndex = 1 - passingPlayerIndex;
    const passer  = state.players[passingPlayerIndex];
    const opp     = state.players[oppIndex];
    const allCardsMap = state._cardsMap || {};

    const passerPrimTop = topOfStack(passer.field.primary);
    const oppPrimTop    = topOfStack(opp.field.primary);

    if (!passerPrimTop) return 'no_primary'; // cannot happen if canPass() passed

    if (!oppPrimTop) return 'no_opponent_card'; // opponent has no card, pass goes to them

    if (oppPrimTop.faceDown) {
      // Both go to end of round
      return 'end_of_round_facedown';
    }

    // Both face-up: compare
    const passerCard = allCardsMap[passerPrimTop.cardId];
    const oppCard    = allCardsMap[oppPrimTop.cardId];
    if (!passerCard || !oppCard) return 'no_opponent_card';

    const passerPower = getEffectivePower(passerPrimTop.cardId, passer, allCardsMap);
    const oppPower    = getEffectivePower(oppPrimTop.cardId,    opp,    allCardsMap);

    const result = compareFullCards(passerCard, passerPower, oppCard, oppPower);

    if (result === 'p1wins') {
      // Passer's card beats opponent: opponent gets a new main phase turn
      return 'opponent_gets_turn';
    }
    if (result === 'p2wins') {
      return 'passing_loses';
    }
    // Tie in type AND power: passing player loses
    return 'passing_loses';
  }

  // Full card comparison considering Monarchy and Divine
  // Returns 'p1wins' | 'p2wins' | 'tie'
  function compareFullCards(card1, power1, card2, power2) {
    const t1 = card1.type.toLowerCase();
    const t2 = card2.type.toLowerCase();

    // Divine beats face-down (handled separately in evaluatePass for facedown)
    // Monarchy: defeats any WEAK_TYPE with more power
    if (cardHasKeyword(card1, 'Monarchy')) {
      const weak = weakTypeFor(t1);
      if (t2 === weak && power2 > power1) return 'p1wins'; // Monarchy defeats stronger weak type
    }
    if (cardHasKeyword(card2, 'Monarchy')) {
      const weak = weakTypeFor(t2);
      if (t1 === weak && power1 > power2) return 'p2wins';
    }

    const typeResult = compareTypes(t1, t2);
    if (typeResult === 1) return 'p1wins';
    if (typeResult === -1) return 'p2wins';
    // Same type: compare power
    if (power1 > power2) return 'p1wins';
    if (power2 > power1) return 'p2wins';
    return 'tie'; // same type, same power: passing player loses (handled by caller)
  }

  // ─── Opponent passes keywords ─────────────────────────────────────────────────
  // Returns array of { slot, cardId, keyword } for each triggerable [Opponent passes] effect
  function getOpponentPassesKeywords(state, respondingPlayerIndex, allCardsMap) {
    const player = state.players[respondingPlayerIndex];
    const field  = player.field;
    const results = [];
    const oppIndex = 1 - respondingPlayerIndex;
    const opp = state.players[oppIndex];
    const oppPrimTop = topOfStack(opp.field.primary);
    const oppCard = oppPrimTop ? allCardsMap[oppPrimTop.cardId] : null;

    for (const slot of ['primary', 'left', 'right']) {
      const top = topOfStack(field[slot]);
      if (!top) continue;
      const card = allCardsMap[top.cardId];
      if (!card) continue;
      // Face-down cards can still trigger [Opponent passes] (they flip when triggered)
      if (card.keywords) {
        for (const kw of card.keywords) {
          if (!kw) continue;
          const kwLow = kw.toLowerCase();
          if (kwLow === 'facism') {
            // Trigger if opponent passed with WEAK_TYPE ≤5000
            if (oppCard && !oppPrimTop.faceDown) {
              const weak = weakTypeFor(card.type.toLowerCase());
              const oppPower = getEffectivePower(oppPrimTop.cardId, opp, allCardsMap);
              if (oppCard.type.toLowerCase() === weak && oppPower <= 5000) {
                results.push({ slot, cardId: top.cardId, keyword: 'Facism' });
              }
            }
          } else if (kwLow === 'herwood') {
            results.push({ slot, cardId: top.cardId, keyword: 'Herwood' });
          } else if (kwLow === 'mikontalo') {
            results.push({ slot, cardId: top.cardId, keyword: 'Mikontalo' });
          }
        }
      }
    }
    return results;
  }

  // ─── Wanderret / Cougar draw triggers ────────────────────────────────────────
  // Returns how many cards a player would draw when playing a card supporting a given primary
  function getSupportDraws(newCard, primaryCard, allCardsMap) {
    let draws = 0;
    if (!newCard || !primaryCard) return 0;
    // Wanderret: draw when supporting a wanderret
    if (cardHasKeyword(primaryCard, 'Wanderret') && cardHasKeyword(newCard, 'Wanderret')) draws++;
    // Cougar: draw when supporting a card with ≤5000 power
    if (cardHasKeyword(newCard, 'Cougar') && primaryCard.power <= 5000) draws++;
    // Cougar-Magnet: primary has this; if new supporting card is a Cougar, draw extra
    if (cardHasKeyword(primaryCard, 'Cougar-Magnet') && cardHasKeyword(newCard, 'Cougar')) draws++;
    return draws;
  }

  // ─── Replicate check ─────────────────────────────────────────────────────────
  function hasReplicate(cardId, allCardsMap) {
    return cardHasKeyword(allCardsMap[cardId], 'Replicate');
  }

  // ─── Deck builder validation ──────────────────────────────────────────────────
  // Validates a deck array (list of {cardId, quantity, card} objects)
  // Returns { valid: bool, errors: string[] }
  function validateDeck(deckCards, allCardsMap) {
    const errors = [];
    let total = 0;
    const names = new Map(); // name → { count, hasReplicate }
    for (const entry of deckCards) {
      const card = allCardsMap[entry.cardId] || entry.card;
      if (!card) { errors.push(`Unknown card #${entry.cardId}`); continue; }
      const qty = entry.quantity || 1;
      total += qty;
      const prev = names.get(card.name) || { count: 0, hasReplicate: cardHasKeyword(card, 'Replicate') };
      prev.count += qty;
      names.set(card.name, prev);
    }
    for (const [name, info] of names) {
      if (!info.hasReplicate && info.count > 1) {
        errors.push(`"${name}" appears ${info.count} times. Only Replicate cards may have duplicates.`);
      }
    }
    if (total !== 60) errors.push(`Deck has ${total} card${total===1?'':'s'} — must be exactly 60.`);
    return { valid: errors.length === 0, errors };
  }

  // ─── Power formatting ─────────────────────────────────────────────────────────
  function formatPower(p) {
    return String(Number(p)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  // ─── Keyword data ─────────────────────────────────────────────────────────────
  function getKeyword(name, allKeywords) {
    if (!allKeywords) return null;
    return allKeywords.find(k => k.name.toLowerCase() === name.toLowerCase()) || null;
  }

  // ─── Build cards map from array ───────────────────────────────────────────────
  function buildCardsMap(allCards) {
    const map = {};
    for (const c of allCards) map[c.id] = c;
    return map;
  }

  // ─── Check if Communism card can be played from hand ──────────────────────────
  function canPlayCommunism(playerState) {
    return (playerState.prizeCount || 0) > 0;
  }

  // ─── Check if Cultism can be activated from grave ─────────────────────────────
  function canActivateCultism(playerState, allCardsMap) {
    const grave = playerState.graveyardIds || [];
    const littleSisters = grave.filter(id => {
      const c = allCardsMap[id];
      return c && cardHasKeyword(c, 'little-sister');
    });
    return littleSisters.length >= 7;
  }

  // ─── Elder-Slime trigger check ────────────────────────────────────────────────
  function elderSlimeTriggers(newCard, prevTopCard) {
    if (!cardHasKeyword(newCard, 'Elder-Slime')) return false;
    if (!prevTopCard) return false;
    // Trigger only if evolving from a non-elder-slime
    return !cardHasKeyword(prevTopCard, 'Elder-Slime');
  }

  // ─── Sister Virus helpers ─────────────────────────────────────────────────────
  function hasSisterVirusOnField(playerState, allCardsMap) {
    for (const slot of ['primary', 'left', 'right']) {
      const top = topOfStack(playerState.field[slot]);
      if (top && !top.faceDown && cardHasKeyword(allCardsMap[top.cardId], 'sister-virus')) return true;
    }
    return false;
  }

  function getLittleSistersInGrave(playerState, allCardsMap) {
    return (playerState.graveyardIds || []).filter(id => cardHasKeyword(allCardsMap[id], 'little-sister'));
  }

  // ─── Boosted power for a slot (Democracy + future modifiers) ─────────────────
  function computeSlotPower(state, playerIndex, slot, allCardsMap) {
    const field = state.players[playerIndex].field;
    const stack = field[slot] || [];
    const top = stack[stack.length - 1];
    if (!top || top.faceDown) return null;
    const card = allCardsMap[top.cardId];
    if (!card || card.power == null) return null;
    let power = parseInt(card.power) || 0;
    if (cardHasKeyword(card, 'democracy')) {
      for (const s of ['left', 'right']) {
        const suppStack = field[s] || [];
        const suppTop = suppStack[suppStack.length - 1];
        if (suppTop && !suppTop.faceDown) {
          const suppCard = allCardsMap[suppTop.cardId];
          if (suppCard) power += parseInt(suppCard.power) || 0;
        }
      }
    }
    return power;
  }

  // ─── Summary of available actions for current player ─────────────────────────
  function getAvailableActions(state, playerIndex) {
    const player = state.players[playerIndex];
    const allCardsMap = state._cardsMap || {};
    const actions = [];
    const phase = state.phase;
    const isMyTurn = state.turn === playerIndex;

    if (!isMyTurn) return actions;

    if (phase === 'start_of_round') {
      if (!player.diceRoll) actions.push({ type: 'rollDice' });
      else {
        if (!player.startOfRoundUsed) {
          const sorCards = (player.handIds || []).filter(id => {
            const c = allCardsMap[id];
            return c && c.keywords && c.keywords.some(k => k && (k.toLowerCase() === 'greed' || k.toLowerCase() === 'natural-selection'));
          });
          sorCards.forEach(id => actions.push({ type: 'useStartOfRound', cardId: id }));
        }
        actions.push({ type: 'doneStartOfRound' });
      }
    }

    if (phase === 'main_phase') {
      (player.handIds || []).forEach(id => {
        ['primary','left','right'].forEach(slot => {
          const result = canPlayCard(state, playerIndex, id, slot, false);
          if (result.canPlay) {
            actions.push({ type: 'playCard', cardId: id, slot, faceDown: result.mustFaceDown });
            if (!result.mustFaceDown) {
              actions.push({ type: 'playCard', cardId: id, slot, faceDown: true });
            }
          }
        });
        // Communism
        const c = allCardsMap[id];
        if (c && cardHasKeyword(c, 'Communism') && canPlayCommunism(player)) {
          actions.push({ type: 'playCommunism', cardId: id });
        }
        // Rizz
        if (c && cardHasKeyword(c, 'Rizz')) {
          actions.push({ type: 'useRizz', cardId: id });
        }
      });
      // Cultism from grave
      if (canActivateCultism(player, allCardsMap)) actions.push({ type: 'activateCultism' });
      if (canPass(state, playerIndex)) actions.push({ type: 'pass' });
      actions.push({ type: 'surrender' });
    }

    if (phase === 'passing_phase' && !isMyTurn) {
      // Opponent passes keywords
      const kws = getOpponentPassesKeywords(state, playerIndex, allCardsMap);
      kws.forEach(k => actions.push({ type: 'triggerOpponentPasses', ...k }));
      actions.push({ type: 'confirmEndOfRound' });
    }

    return actions;
  }

  // Public API
  return {
    compareTypes,
    weakTypeFor,
    cardHasKeyword,
    topOfStack,
    getEffectivePower,
    canPlayCard,
    canEvolveSlot: (state, playerIndex, cardId, slot) => {
      const player = state.players[playerIndex];
      const slotStack = player.field[slot] || [];
      return checkCanEvolve(cardId, slotStack, player, state._cardsMap || {});
    },
    canPass,
    evaluatePass,
    compareFullCards,
    getOpponentPassesKeywords,
    getSupportDraws,
    hasReplicate,
    validateDeck,
    formatPower,
    getKeyword,
    buildCardsMap,
    canPlayCommunism,
    canActivateCultism,
    elderSlimeTriggers,
    getAvailableActions,
    hasWizardOnPrimary,
    hasSisterVirusOnField,
    getLittleSistersInGrave,
    computeSlotPower,
  };
})();

window.GameEngine = GameEngine;