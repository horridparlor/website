<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function getPoem(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $poem = poemFetchFull($database, $id);
    if (!$poem) return Database::responseNotFound();

    return Database::responseSuccess(['poem' => $poem]);
}

function updatePoem(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $title = $database->getRawStringParam('title');
    $author = $database->getRawStringParam('author');
    $content = $database->getRawStringParam('content');
    $writtenDate = $database->getRawStringParam('writtenDate');
    $bookId = $database->getRawStringParam('bookId');
    $hasBookId = $bookId !== null;
    $bookIdInt = $database->getIntParam('bookId');
    $sortOrder = $database->getIntParam('sortOrder');

    $contentLike = !is_null($title) || !is_null($author) || !is_null($content);
    if ($contentLike) {
        poemSnapshotIfStale($database, $id);
    }

    $updates = [];
    $replacements = ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]];

    if (!is_null($title)) {
        $title = trim((string)$title);
        if (!$title) return Database::responseBadRequest('title cannot be empty');
        $updates[] = 'title = :title';
        $replacements['title'] = ['value' => $title, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($author)) {
        $author = trim((string)$author) ?: 'Eero Laine';
        $updates[] = 'author = :author';
        $replacements['author'] = ['value' => $author, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($content)) {
        $updates[] = 'content = :content';
        $replacements['content'] = ['value' => (string)$content, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($writtenDate)) {
        $updates[] = 'writtenDate = :writtenDate';
        $replacements['writtenDate'] = ['value' => $writtenDate ?: null, 'type' => \PDO::PARAM_STR];
    }
    if ($hasBookId) {
        if ($bookIdInt) {
            $book = $database->query(
                'SELECT id FROM poem_book WHERE id = :id AND isDeleted = 0',
                ['id' => ['value' => $bookIdInt, 'type' => \PDO::PARAM_INT]]
            );
            if (!$book) return Database::responseBadRequest('book does not exist');
        }
        $updates[] = 'bookId = :bookId';
        $replacements['bookId'] = ['value' => $bookIdInt ?: null, 'type' => \PDO::PARAM_INT];
    }
    if (!is_null($sortOrder)) {
        $updates[] = 'sortOrder = :sortOrder';
        $replacements['sortOrder'] = ['value' => $sortOrder, 'type' => \PDO::PARAM_INT];
    }

    $tagIds = $database->getArrayParam('tagIds');
    $hasTagIds = !is_null($tagIds);

    if (!$updates && !$hasTagIds) return Database::responseBadRequest('No fields to update.');

    if ($updates) {
        $database->query('UPDATE poem SET ' . implode(', ', $updates) . ' WHERE id = :id', $replacements);
    }
    if ($hasTagIds) {
        poemSyncTags($database, $id, $tagIds);
    }

    return Database::responseSuccess(['poem' => poemFetchFull($database, $id)]);
}

function deletePoem(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $ageRows = $database->query(
        'SELECT TIMESTAMPDIFF(SECOND, createdAt, NOW()) ageSeconds FROM poem WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    $ageSeconds = (int)($ageRows[0]['ageSeconds'] ?? 0);

    if ($ageSeconds >= POEM_AGE_GATE_SECONDS) {
        $database->query(
            'UPDATE poem SET isDeleted = 1, updatedAt = NOW() WHERE id = :id',
            ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
        );
        return Database::responseSuccess(['id' => $id, 'mode' => 'soft']);
    }

    $database->query('DELETE FROM poem WHERE id = :id', [
        'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
    ]);
    return Database::responseSuccess(['id' => $id, 'mode' => 'hard']);
}

$database = new Database();
$database->handleRequest('getPoem', null, 'updatePoem', 'deletePoem');
