<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function listBooks(Database $database): string
{
    poemCenterRequireAdmin($database);

    $books = $database->query(
        <<<SQL
            SELECT
                b.id, b.title, b.description, b.sortOrder, b.isPublished, b.publishedAt,
                b.createdAt, b.updatedAt,
                COUNT(p.id) poemCount
            FROM poem_book b
            LEFT JOIN poem p ON p.bookId = b.id AND p.isDeleted = 0
            WHERE b.isDeleted = 0
            GROUP BY b.id
            ORDER BY b.sortOrder ASC, b.title ASC
        SQL
    );

    // How many of each tag a book's poems carry — most common first, alphabetical as
    // the tie-breaker — so the book list can show a "TagName (N)" breakdown per book.
    $tagRows = $database->query(
        <<<SQL
            SELECT p.bookId, t.id tagId, t.name, t.color, COUNT(*) cnt
            FROM poem p
            JOIN poem_tag_link l ON l.poemId = p.id
            JOIN poem_tag t ON t.id = l.tagId
            WHERE p.isDeleted = 0 AND p.bookId IS NOT NULL
            GROUP BY p.bookId, t.id
        SQL
    );
    $tagsByBook = [];
    foreach ($tagRows as $row) {
        $tagsByBook[(int)$row['bookId']][] = [
            'id' => (int)$row['tagId'], 'name' => $row['name'], 'color' => $row['color'], 'count' => (int)$row['cnt'],
        ];
    }
    foreach ($tagsByBook as &$tags) {
        usort($tags, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));
    }

    // Same idea, but for the languages a book's poems use.
    $langRows = $database->query(
        <<<SQL
            SELECT p.bookId, lang.id languageId, lang.name, COUNT(*) cnt
            FROM poem p
            JOIN poem_language lang ON lang.id = p.languageId
            WHERE p.isDeleted = 0 AND p.bookId IS NOT NULL
            GROUP BY p.bookId, lang.id
        SQL
    );
    $languagesByBook = [];
    foreach ($langRows as $row) {
        $languagesByBook[(int)$row['bookId']][] = [
            'id' => (int)$row['languageId'], 'name' => $row['name'], 'count' => (int)$row['cnt'],
        ];
    }
    foreach ($languagesByBook as &$langs) {
        usort($langs, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));
    }

    foreach ($books as &$book) {
        $book['id'] = (int)$book['id'];
        $book['sortOrder'] = (int)$book['sortOrder'];
        $book['isPublished'] = (bool)(int)$book['isPublished'];
        $book['poemCount'] = (int)$book['poemCount'];
        $book['tags'] = $tagsByBook[$book['id']] ?? [];
        $book['languages'] = $languagesByBook[$book['id']] ?? [];
    }

    return Database::responseSuccess(['books' => $books]);
}

function createBook(Database $database): string
{
    poemCenterRequireAdmin($database);

    $title = trim((string)$database->getRawStringParam('title', ''));
    $description = trim((string)$database->getRawStringParam('description', ''));
    if (!$title) return Database::responseBadRequest('title required');

    $maxSort = $database->query('SELECT MAX(sortOrder) maxSort FROM poem_book WHERE isDeleted = 0');
    $sortOrder = ((int)($maxSort[0]['maxSort'] ?? -1)) + 1;

    $database->query(
        'INSERT INTO poem_book (title, description, sortOrder) VALUES (:title, :description, :sortOrder)',
        [
            'title' => ['value' => $title, 'type' => \PDO::PARAM_STR],
            'description' => ['value' => $description ?: null, 'type' => \PDO::PARAM_STR],
            'sortOrder' => ['value' => $sortOrder, 'type' => \PDO::PARAM_INT],
        ]
    );
    $bookId = $database->getInsertId();

    return Database::responseSuccess(['id' => $bookId]);
}

function updateBook(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_book WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $title = $database->getRawStringParam('title');
    $description = $database->getRawStringParam('description');
    $sortOrder = $database->getIntParam('sortOrder');

    $updates = [];
    $replacements = ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]];

    if (!is_null($title)) {
        $title = trim((string)$title);
        if (!$title) return Database::responseBadRequest('title cannot be empty');
        $updates[] = 'title = :title';
        $replacements['title'] = ['value' => $title, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($description)) {
        $updates[] = 'description = :description';
        $replacements['description'] = ['value' => trim((string)$description) ?: null, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($sortOrder)) {
        $updates[] = 'sortOrder = :sortOrder';
        $replacements['sortOrder'] = ['value' => $sortOrder, 'type' => \PDO::PARAM_INT];
    }

    if (!$updates) return Database::responseBadRequest('No fields to update.');

    $database->query('UPDATE poem_book SET ' . implode(', ', $updates) . ' WHERE id = :id', $replacements);

    return Database::responseSuccess(['success' => true]);
}

function deleteBook(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_book WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $database->query(
        'UPDATE poem_book SET isDeleted = 1 WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );

    // Deleting a book unsorts its poems rather than deleting them — clear the
    // reference so they don't keep showing the now-deleted book as theirs.
    $database->query(
        'UPDATE poem SET bookId = NULL WHERE bookId = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );

    return Database::responseSuccess(['id' => $id]);
}

$database = new Database();
$database->handleRequest('listBooks', 'createBook', 'updateBook', 'deleteBook');
