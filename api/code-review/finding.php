<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/CodeReviewHelper.php");

function updateFinding(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');
    $checked = $database->getBooleanParam('checked');
    if ($checked === null) return Database::responseBadRequest('checked required');

    $rows = $database->query(
        <<<SQL
            SELECT f.id, f.codeReviewId
            FROM code_review_findings f
            JOIN code_reviews r ON r.id = f.codeReviewId
            WHERE f.id = :id AND r.userId = :userId AND r.isDeleted = 0
        SQL,
        [
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );
    if (!$rows) return Database::responseNotFound();
    $reviewId = (int)$rows[0]['codeReviewId'];

    $database->query(
        'UPDATE code_review_findings SET checked = :checked, updatedAt = NOW() WHERE id = :id',
        [
            'checked' => ['value' => $checked ? 1 : 0, 'type' => \PDO::PARAM_INT],
            'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
        ]
    );

    $reviewStatus = codeReviewRecomputeStatus($database, $reviewId);

    return Database::responseSuccess([
        'id' => $id,
        'checked' => (bool)$checked,
        'reviewId' => $reviewId,
        'reviewStatus' => $reviewStatus,
    ]);
}

$database = new Database();
$database->handleRequest(null, null, 'updateFinding', null);
