<?php

use system\Database;
use system\User;

const POEM_AGE_GATE_SECONDS = 3600;

function poemCenterRequireAdmin(Database $database): User
{
    $user = $database->getUser();
    if (!$user || !$user->isAdmin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    return $user;
}

function poemFetchFull(Database $database, int $poemId): ?array
{
    $rows = $database->query(
        <<<SQL
            SELECT
                p.id, p.bookId, p.originalPoemId, p.title, p.author, p.content, p.sortOrder,
                p.writtenDate, p.isPublished, p.publishedAt, p.createdAt, p.updatedAt,
                orig.title originalTitle
            FROM poem p
            LEFT JOIN poem orig ON orig.id = p.originalPoemId
            WHERE p.id = :id AND p.isDeleted = 0
        SQL,
        ['id' => ['value' => $poemId, 'type' => PDO::PARAM_INT]]
    );
    if (!$rows) {
        return null;
    }
    $poem = $rows[0];
    $poem['id'] = (int)$poem['id'];
    $poem['bookId'] = $poem['bookId'] !== null ? (int)$poem['bookId'] : null;
    $poem['originalPoemId'] = $poem['originalPoemId'] !== null ? (int)$poem['originalPoemId'] : null;
    $poem['sortOrder'] = (int)$poem['sortOrder'];
    $poem['isPublished'] = (bool)(int)$poem['isPublished'];

    $historyCount = $database->query(
        'SELECT COUNT(*) cnt FROM poem_history WHERE poemId = :id',
        ['id' => ['value' => $poemId, 'type' => PDO::PARAM_INT]]
    );
    $poem['historyCount'] = (int)($historyCount[0]['cnt'] ?? 0);
    $poem['tags'] = poemTagsFor($database, $poemId);

    return $poem;
}

function poemTagsFor(Database $database, int $poemId): array
{
    $tags = $database->query(
        <<<SQL
            SELECT t.id, t.name, t.color
            FROM poem_tag t
            JOIN poem_tag_link l ON l.tagId = t.id
            WHERE l.poemId = :poemId
            ORDER BY t.name ASC
        SQL,
        ['poemId' => ['value' => $poemId, 'type' => PDO::PARAM_INT]]
    );
    foreach ($tags as &$tag) {
        $tag['id'] = (int)$tag['id'];
    }
    return $tags;
}

// Replaces a poem's tag links with exactly the given set of tag ids.
function poemSyncTags(Database $database, int $poemId, array $tagIds): void
{
    $database->query(
        'DELETE FROM poem_tag_link WHERE poemId = :poemId',
        ['poemId' => ['value' => $poemId, 'type' => PDO::PARAM_INT]]
    );
    foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
        if (!$tagId) continue;
        $exists = $database->query(
            'SELECT id FROM poem_tag WHERE id = :id',
            ['id' => ['value' => $tagId, 'type' => PDO::PARAM_INT]]
        );
        if (!$exists) continue;
        $database->query(
            'INSERT INTO poem_tag_link (poemId, tagId) VALUES (:poemId, :tagId)',
            [
                'poemId' => ['value' => $poemId, 'type' => PDO::PARAM_INT],
                'tagId' => ['value' => $tagId, 'type' => PDO::PARAM_INT],
            ]
        );
    }
}

// Snapshots the poem's current title/author/content/writtenDate into poem_history
// if it has not been edited within the last hour. Call BEFORE applying new edits.
function poemSnapshotIfStale(Database $database, int $poemId): void
{
    $rows = $database->query(
        <<<SQL
            SELECT title, author, content, writtenDate,
                   TIMESTAMPDIFF(SECOND, updatedAt, NOW()) ageSeconds
            FROM poem
            WHERE id = :id
        SQL,
        ['id' => ['value' => $poemId, 'type' => PDO::PARAM_INT]]
    );
    if (!$rows) {
        return;
    }
    $poem = $rows[0];
    if ((int)$poem['ageSeconds'] < POEM_AGE_GATE_SECONDS) {
        return;
    }

    poemSnapshotNow($database, $poemId, $poem['title'], $poem['author'], $poem['content'], $poem['writtenDate']);
}

function poemSnapshotNow(Database $database, int $poemId, string $title, string $author, string $content, ?string $writtenDate): void
{
    $database->query(
        <<<SQL
            INSERT INTO poem_history (poemId, title, author, content, writtenDate)
            VALUES (:poemId, :title, :author, :content, :writtenDate)
        SQL,
        [
            'poemId' => ['value' => $poemId, 'type' => PDO::PARAM_INT],
            'title' => ['value' => $title, 'type' => PDO::PARAM_STR],
            'author' => ['value' => $author, 'type' => PDO::PARAM_STR],
            'content' => ['value' => $content, 'type' => PDO::PARAM_STR],
            'writtenDate' => ['value' => $writtenDate, 'type' => PDO::PARAM_STR],
        ]
    );
}
