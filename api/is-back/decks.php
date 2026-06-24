<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getDecks(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $deckId = $database->getIntParam('id');

    if ($deckId) {
        $sql = <<<SQL
            SELECT
                d.id, d.name, d.createdAt, d.updatedAt,
                dc.cardId, dc.quantity,
                c.name cardName,
                ct.name cardType,
                c.power,
                k1.name keyword1, k2.name keyword2, k3.name keyword3
            FROM isBack_deck d
            LEFT JOIN isBack_deckCard dc ON dc.deckId = d.id
            LEFT JOIN isBack_card c ON c.id = dc.cardId
            LEFT JOIN isBack_cardType ct ON ct.id = c.cardTypeId
            LEFT JOIN isBack_keyword k1 ON k1.id = c.keywordId
            LEFT JOIN isBack_keyword k2 ON k2.id = c.keyword2Id
            LEFT JOIN isBack_keyword k3 ON k3.id = c.keyword3Id
            WHERE d.id = :deckId AND d.userId = :userId
            ORDER BY c.id ASC
        SQL;
        $rows = $database->query($sql, [
            'deckId' => ['value' => $deckId, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]);
        if (!$rows) return Database::responseNotFound();

        $deck = [
            'id'         => $rows[0]['id'],
            'name'       => $rows[0]['name'],
            'createdAt'  => $rows[0]['createdAt'],
            'updatedAt'  => $rows[0]['updatedAt'],
            'cards'      => [],
        ];
        $total = 0;
        foreach ($rows as $row) {
            if (!$row['cardId']) continue;
            $kws = array_values(array_filter([$row['keyword1'], $row['keyword2'], $row['keyword3']]));
            $deck['cards'][] = [
                'cardId'   => (int)$row['cardId'],
                'quantity' => (int)$row['quantity'],
                'name'     => $row['cardName'],
                'type'     => $row['cardType'],
                'power'    => (int)$row['power'],
                'keywords' => $kws,
            ];
            $total += (int)$row['quantity'];
        }
        $deck['totalCards'] = $total;
        return Database::responseSuccess($deck);
    }

    // List all decks
    $sql = <<<SQL
        SELECT
            d.id, d.name, d.createdAt, d.updatedAt,
            COALESCE(SUM(dc.quantity), 0) AS totalCards
        FROM isBack_deck d
        LEFT JOIN isBack_deckCard dc ON dc.deckId = d.id
        WHERE d.userId = :userId
        GROUP BY d.id
        ORDER BY d.updatedAt DESC
    SQL;
    $decks = $database->query($sql, [
        'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
    ]);
    foreach ($decks as &$deck) {
        $deck['totalCards'] = (int)$deck['totalCards'];
    }
    return Database::responseSuccess(['decks' => $decks]);
}

function manageDeck(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id     = $database->getIntParam('id');
    $action = $database->getStringParam('action', '');

    // ── Create ──────────────────────────────────────────────────────────────
    if (!$id && $action !== 'delete') {
        $name = trim($database->getStringParam('name', 'My Deck'));
        if (!$name) $name = 'My Deck';
        $database->query(
            'INSERT INTO isBack_deck (userId, name) VALUES (:userId, :name)',
            [
                'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
                'name'   => ['value' => $name, 'type' => \PDO::PARAM_STR],
            ]
        );
        $newId = $database->getInsertId();
        return Database::responseSuccess(['id' => $newId, 'name' => $name, 'totalCards' => 0]);
    }

    if (!$id) return Database::responseBadRequest('id required');

    // Verify ownership
    $owns = $database->query(
        'SELECT id FROM isBack_deck WHERE id = :id AND userId = :userId',
        [
            'id'     => ['value' => $id, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );
    if (!$owns) return Database::responseNotFound();

    // ── Delete ───────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $database->query('DELETE FROM isBack_deck WHERE id = :id', [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]);
        return Database::responseSuccess(['deleted' => $id]);
    }

    // ── Rename ───────────────────────────────────────────────────────────────
    if ($action === 'rename') {
        $name = trim($database->getStringParam('name', ''));
        if (!$name) return Database::responseBadRequest('name required');
        $database->query(
            'UPDATE isBack_deck SET name = :name WHERE id = :id',
            [
                'name' => ['value' => $name, 'type' => \PDO::PARAM_STR],
                'id'   => ['value' => $id, 'type' => \PDO::PARAM_INT],
            ]
        );
        return Database::responseSuccess(['id' => $id, 'name' => $name]);
    }

    // ── Copy ─────────────────────────────────────────────────────────────────
    if ($action === 'copy') {
        $deckRow = $database->query('SELECT name FROM isBack_deck WHERE id = :id', [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]);
        $newName = 'Copy of ' . $deckRow[0]['name'];
        $database->query('INSERT INTO isBack_deck (userId, name) VALUES (:userId, :name)', [
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
            'name'   => ['value' => $newName, 'type' => \PDO::PARAM_STR],
        ]);
        $newId = $database->getInsertId();
        $database->query(
            'INSERT INTO isBack_deckCard (deckId, cardId, quantity)
             SELECT :newId, cardId, quantity FROM isBack_deckCard WHERE deckId = :oldId',
            [
                'newId' => ['value' => $newId, 'type' => \PDO::PARAM_INT],
                'oldId' => ['value' => $id, 'type' => \PDO::PARAM_INT],
            ]
        );
        return Database::responseSuccess(['id' => $newId, 'name' => $newName]);
    }

    // ── Save full card list (replaces everything) ─────────────────────────────
    if ($action === 'saveCards') {
        $rawCards = $database->getRawStringParam('cards', []);
        $cards = is_array($rawCards) ? $rawCards : json_decode((string)$rawCards, true);
        if (!is_array($cards)) return Database::responseBadRequest('cards must be an array');

        // Validate: total ≤ 60, no dupes for non-replicate cards
        $total = 0;
        $seen  = [];
        foreach ($cards as $entry) {
            $cardId  = (int)($entry['cardId'] ?? 0);
            $qty     = max(1, (int)($entry['quantity'] ?? 1));
            if (!$cardId) continue;

            // Check replicate
            $cardRow = $database->query(
                'SELECT k1.name kw1, k2.name kw2, k3.name kw3
                 FROM isBack_card c
                 LEFT JOIN isBack_keyword k1 ON k1.id = c.keywordId
                 LEFT JOIN isBack_keyword k2 ON k2.id = c.keyword2Id
                 LEFT JOIN isBack_keyword k3 ON k3.id = c.keyword3Id
                 WHERE c.id = :cardId',
                ['cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT]]
            );
            $hasReplicate = false;
            if ($cardRow) {
                $kws = array_filter([$cardRow[0]['kw1'], $cardRow[0]['kw2'], $cardRow[0]['kw3']]);
                $hasReplicate = in_array('replicate', $kws);
            }

            if (!$hasReplicate && isset($seen[$cardId])) {
                return Database::responseBadRequest('Duplicate non-Replicate card: ' . $cardId);
            }
            $seen[$cardId] = true;
            $total += $qty;
        }
        if ($total > 60) return Database::responseBadRequest('Deck exceeds 60 cards');

        // Replace all cards
        $database->query('DELETE FROM isBack_deckCard WHERE deckId = :id', [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]);
        foreach ($cards as $entry) {
            $cardId = (int)($entry['cardId'] ?? 0);
            $qty    = max(1, (int)($entry['quantity'] ?? 1));
            if (!$cardId) continue;
            $database->query(
                'INSERT INTO isBack_deckCard (deckId, cardId, quantity) VALUES (:deckId, :cardId, :qty)',
                [
                    'deckId' => ['value' => $id, 'type' => \PDO::PARAM_INT],
                    'cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT],
                    'qty'    => ['value' => $qty, 'type' => \PDO::PARAM_INT],
                ]
            );
        }

        // Update name if provided
        $name = trim($database->getStringParam('name', ''));
        if ($name) {
            $database->query('UPDATE isBack_deck SET name = :name, updatedAt = NOW() WHERE id = :id', [
                'name' => ['value' => $name, 'type' => \PDO::PARAM_STR],
                'id'   => ['value' => $id, 'type' => \PDO::PARAM_INT],
            ]);
        } else {
            $database->query('UPDATE isBack_deck SET updatedAt = NOW() WHERE id = :id', [
                'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
            ]);
        }

        return Database::responseSuccess(['id' => $id, 'totalCards' => $total]);
    }

    // ── Add single card ───────────────────────────────────────────────────────
    if ($action === 'add') {
        $cardId = $database->getIntParam('cardId');
        if (!$cardId) return Database::responseBadRequest('cardId required');

        $cardRow = $database->query(
            'SELECT c.id, k1.name kw1, k2.name kw2, k3.name kw3
             FROM isBack_card c
             LEFT JOIN isBack_keyword k1 ON k1.id = c.keywordId
             LEFT JOIN isBack_keyword k2 ON k2.id = c.keyword2Id
             LEFT JOIN isBack_keyword k3 ON k3.id = c.keyword3Id
             WHERE c.id = :cardId',
            ['cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT]]
        );
        if (!$cardRow) return Database::responseBadRequest('Card not found');

        $kws         = array_filter([$cardRow[0]['kw1'], $cardRow[0]['kw2'], $cardRow[0]['kw3']]);
        $hasReplicate = in_array('replicate', $kws);

        $totRow = $database->query(
            'SELECT COALESCE(SUM(quantity),0) total FROM isBack_deckCard WHERE deckId = :id',
            ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
        );
        $total = (int)$totRow[0]['total'];
        if ($total >= 60) return Database::responseBadRequest('Deck is already at 60 cards');

        $existing = $database->query(
            'SELECT id, quantity FROM isBack_deckCard WHERE deckId = :deckId AND cardId = :cardId',
            [
                'deckId' => ['value' => $id, 'type' => \PDO::PARAM_INT],
                'cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT],
            ]
        );

        if ($existing) {
            if (!$hasReplicate) return Database::responseBadRequest('Only Replicate cards can have multiple copies');
            $database->query(
                'UPDATE isBack_deckCard SET quantity = quantity + 1 WHERE id = :id',
                ['id' => ['value' => $existing[0]['id'], 'type' => \PDO::PARAM_INT]]
            );
            $newQty = (int)$existing[0]['quantity'] + 1;
        } else {
            $database->query(
                'INSERT INTO isBack_deckCard (deckId, cardId, quantity) VALUES (:deckId, :cardId, 1)',
                [
                    'deckId' => ['value' => $id, 'type' => \PDO::PARAM_INT],
                    'cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT],
                ]
            );
            $newQty = 1;
        }
        $database->query('UPDATE isBack_deck SET updatedAt = NOW() WHERE id = :id', [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]);
        return Database::responseSuccess(['cardId' => $cardId, 'quantity' => $newQty, 'totalCards' => $total + 1]);
    }

    // ── Remove single card ────────────────────────────────────────────────────
    if ($action === 'remove') {
        $cardId = $database->getIntParam('cardId');
        if (!$cardId) return Database::responseBadRequest('cardId required');

        $existing = $database->query(
            'SELECT id, quantity FROM isBack_deckCard WHERE deckId = :deckId AND cardId = :cardId',
            [
                'deckId' => ['value' => $id, 'type' => \PDO::PARAM_INT],
                'cardId' => ['value' => $cardId, 'type' => \PDO::PARAM_INT],
            ]
        );
        if (!$existing) return Database::responseNotFound();

        $qty = (int)$existing[0]['quantity'];
        if ($qty <= 1) {
            $database->query('DELETE FROM isBack_deckCard WHERE id = :id', [
                'id' => ['value' => $existing[0]['id'], 'type' => \PDO::PARAM_INT],
            ]);
        } else {
            $database->query('UPDATE isBack_deckCard SET quantity = quantity - 1 WHERE id = :id', [
                'id' => ['value' => $existing[0]['id'], 'type' => \PDO::PARAM_INT],
            ]);
        }

        $totRow = $database->query(
            'SELECT COALESCE(SUM(quantity),0) total FROM isBack_deckCard WHERE deckId = :id',
            ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
        );
        $database->query('UPDATE isBack_deck SET updatedAt = NOW() WHERE id = :id', [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]);
        return Database::responseSuccess([
            'cardId'     => $cardId,
            'quantity'   => max(0, $qty - 1),
            'totalCards' => (int)$totRow[0]['total'],
        ]);
    }

    return Database::responseBadRequest('Unknown action: ' . $action);
}

$database = new Database();
$database->handleRequest('getDecks', 'manageDeck', 'manageDeck', 'manageDeck');