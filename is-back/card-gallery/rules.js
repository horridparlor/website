// rules.js – Rakuel Is Back rulebook component
// To add content: populate the `content` string and `subtitles` array for each section.
// The TOC regenerates automatically from RULES_SECTIONS.

const RULES_SECTIONS = [
  {
    id: 'win-condition',
    title: 'Win Condition',
    content: `<p>Every card is either Rock, Paper, or Scissors. Rock beats Scissors, Scissors beats Paper, Paper beats Rock. If both cards share the same type, the card with higher power wins the round. If both cards share the same type <em>and</em> the same power, the passing player loses. A round can never end in a tie.</p>
<p>At the start of the game, each player receives 5 prize cards placed face-down. Every time you win a round, you draw one of your prize cards. If you win a round while you have no prize cards remaining, you win the game.</p>`,
    subtitles: [
      {
        id: 'deckout-rule',
        title: '1.1 Deckout Rule',
        content: `<p>There is no lose condition by deckout. If your deck has run out of cards and you need to draw, shuffle your graveyard into a new deck and then draw the required cards.</p>
<p>If your graveyard also has no cards, you cannot form a new deck and will not draw any cards. No penalty is applied.</p>`,
      },
      {
        id: 'alternative-win-conditions',
        title: '1.2 Alternative Win Conditions',
        content: `<p>There are no alternative win conditions. Some keywords allow you to draw prize cards outside of winning a round. If such a keyword triggers when you have no prize cards remaining, nothing happens — it does not count as a win.</p>
<p>The only way to win the game is to win a round while you have no prize cards left.</p>`,
      },
    ],
  },
  {
    id: 'what-is-needed',
    title: 'What Is Needed To Play',
    content: '',
    subtitles: [
      {
        id: 'each-player-needs',
        title: '2.1 Each Player Needs To Have',
        content: `<ul>
  <li>A deck of 60 cards — all cards must be unique, no duplicate copies.</li>
  <li>2 six-sided dice (D6).</li>
</ul>`,
      },
      {
        id: 'needed-one-for-group',
        title: '2.2 Needed One For Group',
        content: `<ul>
  <li>A flat, clean play surface such as a table indoors. Playing outside is not recommended.</li>
  <li>Some paper or a printed playmat can improve the surface and help protect the cards.</li>
</ul>`,
      },
      {
        id: 'also-recommended',
        title: '2.3 Also Recommended',
        content: `<ul>
  <li><strong>Card sleeves</strong> are highly recommended. They protect the cards, make shuffling significantly easier, and prevent marked-card cheating. Standard single-sleeving is ideal.</li>
  <li><strong>Double-sleeving is not recommended</strong> — double-sleeved cards are difficult to shuffle properly.</li>
  <li><strong>Extra D6 dice</strong> are recommended if any players have cards with keywords that grant additional dice rolls.</li>
</ul>`,
      },
    ],
  },
  {
    id: 'game-preparation',
    title: 'Game Preparation',
    content: `<p>Each player shuffles their deck of 60 cards and places it face-down on the table. From each deck, deal 5 cards face-down as prize cards.</p>
<p>Players then determine who goes first. The recommended method is for both players to roll their 2 D6 — whoever rolls the higher total chooses whether they want to go first or second in the first round. There is no penalty or restriction on the player who goes first.</p>
<p>It is important that the starting player is determined by a fully randomized method. Drawing the top card of the deck or playing rock-paper-scissors are not truly random and are not recommended.</p>`,
    subtitles: [],
  },
  {
    id: 'turn-order',
    title: 'Turn Order',
    content: `<p>A round proceeds through up to five phases in order. Both players act within each phase before the next begins.</p>
<p>The player who lost the previous round always goes first in the next round. In the very first round, the starting player is determined during game preparation.</p>`,
    subtitles: [
      {
        id: 'turn-start-of-round',
        title: '4.1 Start of Round',
        content: `<p>Start of Round takes place at the beginning of each player's first main phase turn. Roll your 2 D6, optionally use one <strong>[Start of round]</strong> keyword from hand, then draw up to your rolled total and begin your main phase. The second player does the same after the first player's first turn — unless the round has already ended.</p>`,
      },
      {
        id: 'turn-main-phase',
        title: '4.2 Main Phase',
        content: `<p>Starting from the player going first, each player may play or evolve their primary card, place supporting cards, and use keyword effects. When done, the player passes to the opponent.</p>`,
      },
      {
        id: 'turn-passing-phase',
        title: '4.3 Passing Phase',
        content: `<p>When a player passes, the opponent may respond with <strong>[Opponent passes]</strong> keyword effects. If those effects leave the passing player in a losing or cardless state, the pass is countered and the main phase resumes.</p>`,
      },
      {
        id: 'turn-reveal-phase',
        title: '4.4 Reveal Phase',
        content: `<p>If the non-passing player has a face-down primary card, the round enters the Reveal Phase. Their card is revealed first; if the passing player also has a face-down primary, it is revealed next. The round then proceeds to End of Round.</p>`,
      },
      {
        id: 'turn-end-of-round',
        title: '4.5 End of Round',
        content: `<p>The losing player concedes — either by passing with a losing card or by surrendering. If the winner still has prize cards, they draw one. If they have no prize cards left to draw, they win the game instead.</p>
<p>The losing player goes first in the next round.</p>`,
      },
    ],
  },
  {
    id: 'gameplay-overview',
    title: 'Gameplay Overview',
    content: '',
    subtitles: [
      {
        id: 'go-start-of-round',
        title: '5.1 Start of Round',
        content: `<p>Each player's start of round happens at the beginning of their <em>first</em> main phase turn each round. The player going first takes their start of round at the very start of the round; the second player takes theirs after the first player completes their first turn (if the round has not ended).</p>
<p>When it is your start of round, you roll your 2 D6. The sum determines how many cards you may hold in hand — you draw <em>up to</em> that number, not that many new cards outright.</p>
<ul>
  <li>Example: rolling a 2 and 5 totals 7. If you already have 3 cards in hand, you draw 4 more. If you already have 7 or more, you draw nothing.</li>
</ul>
<p>Before drawing, you may use one card with a <strong>[Start of round]</strong> keyword from your hand. That card is discarded and its effect resolves. Then you draw up to your rolled total and your main phase begins.</p>
<ul>
  <li>These effects commonly alter dice rolls. For example, <em>Greed</em> increases your roll by 2.</li>
  <li>A roll cannot be modified beyond the physical limits of the dice in play. The minimum is the lowest possible roll and the maximum is the highest — 2 and 12 respectively for two D6. If a player has more or fewer dice due to a keyword effect, these limits shift to match the new dice count.</li>
</ul>`,
      },
      {
        id: 'go-main-phase',
        title: '5.2 Main Phase',
        content: `<p>Starting from the player going first this round, each player takes a main phase in turn. During your main phase you may:</p>
<ul>
  <li><strong>Play a card</strong> from your hand as your primary card, or <strong>evolve</strong> by playing a new card on top of your current primary card, forming a stack. The new card must always have higher power than the card beneath it. If the top card of the stack is removed, the card underneath becomes active again.</li>
  <li><strong>Play supporting cards</strong> — one to the left and one to the right of your primary card, for a maximum of 2. Supporting cards can also be evolved the same way (new card must have higher power; if the top is removed, the one below is active again). You may only play supporting cards once you already have a primary card on the field. If your entire primary card stack is removed, any supporting cards on the field are automatically destroyed.</li>
  <li><strong>Use keyword effects</strong> where permitted by the card's text.</li>
</ul>
<p>All cards are played face-up unless a specific effect allows a face-down play. Face-down cards cannot evolve.</p>
<p>Only the primary card determines who wins the round. Supporting cards do not directly affect the outcome unless your cards have keywords that reference them.</p>
<p>You must have a primary card in play to pass. If you have no card to play, you must surrender the round. You may also choose to surrender at any point during your main phase, even if you could play a card.</p>`,
      },
      {
        id: 'go-passing-phase',
        title: '5.3 Passing Phase',
        content: `<p>When you choose to pass, it signals the end of your main phase.</p>
<ul>
  <li>If your opponent has no primary card, the turn simply passes to them.</li>
  <li>If your opponent has a <strong>face-up</strong> primary card that beats yours, you immediately lose the round.</li>
  <li>If both cards share the same type and the same power, you lose the round — ties are broken in favour of the non-passing player.</li>
  <li>If your card beats the opponent's face-up card, they receive a new main phase.</li>
  <li>If your opponent has a <strong>face-down primary card</strong>, the round moves into the Reveal Phase (see section 5.4) instead of ending immediately.</li>
</ul>
<p>When passing against a face-up primary, before the pass resolves the opponent may trigger any <strong>[Opponent passes]</strong> keywords on their field cards (primary and supporting) one at a time in any order they choose.</p>
<ul>
  <li>A face-down field card with such a keyword is flipped face-up when its effect is triggered.</li>
  <li>Your pass is <strong>countered</strong> — and your main phase resumes — if the triggered effects fully remove your entire primary stack, or leave the top card of your primary stack in a losing position.</li>
</ul>`,
      },
      {
        id: 'go-reveal-phase',
        title: '5.4 Reveal Phase',
        content: `<p>When the non-passing player has a face-down primary card, the round enters the Reveal Phase instead of ending immediately.</p>
<ul>
  <li><strong>Step 1 — Non-passing player reveals:</strong> Their face-down primary card is flipped face-up.</li>
  <li><strong>Divine shortcut:</strong> If the revealed card has <strong>Divine</strong> and the passing player still has a face-down primary card, Divine defeats it without further reveal. The passing player's card is never shown.</li>
  <li><strong>Step 2 — Passing player reveals (if applicable):</strong> If the passing player also had a face-down primary card and no Divine shortcut occurred, their card is now revealed.</li>
  <li>The winner is determined from the now face-up cards.</li>
</ul>`,
      },
      {
        id: 'go-end-of-round',
        title: '5.5 End of Round',
        content: `<p>A round ends when a player either passes with a losing card or surrenders. The winning player draws one of their prize cards.</p>
<ul>
  <li>If the winning player has no prize cards remaining, they win the game.</li>
  <li>If a keyword effect allows drawing multiple prize cards at once and fewer than that number remain, the overflow does not count — you do not win from an overflow. The only way to win the game is to have no prize cards left at the moment you would draw one (or more) for winning a round.</li>
</ul>
<p>The losing player goes first in the next round.</p>`,
      },
    ],
  },
  {
    id: 'how-cards-work',
    title: 'How Cards Work',
    content: '',
    subtitles: [
      {
        id: 'identifying-card-types',
        title: '6.1 Identifying Card Types',
        content: `<p>Every card is Rock, Paper, or Scissors. There are three ways to tell them apart, so even colour-blind players can distinguish them:</p>
<ul>
  <li><strong>Background colour</strong> — Rocks are green, Papers are blue, Scissors are orange.</li>
  <li><strong>Printed text and icon</strong> — a small type label and matching icon appear on the bottom centre of the card.</li>
  <li><strong>Power box border</strong> — Rocks have an octagonal border, Papers a rounded border, and Scissors a sharp-edged border.</li>
</ul>`,
      },
      {
        id: 'card-layout',
        title: '6.2 Card Layout',
        content: `<p>The card name is printed at the top. Some cards have alternative printings with different artwork — you can use the name to confirm whether two cards are the same or unique. A card with different art is still mechanically identical to the original; alternate art is purely cosmetic and not intended to mislead players.</p>
<p>Below the name is the card art. Overlaid on the lower portion of the art are the card's keywords (if any) and the power box.</p>
<p>The bottom-left of the card shows the card's unique ID and the bottom-right shows the card's creator. Neither of these affects gameplay.</p>
<p>The back of every card displays the game name <em>Rakuel Is Back</em> and the game's social media handles.</p>`,
      },
      {
        id: 'power',
        title: '6.3 Power',
        content: `<p>Power is always a multiple of 1 000, ranging from 0 000 up to 99 000.</p>
<p>When checking whether you can evolve, only the number printed in the power box is used — base power only. If a keyword effect has increased a card's effective power during the round, that bonus is ignored for evolution purposes. For example, if a card's base power is 3 000 but Democracy has raised it to 5 000, you can still evolve it with any card that has a base power above 3 000.</p>`,
      },
      {
        id: 'keywords-on-cards',
        title: '6.4 Keywords',
        content: `<p>A card can have a maximum of 3 keywords. There are two types:</p>
<ul>
  <li><strong>Title keywords</strong> — displayed with an underline. A title is simply an additional name the card can be referenced by. For example, a card with the title <em>Zombie</em> can be targeted by any effect that refers to Zombies. A card can have at most 1 title keyword.</li>
  <li><strong>Effect keywords</strong> — consist of a name, a tag in brackets that states when the keyword is active, and a description of the effect. Common tags include:
    <ul>
      <li><strong>[Static]</strong> — active at all times while the card is face-up on the field.</li>
      <li><strong>[When played]</strong> — triggers once when the card is played face-up onto the field, either as a primary or a supporting card.</li>
      <li><strong>[Opponent passes]</strong> — a response keyword that can be triggered when this card is on top of your primary stack or a supporting stack when the opponent passes. Uniquely, this tag can also be triggered from a face-down card — the card is revealed when the effect is used.</li>
    </ul>
  </li>
</ul>
<p>To save space, a well-known keyword's description may be omitted from the card if a rarer keyword needs the room. For example, the card <em>Fallen Mantista</em> omits Democracy's description so the less common Necromancy keyword has enough space for its full text.</p>`,
      },
    ],
  },
  {
    id: 'deck-building',
    title: 'Deck Building',
    content: `<p>A deck must contain exactly 60 cards. Uniqueness is determined by name only — no two cards in the deck may share the same name. Two cards with different names are allowed even if they are otherwise mechanically identical.</p>
<p>Some cards have <strong>[Deck building]</strong> tagged keywords that alter these rules for that specific card:</p>
<ul>
  <li>For example, <em>Replicate</em> allows you to include any number of copies of that card in your deck, bypassing the uniqueness restriction.</li>
  <li>Other <strong>[Deck building]</strong> keywords may add extra restrictions, such as limiting which other cards can be included alongside it.</li>
</ul>
<p>Your decklist is hidden knowledge. You are not required to reveal or confirm its contents to your opponent, even if asked directly.</p>`,
    subtitles: [],
  },
  {
    id: 'keyword-interactions',
    title: 'Keyword Interactions',
    content: `<p>Some keywords interact with specific game states in non-obvious ways. The following clarifications cover notable cases.</p>`,
    subtitles: [
      {
        id: 'divine-face-down',
        title: '8.1 Divine and Face-Down Cards',
        content: `<p>Divine is a keyword that allows your card to automatically defeat any face-down primary card — the opponent's card is never revealed and they lose the round.</p>
<ul>
  <li><strong>Face-up Divine vs. face-down primary:</strong> If you pass with a face-up card that has Divine and your opponent has a face-down primary, their card is never revealed. You win the round automatically — no Reveal Phase occurs.</li>
  <li><strong>Both face-down, one player passes:</strong> The round enters the Reveal Phase (see section 5.4). The non-passing player reveals their face-down card first. If it has Divine, the passing player's face-down card is never revealed — Divine defeats it sight unseen and the passing player loses.</li>
  <li><strong>Revealed Divine during Reveal Phase:</strong> If the non-passing player's revealed card has Divine while the passing player still has a face-down primary, the passing player loses immediately. The passing player's card is never shown.</li>
</ul>`,
      },
      {
        id: 'sahkotalo-retrigger',
        title: '8.2 Sähkötalo — What Counts as a Retriggerable Effect',
        content: `<p>Sähkötalo's keyword reads: <em>[When played] You may discard up to 2 cards. If you do, retrigger the effects of up to that many cards supporting this card.</em></p>
<p>Retriggering replays the <strong>play-time</strong> keywords of the chosen supporting cards — specifically those that trigger when a card enters the field:</p>
<ul>
  <li><strong>[When played]</strong> — triggers when a card is played face-up onto the field.</li>
  <li><strong>[When evolves]</strong> — triggers when a card evolves.</li>
  <li><strong>[When supporting]</strong> — triggers when a card is played as a supporting card.</li>
</ul>
<p>The following keyword tags are <strong>not</strong> retriggered, as they are not play-time effects:</p>
<ul>
  <li><strong>[Static]</strong> — a passive effect active while the card is face-up; it is already in effect and does not need to trigger again.</li>
  <li><strong>[Opponent passes]</strong> — a response keyword; it only fires when the opponent passes, not when a card is played.</li>
  <li><strong>[Start of round]</strong> — fires at the start of a round from hand; it cannot be retriggered from the field.</li>
</ul>
<p>Additionally, retriggering does not bypass a keyword's own conditions. If a supporting card's keyword has a requirement — for example, <em>[When supporting] a card with 5 000 or less power</em> — and the current primary card does not meet that condition, the effect does not resolve even when retriggered by Sähkötalo.</p>`,
      },
      {
        id: 'facism-stacks',
        title: '8.3 Facism and Stack Destruction',
        content: `<p>Facism is an <strong>[Opponent passes]</strong> keyword. Its effect reads: <em>if the opponent passes with the weak type at 5 000 or less power, destroy all of that type.</em></p>
<p>When resolving Facism, it checks the <strong>top card of every stack</strong> on the opponent's field — both the primary stack and each supporting stack.</p>
<ul>
  <li><strong>Trigger</strong>: the opponent's active primary card must be the weak type with 5 000 or less power. This is what causes Facism to trigger at all.</li>
  <li><strong>Effect scope</strong>: once triggered, every stack on the opponent's field is checked independently. If the top card of a supporting stack is also the weak type, it is destroyed as well — even if it has more than 5 000 power. The "5 000 or less" condition only applies to the trigger, not to which cards get destroyed.</li>
  <li><strong>Cascade</strong>: when Facism destroys the top card of a stack, the card beneath it is exposed and immediately checked. If it also matches the weak type, it is destroyed too. This repeats down the stack until the newly exposed card does not match or the stack is empty.</li>
</ul>
<p>Example: Anne-Lotte has Facism (weak type: Rock). The opponent passes with a 3 000 Rock as their primary. Facism triggers. Their supporting stack also has a Rock on top — it is destroyed. The card beneath that Rock is also a Rock — cascade destroys it too. The next card under that is a Paper — cascade stops.</p>`,
      },
      {
        id: 'herwood-devolve',
        title: '8.4 Herwood — Failed Devolve and Hidden Information',
        content: `<p>Herwood's keyword reads: <em>[Opponent passes] Look at the top 3 cards of your deck, and devolve this into 1 of them. If you do, add the other 2 to your hand.</em></p>
<ul>
  <li><strong>If you cannot or do not devolve</strong>: all 3 cards are returned to the top of your deck in the same order they were in. Neither card goes to hand.</li>
  <li><strong>Choosing to fail</strong>: the top 3 cards of your deck are hidden information — your opponent cannot see them. You are therefore permitted to look at the cards and declare that none of them can be devolved into, returning them in order, even if one of them actually could be used. Your opponent has no way to verify this.</li>
</ul>`,
      },
      {
        id: 'pass-turn-effects-losing-state',
        title: '8.5 Pass-Turn Effects and the Losing-State Re-evaluation',
        content: `<p>When a player passes while losing, the round would normally resolve against them. However, if the opponent activates an <strong>[Opponent passes]</strong> effect, the game state is re-evaluated after that effect fully resolves. If the passer is still in a losing state, their turn is restored and they may continue playing. Even a failed effect is enough to trigger this re-evaluation.</p>
<p>If the passer is in a winning state when they pass, the pass finalizes regardless of whether any pass-turn effects are used.</p>
<p><strong>Example:</strong> You pass while losing. Your opponent has Herwood, whose keyword reads: <em>[Opponent passes] Look at the top 3 cards of your deck, and devolve this into 1 of them. If you do, add the other 2 into your hand.</em> The opponent activates Herwood but the top 3 cards offer nothing to devolve into, so they return the cards and declare failure. The game re-evaluates — you are still losing — so your turn is restored.</p>
<p><strong>Preventing infinite loops:</strong> A pass-turn effect that failed may not be activated again unless the board state has genuinely changed since the last failure. If the passer's turn is restored, and they pass again without having changed anything on the field, the opponent cannot re-use the same effect that already failed — the board is identical and the result would be identical. Only if something actually changed (a card was played, evolved, discarded, etc.) does the effect become available to use again.</p>`,
      },
      {
        id: 'mikontalo-bottom-card',
        title: '8.6 Mikontalo — Removing Your Own Primary Stack During the Passing Phase',
        content: `<p>If the non-passing player uses an <strong>[Opponent passes]</strong> effect that removes their own entire primary stack, the re-evaluation sees them with no primary card while the passing player still has one. The passing player wins the round.</p>
<p>Example: Mikontalo reads <em>[Opponent passes] You may return this card from the field to hand. If you do, discard a card.</em> If Mikontalo is your only primary card and you return it to hand, you are left with no primary card. The re-evaluation finds the passing player still has a card — they win the round.</p>`,
      },
    ],
  },
  {
    id: 'terms-dictionary',
    title: 'Glossary',
    content: `<dl class="rules-glossary">
  <dt>Active card</dt>
  <dd>The top card of a stack; the card currently in play representing that position.</dd>

  <dt>Base power</dt>
  <dd>The power value printed on the card. Keyword effects that raise or lower power during a round do not change the base power. Base power is the only value used when checking whether a card can evolve.</dd>

  <dt>Deck</dt>
  <dd>A player's face-down draw pile of exactly 60 cards.</dd>

  <dt>Deckout</dt>
  <dd>The state where a player's deck has no cards remaining. There is no penalty — the graveyard is shuffled into a new deck when a draw is required.</dd>

  <dt>Destroy</dt>
  <dd>To send a card from the field to the graveyard.</dd>

  <dt>Discard</dt>
  <dd>To send a card from the hand to the graveyard.</dd>

  <dt>Devolve</dt>
  <dd>To evolve with a card that has lower base power than the current active card. Normally not permitted, but certain keywords such as Autocracy allow it. Devolving still counts as evolving in all other respects.</dd>

  <dt>Evolve</dt>
  <dd>To play a new card on top of an existing card in a stack, replacing it as the active card. The new card must have higher base power than the one beneath it.</dd>

  <dt>Face-down</dt>
  <dd>A card placed on the field without revealing its front. Its type, power, and keywords are hidden. Face-down cards cannot evolve.</dd>

  <dt>Face-up</dt>
  <dd>A card placed or flipped on the field with its front visible.</dd>

  <dt>Field</dt>
  <dd>The play area in front of a player, consisting of the primary stack and up to two supporting stacks.</dd>

  <dt>Graveyard</dt>
  <dd>The face-up discard pile beside a player's deck. Cards that are removed from the field or used from hand go here.</dd>

  <dt>Hand</dt>
  <dd>The cards a player holds privately. The size of your hand is not hidden — opponents may ask how many cards you hold.</dd>

  <dt>Pass</dt>
  <dd>To end your main phase and signal that you are ready to resolve the round against the opponent's current field.</dd>

  <dt>Primary card / Primary stack</dt>
  <dd>The main card (or evolved stack of cards) placed in the centre of a player's field. Only the active primary card determines who wins the round.</dd>

  <dt>Prize cards</dt>
  <dd>Five cards dealt face-down from your deck at the start of the game. You draw one each time you win a round. If you have none left to draw when you would win a round, you win the game instead.</dd>

  <dt>Purge</dt>
  <dd>To permanently remove a card from the game entirely. A purged card does not go to the graveyard and cannot be retrieved by any effect. More permanent than discarding.</dd>

  <dt>Reshuffle</dt>
  <dd>To take cards from the graveyard and shuffle them back into the deck.</dd>

  <dt>Retrigger</dt>
  <dd>To replay the effect of a keyword as if the card had just been played. Only play-time keywords can be retriggered — specifically <strong>[When played]</strong>, <strong>[When evolves]</strong>, and <strong>[When supporting]</strong>. Keywords with other tags (<strong>[Static]</strong>, <strong>[Opponent passes]</strong>, <strong>[Start of round]</strong>) cannot be retriggered. A keyword's own conditions still apply when it is retriggered — if the condition is not met, the effect does not resolve.</dd>

  <dt>Round</dt>
  <dd>One full cycle of play, from Start of Round through End of Round.</dd>

  <dt>Stack</dt>
  <dd>A pile of cards formed by evolving. The top card is the active one. If the top card is removed, the card beneath it becomes active again.</dd>

  <dt>Supporting card / Supporting stack</dt>
  <dd>A card (or evolved stack) placed to the left or right of the primary card. A player may have at most two supporting stacks. Supporting cards do not affect who wins the round directly — they only matter through keyword effects.</dd>

  <dt>Surrender</dt>
  <dd>To voluntarily concede the current round. You may surrender at any point during your main phase, even if you have a card you could play.</dd>

  <dt>Title keyword</dt>
  <dd>An underlined extra name on a card (e.g. Zombie, Slime). A card can have at most one title. It allows the card to be referenced by keyword effects that name that title.</dd>

  <dt>Trigger</dt>
  <dd>To activate a keyword effect, resolving its description.</dd>
</dl>`,
    subtitles: [],
  },
  {
    id: 'commonly-asked',
    title: 'Commonly Asked',
    content: `<p>Clarifications for rules that come up frequently or tend to cause confusion.</p>`,
    subtitles: [
      {
        id: 'faq-draw',
        title: '10.1 Do I draw that many cards, or draw up to that many?',
        content: `<p>You draw <strong>up to</strong> the number you rolled — meaning you draw until your hand size equals your roll, not that many new cards on top of what you already hold.</p>
<p>Example: you roll 7 and have 5 cards in hand. You draw 2. If you already have 7 or more, you draw nothing. You never discard down to your roll.</p>`,
      },
      {
        id: 'faq-evolve-power',
        title: '10.2 Can I evolve using power that was raised by a keyword?',
        content: `<p>No. Only <strong>base power</strong> — the number printed on the card — is used to check whether you can evolve. Keyword effects that increase a card's power during the round do not count. You must have a card in hand with higher base power than the active card's base power.</p>`,
      },
      {
        id: 'faq-surrender',
        title: '10.3 Can I surrender even if I have a card I could play?',
        content: `<p>Yes. You may surrender at any point during your main phase, regardless of whether you have playable cards in hand.</p>`,
      },
      {
        id: 'faq-supporting-removed',
        title: '10.4 What happens to my supporting cards if my primary stack is fully removed?',
        content: `<p>They are <strong>automatically discarded</strong>. Supporting cards cannot exist on the field without a primary card. This applies even mid-effect — if an effect removes your entire primary stack, your supporting cards go to the graveyard immediately.</p>`,
      },
      {
        id: 'faq-face-down-evolve',
        title: '10.5 Can a face-down card be evolved?',
        content: `<p>No. A card must be face-up to be evolved. Playing face-down locks the card in place until it is revealed by an effect or the round is resolved.</p>`,
      },
      {
        id: 'faq-deckout',
        title: '10.6 Do I lose if my deck runs out?',
        content: `<p>No. When you need to draw and your deck is empty, shuffle your graveyard into a new deck and draw normally. If your graveyard is also empty, you simply do not draw — no penalty is applied.</p>`,
      },
      {
        id: 'faq-prize-overflow',
        title: '10.7 A keyword lets me draw multiple prize cards, but I only have one left — do I win?',
        content: `<p>No. Prize card overflow does not count as a win. The only way to win is to have <strong>no prize cards left at the moment you would draw one for winning a round</strong>. Extra prize draws from keyword effects that go beyond zero do not trigger a win.</p>`,
      },
      {
        id: 'faq-pass-no-primary',
        title: '10.8 Can I pass with no primary card on the field?',
        content: `<p>No. You must have a primary card in play to pass. If you have nothing to play, you must surrender the round instead.</p>`,
      },
      {
        id: 'faq-both-face-down',
        title: '10.9 Both players have face-down primaries and one passes — who reveals first?',
        content: `<p>The <strong>opponent</strong> (the non-passing player) reveals their face-down primary first. If their revealed card has an <strong>[Opponent passes]</strong> keyword, they may use it — which may avoid the end of round entirely. If not, the passing player's face-down card is then revealed and the round is resolved normally.</p>`,
      },
      {
        id: 'faq-going-first',
        title: '10.10 Is there any disadvantage to going first?',
        content: `<p>No. There is no penalty or restriction on the player who goes first in any round. The starting player for the first round should be determined by a random method — both players rolling dice and the higher roll choosing is recommended.</p>`,
      },
      {
        id: 'faq-devolve-evolve',
        title: '10.11 Does devolving count as evolving?',
        content: `<p>Yes. Devolving — playing a card with lower base power onto a stack — is still considered evolving in all respects. Any effect that triggers on, references, or restricts evolving applies equally to devolving.</p>`,
      },
    ],
  },
];

window.RulesModule = (() => {
  function buildTOC() {
    const items = RULES_SECTIONS.map((s, i) => {
      const subItems = s.subtitles.length
        ? `<ul class="rules-toc-sub">${s.subtitles.map(sub =>
            `<li><a class="rules-toc-sub-link" href="#${sub.id}">${sub.title}</a></li>`
          ).join('')}</ul>`
        : '';
      return `<li class="rules-toc-item">
        <a class="rules-toc-link" href="#${s.id}">${i + 1}. ${s.title}</a>${subItems}
      </li>`;
    }).join('');
    return `
      <nav class="rules-toc" aria-label="Table of Contents">
        <div class="rules-toc-heading">Contents</div>
        <ol class="rules-toc-list">${items}</ol>
      </nav>
    `;
  }

  function buildSection(s, i) {
    const subContent = s.subtitles.map(sub => `
      <div class="rules-subsection" id="${sub.id}">
        <h3 class="rules-subtitle">${sub.title}</h3>
        <div class="rules-subcontent">${sub.content || ''}</div>
      </div>
    `).join('');
    return `
      <section class="rules-section" id="${s.id}">
        <h2 class="rules-section-title"><span class="rules-num">${i + 1}.</span> ${s.title}</h2>
        <div class="rules-body">${s.content || ''}</div>
        ${subContent}
        <a class="rules-back-to-top" href="#rules-top">↑ Back to top</a>
      </section>
    `;
  }

  return {
    init(container) {
      container.innerHTML = `
        <div class="rules-wrap" id="rules-top">
          ${buildTOC()}
          <div class="rules-main">
            ${RULES_SECTIONS.map((s, i) => buildSection(s, i)).join('')}
          </div>
        </div>
      `;
    },
  };
})();