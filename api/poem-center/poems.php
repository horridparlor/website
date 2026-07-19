<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function listPoems(Database $database): string
{
    poemCenterRequireAdmin($database);

    $bookId = $database->getIntParam('bookId');
    $hasBookId = $database->getRawStringParam('bookId') !== null;
    $q = $database->getStringParam('q', '');

    $sql = <<<SQL
        SELECT
            p.id, p.bookId, p.languageId, p.originalPoemId, p.title, p.author, p.content, p.sortOrder,
            p.writtenDate, p.isPublished, p.publishedAt, p.createdAt, p.updatedAt,
            b.title bookTitle, lang.code languageCode, lang.name languageName
        FROM poem p
        LEFT JOIN poem_book b ON b.id = p.bookId
        LEFT JOIN poem_language lang ON lang.id = p.languageId
        WHERE p.isDeleted = 0
    SQL;
    $replacements = [];

    if ($hasBookId) {
        if ($bookId) {
            $sql .= ' AND p.bookId = :bookId';
            $replacements['bookId'] = ['value' => $bookId, 'type' => \PDO::PARAM_INT];
        } else {
            $sql .= ' AND p.bookId IS NULL';
        }
    }
    if ($q !== '') {
        $sql .= ' AND (p.title LIKE :q OR p.content LIKE :q)';
        $replacements['q'] = ['value' => '%' . $q . '%', 'type' => \PDO::PARAM_STR];
    }

    if ($hasBookId && $bookId) {
        $sql .= ' ORDER BY p.sortOrder ASC, p.updatedAt DESC';
    } else {
        $sql .= ' ORDER BY p.writtenDate DESC, p.id DESC';
    }

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

    foreach ($poems as &$poem) {
        $poem['id'] = (int)$poem['id'];
        $poem['bookId'] = $poem['bookId'] !== null ? (int)$poem['bookId'] : null;
        $poem['languageId'] = $poem['languageId'] !== null ? (int)$poem['languageId'] : null;
        $poem['originalPoemId'] = $poem['originalPoemId'] !== null ? (int)$poem['originalPoemId'] : null;
        $poem['sortOrder'] = (int)$poem['sortOrder'];
        $poem['isPublished'] = (bool)(int)$poem['isPublished'];
        $poem['tags'] = $tagsByPoem[$poem['id']] ?? [];
    }

    return Database::responseSuccess(['poems' => $poems]);
}

function createPoem(Database $database): string
{
    poemCenterRequireAdmin($database);

    $title = trim((string)$database->getRawStringParam('title', ''));
    $author = trim((string)$database->getRawStringParam('author', ''));
    $content = (string)$database->getRawStringParam('content', '');
    $bookId = $database->getIntParam('bookId');
    $languageId = $database->getIntParam('languageId');
    $writtenDate = $database->getStringParam('writtenDate');
    $geniusUrl = trim((string)$database->getRawStringParam('geniusUrl', ''));

    if (!$title) return Database::responseBadRequest('title required');
    if (!$author) $author = 'Eero Laine';

    if ($bookId) {
        $book = $database->query(
            'SELECT id FROM poem_book WHERE id = :id AND isDeleted = 0',
            ['id' => ['value' => $bookId, 'type' => \PDO::PARAM_INT]]
        );
        if (!$book) return Database::responseBadRequest('book does not exist');
    }

    if ($languageId) {
        $language = $database->query(
            'SELECT id FROM poem_language WHERE id = :id',
            ['id' => ['value' => $languageId, 'type' => \PDO::PARAM_INT]]
        );
        if (!$language) return Database::responseBadRequest('language does not exist');
    } else {
        $finnish = $database->query("SELECT id FROM poem_language WHERE code = 'fi'");
        $languageId = $finnish ? (int)$finnish[0]['id'] : null;
    }

    $maxSort = $database->query(
        'SELECT MAX(sortOrder) maxSort FROM poem WHERE isDeleted = 0 AND ' .
        ($bookId ? 'bookId = :bookId' : 'bookId IS NULL'),
        $bookId ? ['bookId' => ['value' => $bookId, 'type' => \PDO::PARAM_INT]] : []
    );
    $sortOrder = ((int)($maxSort[0]['maxSort'] ?? -1)) + 1;

    $database->query(
        <<<SQL
            INSERT INTO poem (bookId, languageId, title, author, content, sortOrder, writtenDate, geniusUrl)
            VALUES (:bookId, :languageId, :title, :author, :content, :sortOrder, :writtenDate, :geniusUrl)
        SQL,
        [
            'bookId' => ['value' => $bookId ?: null, 'type' => \PDO::PARAM_INT],
            'languageId' => ['value' => $languageId ?: null, 'type' => \PDO::PARAM_INT],
            'title' => ['value' => $title, 'type' => \PDO::PARAM_STR],
            'author' => ['value' => $author, 'type' => \PDO::PARAM_STR],
            'content' => ['value' => $content, 'type' => \PDO::PARAM_STR],
            'sortOrder' => ['value' => $sortOrder, 'type' => \PDO::PARAM_INT],
            'writtenDate' => ['value' => $writtenDate ?: date('Y-m-d'), 'type' => \PDO::PARAM_STR],
            'geniusUrl' => ['value' => $geniusUrl !== '' ? $geniusUrl : null, 'type' => \PDO::PARAM_STR],
        ]
    );
    $poemId = $database->getInsertId();

    $tagIds = $database->getArrayParam('tagIds', []);
    if ($tagIds) {
        poemSyncTags($database, $poemId, $tagIds);
    }

    return Database::responseSuccess(['poem' => poemFetchFull($database, $poemId)]);
}

$database = new Database();
$database->handleRequest('listPoems', 'createPoem');
