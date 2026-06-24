<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function changePassword(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $currentPassword = $database->getStringParam('currentPassword', '');
    $newPassword     = $database->getStringParam('newPassword', '');

    if (!$currentPassword) return Database::responseBadRequest('Current password is required');
    if (!$newPassword)     return Database::responseBadRequest('New password is required');
    if (strlen($newPassword) < 8) return Database::responseBadRequest('New password must be at least 8 characters');

    $row = $database->query(
        'SELECT passwordHash FROM user WHERE id = :id',
        ['id' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
    );
    if (!$row) return Database::responseNotFound();

    if (!password_verify($currentPassword, $row[0]['passwordHash'])) {
        return Database::responseUnauthorized(['error' => 'Current password is incorrect']);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $database->query(
        'UPDATE user SET passwordHash = :hash WHERE id = :id',
        [
            'hash' => ['value' => $newHash, 'type' => \PDO::PARAM_STR],
            'id'   => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT],
        ]
    );

    return Database::responseSuccess(['changed' => true]);
}

$database = new Database();
$database->handleRequest(null, null, 'changePassword');