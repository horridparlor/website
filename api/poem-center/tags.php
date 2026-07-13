<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

const DEFAULT_TAG_COLOR = '#00ffcc';

function listTags(Database $database): string
{
    poemCenterRequireAdmin($database);

    $tags = $database->query(
        <<<SQL
            SELECT t.id, t.name, t.color, COUNT(l.poemId) poemCount
            FROM poem_tag t
            LEFT JOIN poem_tag_link l ON l.tagId = t.id
            GROUP BY t.id
            ORDER BY t.name ASC
        SQL
    );
    foreach ($tags as &$tag) {
        $tag['id'] = (int)$tag['id'];
        $tag['poemCount'] = (int)$tag['poemCount'];
    }

    return Database::responseSuccess(['tags' => $tags]);
}

function createTag(Database $database): string
{
    poemCenterRequireAdmin($database);

    $name = trim((string)$database->getRawStringParam('name', ''));
    $color = trim((string)$database->getRawStringParam('color', '')) ?: DEFAULT_TAG_COLOR;
    if (!$name) return Database::responseBadRequest('name required');

    $existing = $database->query(
        'SELECT id, name, color FROM poem_tag WHERE name = :name',
        ['name' => ['value' => $name, 'type' => \PDO::PARAM_STR]]
    );
    if ($existing) {
        return Database::responseSuccess(['tag' => [
            'id' => (int)$existing[0]['id'], 'name' => $existing[0]['name'], 'color' => $existing[0]['color'],
        ]]);
    }

    $database->query(
        'INSERT INTO poem_tag (name, color) VALUES (:name, :color)',
        [
            'name' => ['value' => $name, 'type' => \PDO::PARAM_STR],
            'color' => ['value' => $color, 'type' => \PDO::PARAM_STR],
        ]
    );
    $id = $database->getInsertId();

    return Database::responseSuccess(['tag' => ['id' => $id, 'name' => $name, 'color' => $color]]);
}

function updateTag(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_tag WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $name = $database->getRawStringParam('name');
    $color = $database->getRawStringParam('color');

    $updates = [];
    $replacements = ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]];

    if (!is_null($name)) {
        $name = trim((string)$name);
        if (!$name) return Database::responseBadRequest('name cannot be empty');
        $updates[] = 'name = :name';
        $replacements['name'] = ['value' => $name, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($color)) {
        $updates[] = 'color = :color';
        $replacements['color'] = ['value' => trim((string)$color) ?: DEFAULT_TAG_COLOR, 'type' => \PDO::PARAM_STR];
    }

    if (!$updates) return Database::responseBadRequest('No fields to update.');

    $database->query('UPDATE poem_tag SET ' . implode(', ', $updates) . ' WHERE id = :id', $replacements);

    return Database::responseSuccess(['success' => true]);
}

function deleteTag(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_tag WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $database->query('DELETE FROM poem_tag WHERE id = :id', [
        'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
    ]);

    return Database::responseSuccess(['id' => $id]);
}

$database = new Database();
$database->handleRequest('listTags', 'createTag', 'updateTag', 'deleteTag');
