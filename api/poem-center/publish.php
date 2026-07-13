<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function setPublish(Database $database): string
{
    poemCenterRequireAdmin($database);

    $type = $database->getStringParam('type', '');
    $id = $database->getIntParam('id');
    $isPublished = $database->getBooleanParam('isPublished');

    if (!in_array($type, ['poem', 'book'], true)) return Database::responseBadRequest('type must be poem or book');
    if (!$id) return Database::responseBadRequest('id required');
    if (is_null($isPublished)) return Database::responseBadRequest('isPublished required');

    $table = $type === 'poem' ? 'poem' : 'poem_book';

    $existing = $database->query(
        "SELECT id FROM $table WHERE id = :id AND isDeleted = 0",
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $database->query(
        "UPDATE $table SET isPublished = :isPublished, publishedAt = :publishedAt WHERE id = :id",
        [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
            'isPublished' => ['value' => (int)$isPublished, 'type' => \PDO::PARAM_INT],
            'publishedAt' => ['value' => $isPublished ? date('Y-m-d H:i:s') : null, 'type' => \PDO::PARAM_STR],
        ]
    );

    return Database::responseSuccess(['id' => $id, 'type' => $type, 'isPublished' => $isPublished]);
}

$database = new Database();
$database->handleRequest(null, 'setPublish');
