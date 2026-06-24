<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function registerUser(Database $database): string
{
    $username  = trim($database->getStringParam('username', ''));
    $password  = $database->getStringParam('password', '');
    $email     = trim($database->getStringParam('email', ''));
    $firstname = trim($database->getStringParam('firstname', ''));
    $lastname  = trim($database->getStringParam('lastname', ''));

    if (!$username)  return Database::responseBadRequest('Username is required');
    if (!$password)  return Database::responseBadRequest('Password is required');
    if (!$email)     return Database::responseBadRequest('Email is required');
    if (!$firstname) return Database::responseBadRequest('First name is required');
    if (!$lastname)  return Database::responseBadRequest('Last name is required');

    if (!preg_match('/^[a-zA-Z0-9_]{3,16}$/', $username)) {
        return Database::responseBadRequest('Username must be 3–16 characters: letters, numbers, underscore only');
    }
    if (strlen($password) < 8) {
        return Database::responseBadRequest('Password must be at least 8 characters');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return Database::responseBadRequest('Invalid email address');
    }

    // Check uniqueness
    $existing = $database->query(
        'SELECT id FROM user WHERE username = :username OR email = :email',
        [
            'username' => ['value' => $username, 'type' => \PDO::PARAM_STR],
            'email'    => ['value' => $email,    'type' => \PDO::PARAM_STR],
        ]
    );
    if ($existing) return Database::responseBadRequest('Username or email already taken');

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $database->query(
        'INSERT INTO user (username, firstname, lastname, email, isActive, accessRights, passwordHash) VALUES (:u, :fn, :ln, :em, 1, :ar, :pw)',
        [
            'u'  => ['value' => $username,  'type' => \PDO::PARAM_STR],
            'fn' => ['value' => $firstname, 'type' => \PDO::PARAM_STR],
            'ln' => ['value' => $lastname,  'type' => \PDO::PARAM_STR],
            'em' => ['value' => $email,     'type' => \PDO::PARAM_STR],
            'ar' => ['value' => '{}',       'type' => \PDO::PARAM_STR],
            'pw' => ['value' => $hash,      'type' => \PDO::PARAM_STR],
        ]
    );
    $userId = $database->getInsertId();

    // Auto-login: create auth token
    $token      = bin2hex(random_bytes(16));
    $expiration = date('Y-m-d H:i:s', time() + 24 * 3600);
    $database->query(
        'INSERT INTO authToken (userId, token, expiration) VALUES (:uid, :token, :exp)',
        [
            'uid'   => ['value' => $userId,     'type' => \PDO::PARAM_INT],
            'token' => ['value' => $token,      'type' => \PDO::PARAM_STR],
            'exp'   => ['value' => $expiration, 'type' => \PDO::PARAM_STR],
        ]
    );

    return Database::responseSuccess([
        'authToken' => $token,
        'userId'    => $userId,
        'username'  => $username,
        'firstname' => $firstname,
        'lastname'  => $lastname,
    ]);
}

$database = new Database();
$database->handleRequest(null, 'registerUser');