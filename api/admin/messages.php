<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function requireAdmin(Database $database): \system\User
{
    $user = $database->getUser();
    if (!$user || !$user->isAdmin()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    return $user;
}

function getMessages(Database $database): string
{
    requireAdmin($database);

    $countOnly = $database->getBooleanParam('countOnly', false);
    $dateFrom  = $database->getStringParam('dateFrom');
    $dateTo    = $database->getStringParam('dateTo');

    if ($countOnly) {
        $result = $database->query(
            'SELECT COUNT(*) cnt FROM contactMessage WHERE isDeleted = 0 AND isSpam = 0 AND isRead = 0'
        );
        return Database::responseSuccess(['count' => (int)$result[0]['cnt']]);
    }

    $conditions   = [];
    $replacements = [];

    if (!$dateFrom && !$dateTo) {
        $conditions[] = 'sendDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } else {
        if ($dateFrom) {
            $conditions[] = 'sendDate >= :dateFrom';
            $replacements['dateFrom'] = ['value' => $dateFrom . ' 00:00:00', 'type' => \PDO::PARAM_STR];
        }
        if ($dateTo) {
            $conditions[] = 'sendDate <= :dateTo';
            $replacements['dateTo'] = ['value' => $dateTo . ' 23:59:59', 'type' => \PDO::PARAM_STR];
        }
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $sql   = "SELECT id, email, message, sendDate, isRead, isSpam, isStarred, isDeleted
              FROM contactMessage $where ORDER BY sendDate DESC";

    $messages = $database->query($sql, $replacements);

    // Cast booleans
    foreach ($messages as &$msg) {
        $msg['isRead']    = (bool)$msg['isRead'];
        $msg['isSpam']    = (bool)$msg['isSpam'];
        $msg['isStarred'] = (bool)$msg['isStarred'];
        $msg['isDeleted'] = (bool)$msg['isDeleted'];
    }

    return Database::responseSuccess(['messages' => $messages]);
}

function updateMessage(Database $database): string
{
    requireAdmin($database);

    $id = $database->getIntParam('id');
    if (!$id) {
        return Database::responseBadRequest('Missing message id.');
    }

    $existing = $database->query(
        'SELECT isStarred FROM contactMessage WHERE id = :id',
        ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]]
    );
    if (!$existing) {
        return Database::responseNotFound();
    }
    $isCurrentlyStarred = (bool)$existing[0]['isStarred'];

    $updates      = [];
    $replacements = ['id' => ['value' => $id, 'type' => \PDO::PARAM_INT]];

    $isStarred = $database->getBooleanParam('isStarred');
    $isRead    = $database->getBooleanParam('isRead');
    $isSpam    = $database->getBooleanParam('isSpam');
    $isDeleted = $database->getBooleanParam('isDeleted');

    if (!is_null($isStarred)) {
        $updates[]              = 'isStarred = :isStarred';
        $replacements['isStarred'] = ['value' => (int)$isStarred, 'type' => \PDO::PARAM_INT];
        $isCurrentlyStarred = $isStarred; // allow same-request spam/delete if unstarring
    }
    if (!is_null($isRead)) {
        $updates[]           = 'isRead = :isRead';
        $replacements['isRead'] = ['value' => (int)$isRead, 'type' => \PDO::PARAM_INT];
    }
    if (!is_null($isSpam)) {
        if ($isSpam && $isCurrentlyStarred) {
            return Database::responseBadRequest('Remove star before marking as spam.');
        }
        $updates[]           = 'isSpam = :isSpam';
        $replacements['isSpam'] = ['value' => (int)$isSpam, 'type' => \PDO::PARAM_INT];
    }
    if (!is_null($isDeleted)) {
        if ($isDeleted && $isCurrentlyStarred) {
            return Database::responseBadRequest('Remove star before deleting.');
        }
        $updates[]              = 'isDeleted = :isDeleted';
        $replacements['isDeleted'] = ['value' => (int)$isDeleted, 'type' => \PDO::PARAM_INT];
    }

    if (!$updates) {
        return Database::responseBadRequest('No fields to update.');
    }

    $database->query(
        'UPDATE contactMessage SET ' . implode(', ', $updates) . ' WHERE id = :id',
        $replacements
    );

    return Database::responseSuccess(['success' => true]);
}

$database = new Database();
$database->handleRequest('getMessages', null, 'updateMessage');