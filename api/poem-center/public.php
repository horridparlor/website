<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function listPublic(Database $database): string
{
    $tagId = $database->getIntParam('tag');

    $books = $database->query(
        <<<SQL
            SELECT id, title, description, sortOrder, publishedAt
            FROM poem_book
            WHERE isDeleted = 0 AND isPublished = 1
            ORDER BY sortOrder ASC, title ASC
        SQL
    );

    $sql = <<<SQL
        SELECT p.id, p.bookId, p.languageId, p.originalPoemId, p.title, p.author, p.content, p.sortOrder, p.writtenDate, p.publishedAt,
               lang.code languageCode, lang.name languageName
        FROM poem p
        LEFT JOIN poem_language lang ON lang.id = p.languageId
        LEFT JOIN poem_book b ON b.id = p.bookId AND b.isDeleted = 0
    SQL;
    $replacements = [];
    if ($tagId) {
        $sql .= ' JOIN poem_tag_link l ON l.poemId = p.id AND l.tagId = :tagId';
        $replacements['tagId'] = ['value' => $tagId, 'type' => \PDO::PARAM_INT];
    }
    // A poem is visible if it's published itself, or its book is published (book publish cascades).
    $sql .= ' WHERE p.isDeleted = 0 AND (p.isPublished = 1 OR b.isPublished = 1) ORDER BY p.writtenDate DESC';

    $poems = $database->query($sql, $replacements);

    $tagsByPoem = [];
    if ($poems) {
        $ids = array_map(fn($p) => (int)$p['id'], $poems);
        $placeholders = [];
        $tagReplacements = [];
        foreach ($ids as $i => $poemId) {
            $key = "pid$i";
            $placeholders[] = ":$key";
            $tagReplacements[$key] = ['value' => $poemId, 'type' => \PDO::PARAM_INT];
        }
        $tagSql = 'SELECT l.poemId, t.id, t.name, t.color
            FROM poem_tag_link l
            JOIN poem_tag t ON t.id = l.tagId
            WHERE l.poemId IN (' . implode(',', $placeholders) . ')
            ORDER BY t.name ASC';
        $tagRows = $database->query($tagSql, $tagReplacements);
        foreach ($tagRows as $row) {
            $tagsByPoem[(int)$row['poemId']][] = ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color']];
        }
    }

    $publishedBookIds = array_flip(array_map(fn($b) => (int)$b['id'], $books));

    $poemsByBook = [];
    $standalone = [];
    foreach ($poems as $poem) {
        $poem['id'] = (int)$poem['id'];
        $poem['bookId'] = $poem['bookId'] !== null ? (int)$poem['bookId'] : null;
        $poem['languageId'] = $poem['languageId'] !== null ? (int)$poem['languageId'] : null;
        $poem['originalPoemId'] = $poem['originalPoemId'] !== null ? (int)$poem['originalPoemId'] : null;
        $poem['sortOrder'] = (int)$poem['sortOrder'];
        $poem['tags'] = $tagsByPoem[$poem['id']] ?? [];
        // Only group under a book section if that book is actually published — otherwise
        // (e.g. an individually-published poem sitting in an unpublished book) show it standalone.
        if ($poem['bookId'] && isset($publishedBookIds[$poem['bookId']])) {
            $poemsByBook[$poem['bookId']][] = $poem;
        } else {
            $standalone[] = $poem;
        }
    }

    foreach ($books as &$book) {
        $book['id'] = (int)$book['id'];
        $book['sortOrder'] = (int)$book['sortOrder'];
        $book['poems'] = $poemsByBook[$book['id']] ?? [];
    }
    // Drop empty published books (nothing published inside them yet, or nothing matches the tag filter).
    $books = array_values(array_filter($books, fn($b) => sizeof($b['poems']) > 0));

    return Database::responseSuccess([
        'books' => $books,
        'poems' => $standalone,
    ]);
}

$database = new Database();
$database->handleRequest('listPublic');
