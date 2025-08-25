<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getEventTypes(Database $database): string
{
    $user = $database->getUser();
    if (!$user) {
        return $database->responseUnauthorized();
    }
    $sql = <<<SQL
        SELECT
            eventType.id,
            eventType.name,
            eventType.defaultEventName,
            color.color 
        FROM calendar_eventType eventType
        JOIN calendar_color color
            ON eventType.colorId = color.id
        WHERE eventType.userId = :userId
    SQL;
    $replacements = array(
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_INT],
    );
    $eventTypes = $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "countOfEventTypes" => sizeof($eventTypes),
        "eventTypes" => $eventTypes
    ));
}

$database = new Database();
$database->handleRequest('getEventTypes');
