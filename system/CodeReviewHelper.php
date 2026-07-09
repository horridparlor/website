<?php

use system\Database;

function codeReviewFetchFull(Database $database, int $reviewId, int $userId): ?array
{
    $sql = <<<SQL
        SELECT id, identifier, title, rawMarkdown, status, createdAt, updatedAt
        FROM code_reviews
        WHERE id = :id AND userId = :userId AND isDeleted = 0
    SQL;
    $rows = $database->query($sql, [
        'id' => ['value' => $reviewId, 'type' => PDO::PARAM_INT],
        'userId' => ['value' => $userId, 'type' => PDO::PARAM_INT],
    ]);
    if (!$rows) {
        return null;
    }
    $review = $rows[0];
    $review['id'] = (int)$review['id'];

    $sql = <<<SQL
        SELECT id, sortOrder, sectionTitle, severity, number, title, filePath, bodyMarkdown, isCheckable, checked, createdAt, updatedAt
        FROM code_review_findings
        WHERE codeReviewId = :reviewId
        ORDER BY sortOrder ASC
    SQL;
    $findings = $database->query($sql, [
        'reviewId' => ['value' => $reviewId, 'type' => PDO::PARAM_INT],
    ]);

    $sql = <<<SQL
        SELECT c.id, c.findingId, c.body, c.createdAt, c.updatedAt
        FROM code_review_comments c
        JOIN code_review_findings f ON f.id = c.findingId
        WHERE f.codeReviewId = :reviewId
        ORDER BY c.createdAt ASC
    SQL;
    $comments = $database->query($sql, [
        'reviewId' => ['value' => $reviewId, 'type' => PDO::PARAM_INT],
    ]);

    $commentsByFinding = [];
    foreach ($comments as $comment) {
        $findingId = (int)$comment['findingId'];
        $comment['id'] = (int)$comment['id'];
        $comment['findingId'] = $findingId;
        $commentsByFinding[$findingId][] = $comment;
    }

    foreach ($findings as &$finding) {
        $finding['id'] = (int)$finding['id'];
        $finding['sortOrder'] = (int)$finding['sortOrder'];
        $finding['isCheckable'] = (bool)(int)$finding['isCheckable'];
        $finding['checked'] = (bool)(int)$finding['checked'];
        $finding['comments'] = $commentsByFinding[$finding['id']] ?? [];
    }

    $review['findings'] = $findings;
    return $review;
}

function codeReviewRecomputeStatus(Database $database, int $reviewId): string
{
    $sql = <<<SQL
        SELECT
            COUNT(*) total,
            SUM(checked) doneCount
        FROM code_review_findings
        WHERE codeReviewId = :reviewId AND isCheckable = 1
    SQL;
    $rows = $database->query($sql, [
        'reviewId' => ['value' => $reviewId, 'type' => PDO::PARAM_INT],
    ]);
    $total = (int)($rows[0]['total'] ?? 0);
    $doneCount = (int)($rows[0]['doneCount'] ?? 0);
    $status = ($total > 0 && $doneCount === $total) ? 'closed' : 'open';

    $database->query(
        'UPDATE code_reviews SET status = :status, updatedAt = NOW() WHERE id = :id',
        [
            'status' => ['value' => $status, 'type' => PDO::PARAM_STR],
            'id' => ['value' => $reviewId, 'type' => PDO::PARAM_INT],
        ]
    );

    return $status;
}
