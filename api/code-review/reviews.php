<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/CodeReviewHelper.php");

function listReviews(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $q = $database->getStringParam('q', '');
    $status = $database->getStringParam('status', '');

    $sql = <<<SQL
        SELECT
            r.id, r.identifier, r.title, r.status, r.createdAt, r.updatedAt,
            COUNT(f.id) totalCount,
            SUM(CASE WHEN f.checked = 1 THEN 1 ELSE 0 END) checkedCount
        FROM code_reviews r
        LEFT JOIN code_review_findings f ON f.codeReviewId = r.id AND f.isCheckable = 1
        WHERE r.userId = :userId AND r.isDeleted = 0
    SQL;
    $replacements = [
        'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
    ];

    if ($q !== '') {
        $sql .= ' AND (r.identifier LIKE :q OR r.title LIKE :q)';
        $replacements['q'] = ['value' => '%' . $q . '%', 'type' => \PDO::PARAM_STR];
    }
    if ($status === 'open' || $status === 'closed') {
        $sql .= ' AND r.status = :status';
        $replacements['status'] = ['value' => $status, 'type' => \PDO::PARAM_STR];
    }

    $sql .= ' GROUP BY r.id ORDER BY r.updatedAt DESC';

    $reviews = $database->query($sql, $replacements);
    foreach ($reviews as &$review) {
        $review['id'] = (int)$review['id'];
        $review['totalCount'] = (int)$review['totalCount'];
        $review['checkedCount'] = (int)$review['checkedCount'];
    }

    return Database::responseSuccess(['reviews' => $reviews]);
}

function createReview(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $identifier = trim((string)$database->getRawStringParam('identifier', ''));
    $title = trim((string)$database->getRawStringParam('title', ''));
    $rawMarkdown = (string)$database->getRawStringParam('rawMarkdown', '');
    $sections = $database->getArrayParam('sections', []);

    if (!$title) return Database::responseBadRequest('title required');
    if (!is_array($sections) || !sizeof($sections)) return Database::responseBadRequest('sections required');

    $database->query(
        'INSERT INTO code_reviews (userId, identifier, title, rawMarkdown, status) VALUES (:userId, :identifier, :title, :rawMarkdown, :status)',
        [
            'userId' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
            'identifier' => ['value' => $identifier ?: null, 'type' => \PDO::PARAM_STR],
            'title' => ['value' => $title, 'type' => \PDO::PARAM_STR],
            'rawMarkdown' => ['value' => $rawMarkdown, 'type' => \PDO::PARAM_STR],
            'status' => ['value' => 'open', 'type' => \PDO::PARAM_STR],
        ]
    );
    $reviewId = $database->getInsertId();

    $sortOrder = 0;
    foreach ($sections as $section) {
        $sectionTitle = trim((string)($section['sectionTitle'] ?? ''));
        $isCheckable = !empty($section['isCheckable']) ? 1 : 0;
        $findings = $section['findings'] ?? [];
        if (!is_array($findings)) continue;

        foreach ($findings as $finding) {
            $findingTitle = trim((string)($finding['title'] ?? ''));
            if (!$findingTitle) continue;

            $database->query(
                <<<SQL
                    INSERT INTO code_review_findings
                        (codeReviewId, sortOrder, sectionTitle, severity, number, title, filePath, bodyMarkdown, isCheckable, checked)
                    VALUES
                        (:codeReviewId, :sortOrder, :sectionTitle, :severity, :number, :title, :filePath, :bodyMarkdown, :isCheckable, 0)
                SQL,
                [
                    'codeReviewId' => ['value' => $reviewId, 'type' => \PDO::PARAM_INT],
                    'sortOrder' => ['value' => $sortOrder, 'type' => \PDO::PARAM_INT],
                    'sectionTitle' => ['value' => $sectionTitle ?: null, 'type' => \PDO::PARAM_STR],
                    'severity' => ['value' => trim((string)($finding['severity'] ?? '')) ?: null, 'type' => \PDO::PARAM_STR],
                    'number' => ['value' => trim((string)($finding['number'] ?? '')) ?: null, 'type' => \PDO::PARAM_STR],
                    'title' => ['value' => $findingTitle, 'type' => \PDO::PARAM_STR],
                    'filePath' => ['value' => trim((string)($finding['filePath'] ?? '')) ?: null, 'type' => \PDO::PARAM_STR],
                    'bodyMarkdown' => ['value' => (string)($finding['bodyMarkdown'] ?? ''), 'type' => \PDO::PARAM_STR],
                    'isCheckable' => ['value' => $isCheckable, 'type' => \PDO::PARAM_INT],
                ]
            );
            $sortOrder++;
        }
    }

    $review = codeReviewFetchFull($database, $reviewId, $user->getId());
    return Database::responseSuccess(['review' => $review]);
}

$database = new Database();
$database->handleRequest('listReviews', 'createReview');
