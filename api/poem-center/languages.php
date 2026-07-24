<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function listLanguages(Database $database): string
{
    poemCenterRequireAdmin($database);

    $languages = $database->query(
        <<<SQL
            SELECT l.id, l.code, l.name, COUNT(p.id) poemCount
            FROM poem_language l
            LEFT JOIN poem p ON p.languageId = l.id AND p.isDeleted = 0
            GROUP BY l.id
            ORDER BY l.name ASC
        SQL
    );
    foreach ($languages as &$language) {
        $language['id'] = (int)$language['id'];
        $language['poemCount'] = (int)$language['poemCount'];
    }

    return Database::responseSuccess(['languages' => $languages]);
}

function createLanguage(Database $database): string
{
    poemCenterRequireAdmin($database);

    $code = trim((string)$database->getRawStringParam('code', ''));
    $name = trim((string)$database->getRawStringParam('name', ''));
    if (!$code) return Database::responseBadRequest('code required');
    if (!$name) return Database::responseBadRequest('name required');

    $existing = $database->query(
        'SELECT id, code, name FROM poem_language WHERE code = :code',
        ['code' => ['value' => $code, 'type' => \PDO::PARAM_STR]]
    );
    if ($existing) {
        return Database::responseSuccess(['language' => [
            'id' => (int)$existing[0]['id'], 'code' => $existing[0]['code'], 'name' => $existing[0]['name'],
        ]]);
    }

    $database->query(
        'INSERT INTO poem_language (code, name) VALUES (:code, :name)',
        [
            'code' => ['value' => $code, 'type' => \PDO::PARAM_STR],
            'name' => ['value' => $name, 'type' => \PDO::PARAM_STR],
        ]
    );
    $id = $database->getInsertId();

    return Database::responseSuccess(['language' => ['id' => $id, 'code' => $code, 'name' => $name]]);
}

function updateLanguage(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_language WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $code = $database->getRawStringParam('code');
    $name = $database->getRawStringParam('name');

    $updates = [];
    $replacements = ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]];

    if (!is_null($code)) {
        $code = trim((string)$code);
        if (!$code) return Database::responseBadRequest('code cannot be empty');
        $updates[] = 'code = :code';
        $replacements['code'] = ['value' => $code, 'type' => \PDO::PARAM_STR];
    }
    if (!is_null($name)) {
        $name = trim((string)$name);
        if (!$name) return Database::responseBadRequest('name cannot be empty');
        $updates[] = 'name = :name';
        $replacements['name'] = ['value' => $name, 'type' => \PDO::PARAM_STR];
    }

    if (!$updates) return Database::responseBadRequest('No fields to update.');

    $database->query('UPDATE poem_language SET ' . implode(', ', $updates) . ' WHERE id = :id', $replacements);

    return Database::responseSuccess(['success' => true]);
}

function deleteLanguage(Database $database): string
{
    poemCenterRequireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) return Database::responseBadRequest('id required');

    $existing = $database->query(
        'SELECT id FROM poem_language WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) return Database::responseNotFound();

    $database->query('DELETE FROM poem_language WHERE id = :id', [
        'id' => ['value' => $id, 'type' => \PDO::PARAM_INT],
    ]);

    return Database::responseSuccess(['id' => $id]);
}

$database = new Database();
$database->handleRequest('listLanguages', 'createLanguage', 'updateLanguage', 'deleteLanguage');