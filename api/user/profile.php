<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getProfile(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $rows = $database->query(
        'SELECT id, username, firstname, lastname, email, penName FROM user WHERE id = :id',
        ['id' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]]
    );
    if (!$rows) return Database::responseNotFound();

    return Database::responseSuccess($rows[0]);
}

function updateProfile(Database $database): string
{
    $user = $database->getUser();
    if (!$user) return Database::responseUnauthorized();

    $firstname = trim($database->getStringParam('firstname', ''));
    $lastname  = trim($database->getStringParam('lastname',  ''));
    $email     = trim($database->getStringParam('email',     ''));
    $penName   = trim($database->getStringParam('penName',   ''));

    if ($firstname && strlen($firstname) > 16) return Database::responseBadRequest('First name too long');
    if ($lastname  && strlen($lastname)  > 16) return Database::responseBadRequest('Last name too long');
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) return Database::responseBadRequest('Invalid email');

    $sets  = [];
    $binds = ['id' => ['value' => $user->getId(), 'type' => \PDO::PARAM_INT]];

    if ($firstname) { $sets[] = 'firstname = :fn'; $binds['fn'] = ['value' => $firstname, 'type' => \PDO::PARAM_STR]; }
    if ($lastname)  { $sets[] = 'lastname = :ln';  $binds['ln'] = ['value' => $lastname,  'type' => \PDO::PARAM_STR]; }
    if ($email)     { $sets[] = 'email = :em';     $binds['em'] = ['value' => $email,     'type' => \PDO::PARAM_STR]; }
    $sets[] = 'penName = :pn'; $binds['pn'] = ['value' => $penName ?: null, 'type' => \PDO::PARAM_STR];

    if (count($sets) > 1 || $penName !== '') {
        $database->query('UPDATE user SET ' . implode(', ', $sets) . ' WHERE id = :id', $binds);
    }

    return Database::responseSuccess(['updated' => true]);
}

$database = new Database();
$database->handleRequest('getProfile', null, 'updateProfile');