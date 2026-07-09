<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function findOwnedFinding(Database $database, int $findingId, int $userId): ?array
{
    $rows = $database->query(
        <<<SQL
            SELECT f.id
            FROM code_review_findings f
            JOIN code_reviews r ON r.id = f.codeReviewId
            WHERE f.id = :findingId AND r.userId = :userId AND r.isDeleted = 0
        SQL,
        [
            'findingId' => ['value' => $findingId, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $userId, 'type' => \PDO::PARAM_INT],
        ]
    );
    return $rows[0] ?? null;
}

function findOwnedComment(Database $database, int $commentId, int $userId): ?array
{
    $rows = $database->query(
        <<<SQL
            SELECT c.id, c.findingId
            FROM code_review_comments c
            JOIN code_review_findings f ON f.id = c.findingId
            JOIN code_reviews r ON r.id = f.codeReviewId
            WHERE c.id = :commentId AND r.userId = :userId AND r.isDeleted = 0
        SQL,
        [
            'commentId' => ['value' => $commentId, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $userId, 'type' => \PDO::PARAM_INT],
        ]
    );
    return $rows[0] ?? null;
}

function createComment(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $findingId = $database->getIntParam('findingId');
    $body = trim((string)$database->getRawStringParam('body', ''));
    if (!$findingId) return Database::responseBadRequest('findingId required');
    if (!$body) return Database::responseBadRequest('body required');

    if (!findOwnedFinding($database, $findingId, $user->getId())) return Database::responseNotFound();

    $database->query(
        'INSERT INTO code_review_comments (findingId, body) VALUES (:findingId, :body)',
        [
            'findingId' => ['value' => $findingId, 'type' => \PDO::PARAM_INT],
            'body' => ['value' => $body, 'type' => \PDO::PARAM_STR],
        ]
    );
    $commentId = $database->getInsertId();

    $rows = $database->query(
        'SELECT id, findingId, body, createdAt, updatedAt FROM code_review_comments WHERE id = :id',
        ['id' => ['value' => $commentId, 'type' => \PDO::PARAM_INT]]
    );

    return Database::responseSuccess(['comment' => $rows[0]]);
}

function updateComment(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id = $database->getIntParam('id');
    $body = trim((string)$database->getRawStringParam('body', ''));
    if (!$id) return Database::responseBadRequest('id required');
    if (!$body) return Database::responseBadRequest('body required');

    if (!findOwnedComment($database, $id, $user->getId())) return Database::responseNotFound();

    $database->query(
        'UPDATE code_review_comments SET body = :body, updatedAt = NOW() WHERE id = :id',
        [
            'body' => ['value' => $body, 'type' => \PDO::PARAM_STR],
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]
    );

    $rows = $database->query(
        'SELECT id, findingId, body, createdAt, updatedAt FROM code_review_comments WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );

    return Database::responseSuccess(['comment' => $rows[0]]);
}

function deleteComment(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    if (!findOwnedComment($database, $id, $user->getId())) return Database::responseNotFound();

    $database->query('DELETE FROM code_review_comments WHERE id = :id', [
        'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
    ]);

    return Database::responseSuccess(['id' => $id]);
}

$database = new Database();
$database->handleRequest(null, 'createComment', 'updateComment', 'deleteComment');
