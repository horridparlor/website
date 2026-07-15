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
        SELECT p.id, p.bookId, p.originalPoemId, p.title, p.author, p.content, p.sortOrder, p.writtenDate, p.publishedAt
        FROM poem p
    SQL;
    $replacements = [];
    if ($tagId) {
        $sql .= ' JOIN poem_tag_link l ON l.poemId = p.id AND l.tagId = :tagId';
        $replacements['tagId'] = ['value' => $tagId, 'type' => \PDO::PARAM_INT];
    }
    $sql .= ' WHERE p.isDeleted = 0 AND p.isPublished = 1 ORDER BY p.writtenDate DESC';

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

    $poemsByBook = [];
    $standalone = [];
    foreach ($poems as $poem) {
        $poem['id'] = (int)$poem['id'];
        $poem['bookId'] = $poem['bookId'] !== null ? (int)$poem['bookId'] : null;
        $poem['originalPoemId'] = $poem['originalPoemId'] !== null ? (int)$poem['originalPoemId'] : null;
        $poem['sortOrder'] = (int)$poem['sortOrder'];
        $poem['tags'] = $tagsByPoem[$poem['id']] ?? [];
        if ($poem['bookId']) {
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
