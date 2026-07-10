<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

function listHistory(Database $database): string
{
    poemCenterRequireAdmin($database);

    $poemId = $database->getIntParam('poemId');
    if (!$poemId) return Database::responseBadRequest('poemId required');

    $poem = $database->query(
        'SELECT id FROM poem WHERE id = :id',
        ['id' => ['value' => $poemId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$poem) return Database::responseNotFound();

    $history = $database->query(
        <<<SQL
            SELECT id, title, author, content, writtenDate, snapshotAt
            FROM poem_history
            WHERE poemId = :poemId
            ORDER BY snapshotAt DESC
        SQL,
        ['poemId' => ['value' => $poemId, 'type' => \PDO::PARAM_INT]]
    );
    foreach ($history as &$row) {
        $row['id'] = (int)$row['id'];
    }

    return Database::responseSuccess(['history' => $history]);
}

function restoreHistory(Database $database): string
{
    poemCenterRequireAdmin($database);

    $historyId = $database->getIntParam('historyId');
    if (!$historyId) return Database::responseBadRequest('historyId required');

    $snapshotRows = $database->query(
        'SELECT poemId, title, author, content, writtenDate FROM poem_history WHERE id = :id',
        ['id' => ['value' => $historyId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$snapshotRows) return Database::responseNotFound();
    $snapshot = $snapshotRows[0];
    $poemId = (int)$snapshot['poemId'];

    $poemRows = $database->query(
        'SELECT title, author, content, writtenDate FROM poem WHERE id = :id AND isDeleted = 0',
        ['id' => ['value' => $poemId, 'type' => \PDO::PARAM_INT]]
    );
    if (!$poemRows) return Database::responseNotFound();
    $current = $poemRows[0];

    // Preserve the current state as a snapshot before overwriting, regardless of age,
    // so restoring never loses work.
    poemSnapshotNow($database, $poemId, $current['title'], $current['author'], $current['content'], $current['writtenDate']);

    $database->query(
        <<<SQL
            UPDATE poem SET title = :title, author = :author, content = :content, writtenDate = :writtenDate
            WHERE id = :id
        SQL,
        [
            'id' => ['value' => $poemId, 'type' => \PDO::PARAM_INT],
            'title' => ['value' => $snapshot['title'], 'type' => \PDO::PARAM_STR],
            'author' => ['value' => $snapshot['author'], 'type' => \PDO::PARAM_STR],
            'content' => ['value' => $snapshot['content'], 'type' => \PDO::PARAM_STR],
            'writtenDate' => ['value' => $snapshot['writtenDate'], 'type' => \PDO::PARAM_STR],
        ]
    );

    return Database::responseSuccess(['poem' => poemFetchFull($database, $poemId)]);
}

function handleHistoryPost(Database $database): string
{
    $action = $database->getStringParam('action', '');
    return match ($action) {
        'restore' => restoreHistory($database),
        default => Database::responseBadRequest('Unknown or missing action'),
    };
}

$database = new Database();
$database->handleRequest('listHistory', 'handleHistoryPost');
