<?php

/*
 * Required table (run once):
 *
 * CREATE TABLE isBack_expansion (
 *   id                        INT AUTO_INCREMENT PRIMARY KEY,
 *   name                      VARCHAR(100) NULL,
 *   firstCardId               INT NOT NULL,
 *   lastCardId                INT NOT NULL,
 *   isReleased                TINYINT(1) NOT NULL DEFAULT 0,
 *   showExpansionInCardGallery TINYINT(1) NOT NULL DEFAULT 0,
 *   showInDeckBuilder         TINYINT(1) NULL DEFAULT NULL,
 *   created_at                DATETIME DEFAULT CURRENT_TIMESTAMP,
 *   updated_at                DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

function mapExpansion(array $row): array {
    return [
        'id'                        => (int) $row['id'],
        'name'                      => $row['name'],
        'firstCardId'               => (int) $row['firstCardId'],
        'lastCardId'                => (int) $row['lastCardId'],
        'isReleased'                => (bool) $row['isReleased'],
        'showExpansionInCardGallery' => (bool) $row['showExpansionInCardGallery'],
        'showInDeckBuilder'         => is_null($row['showInDeckBuilder']) ? null : (bool) $row['showInDeckBuilder'],
    ];
}

function getExpansions(Database $database): string {
    $sql = <<<SQL
        SELECT id, name, firstCardId, lastCardId, isReleased, showExpansionInCardGallery, showInDeckBuilder
        FROM isBack_expansion
        ORDER BY firstCardId ASC
    SQL;
    $rows = $database->query($sql);
    $expansions = array_map('mapExpansion', $rows);

    $user    = $database->getUser();
    $isAdmin = $user && $user->isAdmin();

    return Database::responseSuccess([
        'expansions' => $expansions,
        'isAdmin'    => $isAdmin,
    ]);
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

    $name                       = isset($data->name) && $data->name !== '' ? strval($data->name) : null;
    $firstCardId                = isset($data->firstCardId) ? intval($data->firstCardId) : null;
    $lastCardId                 = isset($data->lastCardId)  ? intval($data->lastCardId)  : null;
    $isReleased                 = isset($data->isReleased)                 ? intval((bool) $data->isReleased)                 : 0;
    $showExpansionInCardGallery = isset($data->showExpansionInCardGallery) ? intval((bool) $data->showExpansionInCardGallery) : 0;
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
                'isReleased'                => Database::getIntReplacement($isReleased),
                'showExpansionInCardGallery' => Database::getIntReplacement($showExpansionInCardGallery),
                'showInDeckBuilder'         => nullableIntReplacement($showInDeckBuilder),
            ]
        );
        $row = $database->query('SELECT id, name, firstCardId, lastCardId, isReleased, showExpansionInCardGallery, showInDeckBuilder FROM isBack_expansion WHERE id = :id', ['id' => Database::getIntReplacement($id)]);
        return Database::responseSuccess(['expansion' => mapExpansion($row[0])]);
    }

    // create
    $database->query(
        'INSERT INTO isBack_expansion (name, firstCardId, lastCardId, isReleased, showExpansionInCardGallery, showInDeckBuilder) VALUES (:name, :firstCardId, :lastCardId, :isReleased, :showExpansionInCardGallery, :showInDeckBuilder)',
        [
            'name'                      => nullableStringReplacement($name),
            'firstCardId'               => Database::getIntReplacement($firstCardId),
            'lastCardId'                => Database::getIntReplacement($lastCardId),
            'isReleased'                => Database::getIntReplacement($isReleased),
            'showExpansionInCardGallery' => Database::getIntReplacement($showExpansionInCardGallery),
            'showInDeckBuilder'         => nullableIntReplacement($showInDeckBuilder),
        ]
    );
    $newId = $database->getInsertId();
    $row   = $database->query('SELECT id, name, firstCardId, lastCardId, isReleased, showExpansionInCardGallery, showInDeckBuilder FROM isBack_expansion WHERE id = :id', ['id' => Database::getIntReplacement($newId)]);
    return Database::responseSuccess(['expansion' => mapExpansion($row[0])]);
}

$database = new Database();
$database->handleRequest('getExpansions', 'postExpansions');