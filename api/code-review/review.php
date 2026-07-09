<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/CodeReviewHelper.php");

function getReview(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $review = codeReviewFetchFull($database, $id, $user->getId());
    if (!$review) return Database::responseNotFound();

    return Database::responseSuccess(['review' => $review]);
}

function deleteReview(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $rows = $database->query(
        'SELECT id, createdAt FROM code_reviews WHERE id = :id AND userId = :userId AND isDeleted = 0',
        [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );
    if (!$rows) return Database::responseNotFound();

    $ageRows = $database->query(
        'SELECT TIMESTAMPDIFF(SECOND, createdAt, NOW()) ageSeconds FROM code_reviews WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    $ageSeconds = (int)($ageRows[0]['ageSeconds'] ?? 0);
    $oneDay = 24 * 3600;

    if ($ageSeconds >= $oneDay) {
        $database->query(
            'UPDATE code_reviews SET isDeleted = 1, updatedAt = NOW() WHERE id = :id',
            ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
        );
        return Database::responseSuccess(['id' => $id, 'mode' => 'soft']);
    }

    $database->query('DELETE FROM code_reviews WHERE id = :id', [
        'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
    ]);
    return Database::responseSuccess(['id' => $id, 'mode' => 'hard']);
}

$database = new Database();
$database->handleRequest('getReview', null, null, 'deleteReview');
