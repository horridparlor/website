<?php

/*
 * Required tables (run once) — see migrations/009_isBack_subRelease.sql:
 *
 * CREATE TABLE isBack_expansion (
 *   id                        INT AUTO_INCREMENT PRIMARY KEY,
 *   name                      VARCHAR(100) NULL,
 *   firstCardId               INT NOT NULL,
 *   lastCardId                INT NOT NULL,
 *   releaseDate               DATE NULL DEFAULT NULL,
 *   isReleased                TINYINT(1) NOT NULL DEFAULT 0,
 *   showExpansionInCardGallery TINYINT(1) NOT NULL DEFAULT 0,
 *   showInDeckBuilder         TINYINT(1) NULL DEFAULT NULL,
 *   created_at                DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   updated_at                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE isBack_subRelease (
 *   id                        INT AUTO_INCREMENT PRIMARY KEY,
 *   expansionId               INT NOT NULL REFERENCES isBack_expansion(id),
 *   name                      VARCHAR(100) NULL,
 *   releaseDate               DATE NULL DEFAULT NULL,
 *   isReleased                TINYINT(1) NOT NULL DEFAULT 0,
 *   showExpansionInCardGallery TINYINT(1) NOT NULL DEFAULT 0,
 *   showInDeckBuilder         TINYINT(1) NULL DEFAULT NULL,
 *   created_at                DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   updated_at                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE isBack_subExpansionCard (
 *   id            INT AUTO_INCREMENT PRIMARY KEY,
 *   subReleaseId  INT NOT NULL REFERENCES isBack_subRelease(id),
 *   cardId        INT NOT NULL REFERENCES isBack_card(id)
 * );
 */

use system\Database;

header('Content-Type: application/json');
include("../../system/Database.php");

function nullableIntReplacement(mixed $value): array {
    if (is_null($value)) {
        return ['value' => null, 'type' => \PDO::PARAM_NULL];
    }
    return ['value' => intval($value), 'type' => \PDO::PARAM_INT];
}

function nullableStringReplacement(mixed $value): array {
    if (is_null($value)) {
        return ['value' => null, 'type' => \PDO::PARAM_NULL];
    }
    return ['value' => strval($value), 'type' => \PDO::PARAM_STR];
}

function nullableDateReplacement(mixed $value): array {
    if (is_null($value) || $value === '') {
        return ['value' => null, 'type' => \PDO::PARAM_NULL];
    }
    return ['value' => strval($value), 'type' => \PDO::PARAM_STR];
}

function mapSubRelease(array $row, array $cardIds): array {
    return [
        'id'                        => (int) $row['id'],
        'expansionId'               => (int) $row['expansionId'],
        'name'                      => $row['name'],
        'releaseDate'               => $row['releaseDate'],
        'isReleased'                => (bool) $row['isReleased'],
        'showExpansionInCardGallery' => (bool) $row['showExpansionInCardGallery'],
        'showInDeckBuilder'         => is_null($row['showInDeckBuilder']) ? null : (bool) $row['showInDeckBuilder'],
        'cardIds'                   => array_values(array_map('intval', $cardIds)),
    ];
}

function mapExpansion(array $row, array $subReleases): array {
    return [
        'id'                        => (int) $row['id'],
        'name'                      => $row['name'],
        'firstCardId'               => (int) $row['firstCardId'],
        'lastCardId'                => (int) $row['lastCardId'],
        'releaseDate'               => $row['releaseDate'],
        'isReleased'                => (bool) $row['isReleased'],
        'showExpansionInCardGallery' => (bool) $row['showExpansionInCardGallery'],
        'showInDeckBuilder'         => is_null($row['showInDeckBuilder']) ? null : (bool) $row['showInDeckBuilder'],
        'subReleases'               => $subReleases,
    ];
}

const EXPANSION_COLUMNS   = 'id, name, firstCardId, lastCardId, releaseDate, isReleased, showExpansionInCardGallery, showInDeckBuilder';
const SUB_RELEASE_COLUMNS = 'id, expansionId, name, releaseDate, isReleased, showExpansionInCardGallery, showInDeckBuilder';

function fetchSubReleasesByExpansion(Database $database): array {
    $subRows = $database->query("SELECT " . SUB_RELEASE_COLUMNS . " FROM isBack_subRelease ORDER BY id ASC");

    $cardRows = $database->query('SELECT subReleaseId, cardId FROM isBack_subExpansionCard');
    $cardsBySub = [];
    foreach ($cardRows as $cr) {
        $cardsBySub[(int) $cr['subReleaseId']][] = (int) $cr['cardId'];
    }

    $subsByExpansion = [];
    foreach ($subRows as $sr) {
        $subsByExpansion[(int) $sr['expansionId']][] = mapSubRelease($sr, $cardsBySub[(int) $sr['id']] ?? []);
    }
    return $subsByExpansion;
}

function getExpansions(Database $database): string {
    $sql  = 'SELECT ' . EXPANSION_COLUMNS . ' FROM isBack_expansion ORDER BY firstCardId ASC';
    $rows = $database->query($sql);

    $subsByExpansion = fetchSubReleasesByExpansion($database);

    $expansions = array_map(
        fn($row) => mapExpansion($row, $subsByExpansion[(int) $row['id']] ?? []),
        $rows
    );

    $user    = $database->getUser();
    $isAdmin = $user && $user->isAdmin();

    return Database::responseSuccess([
        'expansions' => $expansions,
        'isAdmin'    => $isAdmin,
    ]);
}

function validateSubReleaseCardIds(Database $database, array $expansionRow, array $cardIds, int $excludeSubId): ?string {
    $firstCardId = (int) $expansionRow['firstCardId'];
    $lastCardId  = (int) $expansionRow['lastCardId'];
    foreach ($cardIds as $cardId) {
        if ($cardId < $firstCardId || $cardId > $lastCardId) {
            return "Card $cardId is outside this expansion's card range ({$firstCardId}-{$lastCardId})";
        }
    }
    if (empty($cardIds)) return null;

    $placeholders = [];
    $replacements = [
        'expansionId' => Database::getIntReplacement((int) $expansionRow['id']),
        'excludeId'   => Database::getIntReplacement($excludeSubId),
    ];
    foreach ($cardIds as $i => $cardId) {
        $key = "c$i";
        $placeholders[] = ":$key";
        $replacements[$key] = Database::getIntReplacement($cardId);
    }
    $sql = "SELECT sec.cardId
            FROM isBack_subExpansionCard sec
            JOIN isBack_subRelease sr ON sr.id = sec.subReleaseId
            WHERE sr.expansionId = :expansionId
              AND sr.id != :excludeId
              AND sec.cardId IN (" . implode(', ', $placeholders) . ")";
    $rows = $database->query($sql, $replacements);
    if (!empty($rows)) {
        $ids = implode(', ', array_map(fn($r) => $r['cardId'], $rows));
        return "Card(s) already assigned to another sub-release: $ids";
    }
    return null;
}

function postSubRelease(Database $database, \stdClass $data, string $action): string {
    $expansionId = isset($data->expansionId) ? intval($data->expansionId) : null;
    if (!$expansionId) return Database::responseBadRequest('Missing expansionId');

    $expRows = $database->query(
        'SELECT ' . EXPANSION_COLUMNS . ' FROM isBack_expansion WHERE id = :id',
        ['id' => Database::getIntReplacement($expansionId)]
    );
    if (!$expRows) return Database::responseBadRequest('Expansion not found');
    $expansionRow = $expRows[0];

    $name        = isset($data->name) && $data->name !== '' ? strval($data->name) : null;
    $releaseDate = isset($data->releaseDate) && $data->releaseDate !== '' ? strval($data->releaseDate) : null;
    $isReleased  = isset($data->isReleased) ? intval((bool) $data->isReleased) : 0;
    $showGallery = property_exists($data, 'showExpansionInCardGallery') && !is_null($data->showExpansionInCardGallery)
        ? intval((bool) $data->showExpansionInCardGallery)
        : 0;
    $showDeckBuilder = property_exists($data, 'showInDeckBuilder') && !is_null($data->showInDeckBuilder)
        ? intval((bool) $data->showInDeckBuilder)
        : null;

    $cardIds = [];
    if (isset($data->cardIds) && is_array($data->cardIds)) {
        $cardIds = array_values(array_unique(array_map('intval', $data->cardIds)));
    }

    $subId = 0;
    if ($action === 'updateSub') {
        $subId = isset($data->id) ? intval($data->id) : 0;
        if (!$subId) return Database::responseBadRequest('Missing id for update');
        $owns = $database->query(
            'SELECT id FROM isBack_subRelease WHERE id = :id AND expansionId = :expansionId',
            ['id' => Database::getIntReplacement($subId), 'expansionId' => Database::getIntReplacement($expansionId)]
        );
        if (!$owns) return Database::responseBadRequest('Sub-release not found for this expansion');
    }

    $error = validateSubReleaseCardIds($database, $expansionRow, $cardIds, $subId);
    if ($error) return Database::responseBadRequest($error);

    if ($action === 'updateSub') {
        $database->query(
            <<<SQL
                UPDATE isBack_subRelease
                SET name = :name,
                    releaseDate = :releaseDate,
                    isReleased = :isReleased,
                    showExpansionInCardGallery = :showGallery,
                    showInDeckBuilder = :showDeckBuilder
                WHERE id = :id
            SQL,
            [
                'id'              => Database::getIntReplacement($subId),
                'name'            => nullableStringReplacement($name),
                'releaseDate'     => nullableDateReplacement($releaseDate),
                'isReleased'      => Database::getIntReplacement($isReleased),
                'showGallery'     => Database::getIntReplacement($showGallery),
                'showDeckBuilder' => nullableIntReplacement($showDeckBuilder),
            ]
        );
        $newSubId = $subId;
    } else {
        $database->query(
            <<<SQL
                INSERT INTO isBack_subRelease (expansionId, name, releaseDate, isReleased, showExpansionInCardGallery, showInDeckBuilder)
                VALUES (:expansionId, :name, :releaseDate, :isReleased, :showGallery, :showDeckBuilder)
            SQL,
            [
                'expansionId'     => Database::getIntReplacement($expansionId),
                'name'            => nullableStringReplacement($name),
                'releaseDate'     => nullableDateReplacement($releaseDate),
                'isReleased'      => Database::getIntReplacement($isReleased),
                'showGallery'     => Database::getIntReplacement($showGallery),
                'showDeckBuilder' => nullableIntReplacement($showDeckBuilder),
            ]
        );
        $newSubId = $database->getInsertId();
    }

    // Replace card assignments for this sub-release
    $database->query('DELETE FROM isBack_subExpansionCard WHERE subReleaseId = :id', ['id' => Database::getIntReplacement($newSubId)]);
    foreach ($cardIds as $cardId) {
        $database->query(
            'INSERT INTO isBack_subExpansionCard (subReleaseId, cardId) VALUES (:subId, :cardId)',
            ['subId' => Database::getIntReplacement($newSubId), 'cardId' => Database::getIntReplacement($cardId)]
        );
    }

    $row = $database->query(
        'SELECT ' . SUB_RELEASE_COLUMNS . ' FROM isBack_subRelease WHERE id = :id',
        ['id' => Database::getIntReplacement($newSubId)]
    );
    return Database::responseSuccess(['subRelease' => mapSubRelease($row[0], $cardIds)]);
}

function postExpansions(Database $database): string {
    $user = $database->getUser();
    if (!$user || !$user->isAdmin()) {
        return Database::responseUnauthorized();
    }

    $data   = $database->getRequestData();
    $action = $data->action ?? 'create';

    if ($action === 'delete') {
        $id = isset($data->id) ? intval($data->id) : null;
        if (!$id) return Database::responseBadRequest('Missing id');
        $database->query(
            'DELETE FROM isBack_expansion WHERE id = :id',
            ['id' => Database::getIntReplacement($id)]
        );
        return Database::responseSuccess(['deleted' => true]);
    }

    if ($action === 'createSub' || $action === 'updateSub') {
        return postSubRelease($database, $data, $action);
    }

    if ($action === 'deleteSub') {
        $id = isset($data->id) ? intval($data->id) : null;
        if (!$id) return Database::responseBadRequest('Missing id');
        $database->query(
            'DELETE FROM isBack_subRelease WHERE id = :id',
            ['id' => Database::getIntReplacement($id)]
        );
        return Database::responseSuccess(['deleted' => true]);
    }

    $name                       = isset($data->name) && $data->name !== '' ? strval($data->name) : null;
    $firstCardId                = isset($data->firstCardId) ? intval($data->firstCardId) : null;
    $lastCardId                 = isset($data->lastCardId)  ? intval($data->lastCardId)  : null;
    $releaseDate                = isset($data->releaseDate) && $data->releaseDate !== '' ? strval($data->releaseDate) : null;
    $isReleased                 = isset($data->isReleased) ? intval((bool) $data->isReleased) : 0;
    $showExpansionInCardGallery = property_exists($data, 'showExpansionInCardGallery') && !is_null($data->showExpansionInCardGallery)
        ? intval((bool) $data->showExpansionInCardGallery)
        : 0;
    $showInDeckBuilder          = property_exists($data, 'showInDeckBuilder') && !is_null($data->showInDeckBuilder)
        ? intval((bool) $data->showInDeckBuilder)
        : null;

    if (!$firstCardId || !$lastCardId) {
        return Database::responseBadRequest('Missing firstCardId or lastCardId');
    }
    if ($firstCardId > $lastCardId) {
        return Database::responseBadRequest('firstCardId must be ≤ lastCardId');
    }

    $excludeId = ($action === 'update' && isset($data->id)) ? intval($data->id) : 0;
    $overlaps  = $database->query(
        'SELECT id FROM isBack_expansion WHERE id != :excludeId AND NOT (lastCardId < :firstCardId OR firstCardId > :lastCardId)',
        [
            'excludeId'   => Database::getIntReplacement($excludeId),
            'firstCardId' => Database::getIntReplacement($firstCardId),
            'lastCardId'  => Database::getIntReplacement($lastCardId),
        ]
    );
    if (!empty($overlaps)) {
        $ids = implode(', ', array_column($overlaps, 'id'));
        return Database::responseBadRequest("Range overlaps with expansion id(s): $ids");
    }

    if ($action === 'update') {
        $id = isset($data->id) ? intval($data->id) : null;
        if (!$id) return Database::responseBadRequest('Missing id for update');
        $database->query(
            <<<SQL
                UPDATE isBack_expansion
                SET name = :name,
                    firstCardId = :firstCardId,
                    lastCardId = :lastCardId,
                    releaseDate = :releaseDate,
                    isReleased = :isReleased,
                    showExpansionInCardGallery = :showExpansionInCardGallery,
                    showInDeckBuilder = :showInDeckBuilder
                WHERE id = :id
            SQL,
            [
                'id'                        => Database::getIntReplacement($id),
                'name'                      => nullableStringReplacement($name),
                'firstCardId'               => Database::getIntReplacement($firstCardId),
                'lastCardId'                => Database::getIntReplacement($lastCardId),
                'releaseDate'               => nullableDateReplacement($releaseDate),
                'isReleased'                => Database::getIntReplacement($isReleased),
                'showExpansionInCardGallery' => Database::getIntReplacement($showExpansionInCardGallery),
                'showInDeckBuilder'         => nullableIntReplacement($showInDeckBuilder),
            ]
        );
        $row = $database->query('SELECT ' . EXPANSION_COLUMNS . ' FROM isBack_expansion WHERE id = :id', ['id' => Database::getIntReplacement($id)]);
        $subsByExpansion = fetchSubReleasesByExpansion($database);
        return Database::responseSuccess(['expansion' => mapExpansion($row[0], $subsByExpansion[$id] ?? [])]);
    }

    // create
    $database->query(
        'INSERT INTO isBack_expansion (name, firstCardId, lastCardId, releaseDate, isReleased, showExpansionInCardGallery, showInDeckBuilder) VALUES (:name, :firstCardId, :lastCardId, :releaseDate, :isReleased, :showExpansionInCardGallery, :showInDeckBuilder)',
        [
            'name'                      => nullableStringReplacement($name),
            'firstCardId'               => Database::getIntReplacement($firstCardId),
            'lastCardId'                => Database::getIntReplacement($lastCardId),
            'releaseDate'               => nullableDateReplacement($releaseDate),
            'isReleased'                => Database::getIntReplacement($isReleased),
            'showExpansionInCardGallery' => nullableIntReplacement($showExpansionInCardGallery),
            'showInDeckBuilder'         => nullableIntReplacement($showInDeckBuilder),
        ]
    );
    $newId = $database->getInsertId();
    $row   = $database->query('SELECT ' . EXPANSION_COLUMNS . ' FROM isBack_expansion WHERE id = :id', ['id' => Database::getIntReplacement($newId)]);
    return Database::responseSuccess(['expansion' => mapExpansion($row[0], [])]);
}

$database = new Database();
$database->handleRequest('getExpansions', 'postExpansions');
