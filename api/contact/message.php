<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

const RATE_LIMIT_MINUTES = 1;
const MAX_MESSAGE_LENGTH = 5000;

function submitMessage(Database $database): string
{
    $email   = trim($database->getStringParam('email') ?? '');
    $message = trim($database->getStringParam('message') ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return Database::responseBadRequest('Invalid email address.');
    }
    if (!$message) {
        return Database::responseBadRequest('Message cannot be empty.');
    }
    if (strlen($message) > MAX_MESSAGE_LENGTH) {
        return Database::responseBadRequest('Message too long (max 5000 characters).');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . RATE_LIMIT_MINUTES . ' minutes'));
    $limitSeconds = RATE_LIMIT_MINUTES * 60;

    $recent = $database->query(
        "SELECT GREATEST(0, $limitSeconds - TIMESTAMPDIFF(SECOND, sendDate, NOW())) secondsLeft
         FROM contactMessage WHERE senderIp = :ip AND sendDate > :cutoff
         ORDER BY sendDate DESC LIMIT 1",
        [
            'ip'     => ['value' => $ip, 'type' => \PDO::PARAM_STR],
            'cutoff' => ['value' => $cutoff, 'type' => \PDO::PARAM_STR],
        ]
    );
    if ($recent) {
        http_response_code(429);
        return json_encode(['secondsLeft' => (int)$recent[0]['secondsLeft']]);
    }

    $database->query(
        'INSERT INTO contactMessage (email, message, senderIp) VALUES (:email, :message, :ip)',
        [
            'email'   => ['value' => $email, 'type' => \PDO::PARAM_STR],
            'message' => ['value' => $message, 'type' => \PDO::PARAM_STR],
            'ip'      => ['value' => $ip, 'type' => \PDO::PARAM_STR],
        ]
    );

    return Database::responseSuccess(['success' => true]);
}

$database = new Database();
$database->handleRequest(null, 'submitMessage');