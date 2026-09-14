<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

const BACKUP_TYPE_POEMS = 'poemsBackup';
const BACKUP_DIR = __DIR__ . '/../../backups/';

function ensureBackupDir(): void
{
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0775, true);
    }
}

// Full dump of every table the Poem Center owns (excluding poem_language, which is
// static reference data, not user content) — enough to fully restore the current state.
function buildPoemsBackupData(Database $database): array
{
    $books = $database->query(
        'SELECT id, title, description, sortOrder, isPublished, publishedAt, isDeleted, createdAt, updatedAt
         FROM poem_book ORDER BY id ASC'
    );
    $tags = $database->query('SELECT id, name, color, createdAt FROM poem_tag ORDER BY id ASC');
    $tagLinks = $database->query('SELECT poemId, tagId FROM poem_tag_link ORDER BY poemId ASC, tagId ASC');
    $poems = $database->query(
        'SELECT id, bookId, languageId, originalPoemId, title, author, content, sortOrder,
                writtenDate, geniusUrl, isPublished, publishedAt, isDeleted, createdAt, updatedAt
         FROM poem ORDER BY id ASC'
    );
    $history = $database->query(
        'SELECT id, poemId, title, author, content, writtenDate, snapshotAt
         FROM poem_history ORDER BY id ASC'
    );

    return [
        'version' => 1,
        'exportedAt' => date('c'),
        'domain' => $_SERVER['HTTP_HOST'] ?? '',
        'books' => $books,
        'tags' => $tags,
        'tagLinks' => $tagLinks,
        'poems' => $poems,
        'history' => $history,
    ];
}

// Writes $data to a timestamped file under backups/ and records it in the backup table.
// Timestamp is to the second (not just the date) since this can now run more than once a
// day — both from a manual export and as the automatic pre-reset snapshot.
function saveServerBackup(Database $database, array $data): array
{
    ensureBackupDir();

    $slug = date('Y-m-d_H-i-s');
    $filename = "poems-backup-$slug.json";
    $i = 2;
    while (file_exists(BACKUP_DIR . $filename)) {
        $filename = "poems-backup-{$slug}-{$i}.json";
        $i++;
    }

    file_put_contents(
        BACKUP_DIR . $filename,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $relativePath = 'backups/' . $filename;

    $database->query(
        'INSERT INTO backup (backupType, path, isValid) VALUES (:type, :path, 1)',
        [
            'type' => Database::getStringReplacement(BACKUP_TYPE_POEMS),
            'path' => Database::getStringReplacement($relativePath),
        ]
    );
    $id = $database->getInsertId();
    $row = $database->query(
        'SELECT id, path, createdAt FROM backup WHERE id = :id',
        ['id' => Database::getIntReplacement($id)]
    )[0];

    return [
        'id' => (int)$row['id'],
        'path' => $row['path'],
        'filename' => $filename,
        'createdAt' => $row['createdAt'],
    ];
}

// Writes the backup to the server (backups/ + a `backup` row) and also returns the data
// so the browser can download its own copy at the same time.
function exportBackup(Database $database): string
{
    poemCenterRequireAdmin($database);

    $data = buildPoemsBackupData($database);
    $saved = saveServerBackup($database, $data);

    return Database::responseSuccess([
        'backup' => $data,
        'saved' => [
            'id' => $saved['id'],
            'filename' => $saved['filename'],
            'createdAt' => $saved['createdAt'],
        ],
    ]);
}

// Lists server-stored poem backups, newest first. Any row whose file has since
// disappeared from disk is quietly flipped to isValid = 0 and left out of the list.
function listBackups(Database $database): string
{
    poemCenterRequireAdmin($database);

    $rows = $database->query(
        'SELECT id, path, createdAt FROM backup WHERE backupType = :type AND isValid = 1 ORDER BY createdAt DESC',
        ['type' => Database::getStringReplacement(BACKUP_TYPE_POEMS)]
    );

    $backups = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $absPath = __DIR__ . '/../../' . $row['path'];
        if (!file_exists($absPath)) {
            $database->query(
                'UPDATE backup SET isValid = 0 WHERE id = :id',
                ['id' => Database::getIntReplacement($id)]
            );
            continue;
        }
        $backups[] = [
            'id' => $id,
            'filename' => basename($row['path']),
            'createdAt' => $row['createdAt'],
            'sizeBytes' => filesize($absPath),
        ];
    }

    return Database::responseSuccess(['backups' => $backups]);
}

function handleBackupGet(Database $database): string
{
    $action = $database->getStringParam('action', 'export');
    return match ($action) {
        'list' => listBackups($database),
        'export' => exportBackup($database),
        default => Database::responseBadRequest('Unknown action'),
    };
}

// Wipes poem_book/poem_tag/poem_tag_link/poem/poem_history and reinserts everything from
// the chosen server backup, preserving original ids so cross-references (bookId, tagId,
// originalPoemId, poemId) stay intact. poem_language is left untouched.
function resetFromBackup(Database $database): string
{
    poemCenterRequireAdmin($database);

    $backupId = $database->getIntParam('backupId');
    if (!$backupId) return Database::responseBadRequest('backupId required');

    $backupCurrentFirst = $database->getBooleanParam('backupCurrentFirst', true);

    $rows = $database->query(
        'SELECT id, path, isValid FROM backup WHERE id = :id AND backupType = :type',
        [
            'id' => Database::getIntReplacement($backupId),
            'type' => Database::getStringReplacement(BACKUP_TYPE_POEMS),
        ]
    );
    if (!$rows) return Database::responseNotFound(['error' => 'Backup not found.']);
    $backupRow = $rows[0];
    if (!(int)$backupRow['isValid']) {
        return Database::responseBadRequest('That backup is no longer available.');
    }

    $absPath = __DIR__ . '/../../' . $backupRow['path'];
    if (!file_exists($absPath)) {
        $database->query(
            'UPDATE backup SET isValid = 0 WHERE id = :id',
            ['id' => Database::getIntReplacement($backupId)]
        );
        return Database::responseBadRequest('That backup file no longer exists on the server. It has been removed from the list — please pick another.');
    }

    // The domain-typing safety net is only required when the admin opted OUT of taking a
    // fresh safety snapshot first — with that snapshot, a mistake is always recoverable.
    if (!$backupCurrentFirst) {
        $actualDomain = $_SERVER['HTTP_HOST'] ?? '';
        $confirmDomain = trim((string)$database->getRawStringParam('confirmDomain', ''));
        if ($confirmDomain === '' || strcasecmp($confirmDomain, $actualDomain) !== 0) {
            return Database::responseBadRequest("Domain confirmation did not match this site ($actualDomain). Reset cancelled.");
        }
    }

    $raw = file_get_contents($absPath);
    $backup = json_decode($raw, true);
    if (!is_array($backup) || !isset($backup['books'], $backup['tags'], $backup['poems'], $backup['history'])) {
        return Database::responseBadRequest('That backup file is invalid or corrupted.');
    }

    $books = is_array($backup['books']) ? $backup['books'] : [];
    $tags = is_array($backup['tags']) ? $backup['tags'] : [];
    $tagLinks = is_array($backup['tagLinks'] ?? null) ? $backup['tagLinks'] : [];
    $poems = is_array($backup['poems']) ? $backup['poems'] : [];
    $history = is_array($backup['history']) ? $backup['history'] : [];

    $preResetBackup = null;
    if ($backupCurrentFirst) {
        $preResetBackup = saveServerBackup($database, buildPoemsBackupData($database));
    }

    $languageIds = array_flip(array_map(
        fn($r) => (int)$r['id'],
        $database->query('SELECT id FROM poem_language')
    ));

    // Wipe existing content, in FK-safe order (self-referencing originalPoemId cleared first).
    $database->query('DELETE FROM poem_tag_link');
    $database->query('DELETE FROM poem_history');
    $database->query('UPDATE poem SET originalPoemId = NULL');
    $database->query('DELETE FROM poem');
    $database->query('DELETE FROM poem_book');
    $database->query('DELETE FROM poem_tag');

    foreach ($books as $b) {
        $database->query(
            <<<SQL
                INSERT INTO poem_book (id, title, description, sortOrder, isPublished, publishedAt, isDeleted, createdAt, updatedAt)
                VALUES (:id, :title, :description, :sortOrder, :isPublished, :publishedAt, :isDeleted, :createdAt, :updatedAt)
            SQL,
            [
                'id' => Database::getIntReplacement((int)$b['id']),
                'title' => Database::getStringReplacement((string)($b['title'] ?? '')),
                'description' => ['value' => $b['description'] ?? null, 'type' => \PDO::PARAM_STR],
                'sortOrder' => Database::getIntReplacement((int)($b['sortOrder'] ?? 0)),
                'isPublished' => Database::getIntReplacement((int)!empty($b['isPublished'])),
                'publishedAt' => ['value' => $b['publishedAt'] ?? null, 'type' => \PDO::PARAM_STR],
                'isDeleted' => Database::getIntReplacement((int)!empty($b['isDeleted'])),
                'createdAt' => Database::getStringReplacement((string)($b['createdAt'] ?? date('Y-m-d H:i:s'))),
                'updatedAt' => Database::getStringReplacement((string)($b['updatedAt'] ?? date('Y-m-d H:i:s'))),
            ]
        );
    }

    foreach ($tags as $t) {
        $database->query(
            'INSERT INTO poem_tag (id, name, color, createdAt) VALUES (:id, :name, :color, :createdAt)',
            [
                'id' => Database::getIntReplacement((int)$t['id']),
                'name' => Database::getStringReplacement((string)($t['name'] ?? '')),
                'color' => ['value' => $t['color'] ?? null, 'type' => \PDO::PARAM_STR],
                'createdAt' => Database::getStringReplacement((string)($t['createdAt'] ?? date('Y-m-d H:i:s'))),
            ]
        );
    }

    foreach ($poems as $p) {
        $languageId = isset($p['languageId']) && isset($languageIds[(int)$p['languageId']]) ? (int)$p['languageId'] : null;
        $database->query(
            <<<SQL
                INSERT INTO poem (id, bookId, languageId, originalPoemId, title, author, content, sortOrder, writtenDate, geniusUrl, isPublished, publishedAt, isDeleted, createdAt, updatedAt)
                VALUES (:id, :bookId, :languageId, NULL, :title, :author, :content, :sortOrder, :writtenDate, :geniusUrl, :isPublished, :publishedAt, :isDeleted, :createdAt, :updatedAt)
            SQL,
            [
                'id' => Database::getIntReplacement((int)$p['id']),
                'bookId' => ['value' => $p['bookId'] !== null && $p['bookId'] !== '' ? (int)$p['bookId'] : null, 'type' => \PDO::PARAM_INT],
                'languageId' => ['value' => $languageId, 'type' => \PDO::PARAM_INT],
                'title' => Database::getStringReplacement((string)($p['title'] ?? '')),
                'author' => Database::getStringReplacement((string)($p['author'] ?? 'Eero Laine')),
                'content' => Database::getStringReplacement((string)($p['content'] ?? '')),
                'sortOrder' => Database::getIntReplacement((int)($p['sortOrder'] ?? 0)),
                'writtenDate' => ['value' => $p['writtenDate'] ?? null, 'type' => \PDO::PARAM_STR],
                'geniusUrl' => ['value' => $p['geniusUrl'] ?? null, 'type' => \PDO::PARAM_STR],
                'isPublished' => Database::getIntReplacement((int)!empty($p['isPublished'])),
                'publishedAt' => ['value' => $p['publishedAt'] ?? null, 'type' => \PDO::PARAM_STR],
                'isDeleted' => Database::getIntReplacement((int)!empty($p['isDeleted'])),
                'createdAt' => Database::getStringReplacement((string)($p['createdAt'] ?? date('Y-m-d H:i:s'))),
                'updatedAt' => Database::getStringReplacement((string)($p['updatedAt'] ?? date('Y-m-d H:i:s'))),
            ]
        );
    }

    // Second pass: relink originalPoemId now that every poem row exists.
    $poemIds = array_flip(array_map(fn($p) => (int)$p['id'], $poems));
    foreach ($poems as $p) {
        if (!empty($p['originalPoemId']) && isset($poemIds[(int)$p['originalPoemId']])) {
            $database->query(
                'UPDATE poem SET originalPoemId = :orig WHERE id = :id',
                [
                    'orig' => Database::getIntReplacement((int)$p['originalPoemId']),
                    'id' => Database::getIntReplacement((int)$p['id']),
                ]
            );
        }
    }

    foreach ($tagLinks as $l) {
        if (!isset($l['poemId'], $l['tagId'])) continue;
        $database->query(
            'INSERT IGNORE INTO poem_tag_link (poemId, tagId) VALUES (:poemId, :tagId)',
            [
                'poemId' => Database::getIntReplacement((int)$l['poemId']),
                'tagId' => Database::getIntReplacement((int)$l['tagId']),
            ]
        );
    }

    foreach ($history as $h) {
        $database->query(
            <<<SQL
                INSERT INTO poem_history (id, poemId, title, author, content, writtenDate, snapshotAt)
                VALUES (:id, :poemId, :title, :author, :content, :writtenDate, :snapshotAt)
            SQL,
            [
                'id' => Database::getIntReplacement((int)$h['id']),
                'poemId' => Database::getIntReplacement((int)$h['poemId']),
                'title' => Database::getStringReplacement((string)($h['title'] ?? '')),
                'author' => Database::getStringReplacement((string)($h['author'] ?? '')),
                'content' => Database::getStringReplacement((string)($h['content'] ?? '')),
                'writtenDate' => ['value' => $h['writtenDate'] ?? null, 'type' => \PDO::PARAM_STR],
                'snapshotAt' => Database::getStringReplacement((string)($h['snapshotAt'] ?? date('Y-m-d H:i:s'))),
            ]
        );
    }

    return Database::responseSuccess([
        'success' => true,
        'counts' => [
            'books' => count($books),
            'tags' => count($tags),
            'poems' => count($poems),
            'history' => count($history),
        ],
        'preResetBackup' => $preResetBackup ? [
            'id' => $preResetBackup['id'],
            'filename' => $preResetBackup['filename'],
        ] : null,
    ]);
}

// Deleting is only allowed when another backup exists for the same calendar date, so an
// admin can never be left without any backup for a given day.
function deleteBackup(Database $database): string
{
    poemCenterRequireAdmin($database);

    $backupId = $database->getIntParam('backupId');
    if (!$backupId) return Database::responseBadRequest('backupId required');

    $rows = $database->query(
        'SELECT id, path, DATE(createdAt) backupDate FROM backup WHERE id = :id AND backupType = :type AND isValid = 1',
        [
            'id' => Database::getIntReplacement($backupId),
            'type' => Database::getStringReplacement(BACKUP_TYPE_POEMS),
        ]
    );
    if (!$rows) return Database::responseNotFound(['error' => 'Backup not found.']);
    $backupRow = $rows[0];

    $sameDateCount = $database->query(
        'SELECT COUNT(*) cnt FROM backup WHERE backupType = :type AND isValid = 1 AND DATE(createdAt) = :date',
        [
            'type' => Database::getStringReplacement(BACKUP_TYPE_POEMS),
            'date' => Database::getStringReplacement($backupRow['backupDate']),
        ]
    )[0]['cnt'];
    if ((int)$sameDateCount <= 1) {
        return Database::responseBadRequest('Cannot delete the only backup for this date.');
    }

    $absPath = __DIR__ . '/../../' . $backupRow['path'];
    if (file_exists($absPath)) {
        unlink($absPath);
    }
    $database->query('DELETE FROM backup WHERE id = :id', ['id' => Database::getIntReplacement($backupId)]);

    return Database::responseSuccess(['id' => $backupId]);
}

function handleBackupPost(Database $database): string
{
    $action = $database->getStringParam('action', '');
    return match ($action) {
        'reset' => resetFromBackup($database),
        'delete' => deleteBackup($database),
        default => Database::responseBadRequest('Unknown or missing action'),
    };
}

$database = new Database();
$database->handleRequest('handleBackupGet', 'handleBackupPost');
