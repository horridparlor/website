<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function createVariant(Database $database): string
{
    poemCenterRequireAdmin($database);

    $poemId = $database->getIntParam('poemId');
    if (!$poemId) return Database::responseBadRequest('poemId required');

    $rows = $database->query(
        'SELECT bookId, originalPoemId, title, author, content, writtenDate FROM poem WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $poemId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$rows) return Database::responseNotFound();
    $original = $rows[0];

    $rootId = $original['originalPoemId'] ? (int)$original['originalPoemId'] : $poemId;
    $newTitle = $original['title'] . ' (New Version)';

    $maxSort = $database->query(
        'SELECT MAX(sortOrder) maxSort FROM poem WHERE isDeleted = 0 AND ' .
        ($original['bookId'] ? 'bookId = :bookId' : 'bookId IS NULL'),
        $original['bookId'] ? ['bookId' => ['value' => (int)$original['bookId'], 'type' => \PDO::PARAM_INT]] : []
    );
    $sortOrder = ((int)($maxSort[0]['maxSort'] ?? -1)) + 1;

    $database->query(
        <<<SQL
            INSERT INTO poem (bookId, originalPoemId, title, author, content, sortOrder, writtenDate)
            VALUES (:bookId, :originalPoemId, :title, :author, :content, :sortOrder, :writtenDate)
        SQL,
        [
            'bookId' => ['value' => $original['bookId'] ?: null, 'type' => \PDO::PARAM_INT],
            'originalPoemId' => ['value' => $rootId, 'type' => \PDO::PARAM_INT],
            'title' => ['value' => $newTitle, 'type' => \PDO::PARAM_STR],
            'author' => ['value' => $original['author'], 'type' => \PDO::PARAM_STR],
            'content' => ['value' => $original['content'], 'type' => \PDO::PARAM_STR],
            'sortOrder' => ['value' => $sortOrder, 'type' => \PDO::PARAM_INT],
            'writtenDate' => ['value' => date('Y-m-d'), 'type' => \PDO::PARAM_STR],
        ]
    );
    $newId = $database->getInsertId();

    return Database::responseSuccess(['poem' => poemFetchFull($database, $newId)]);
}

$database = new Database();
$database->handleRequest(null, 'createVariant');
