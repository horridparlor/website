<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getEventTypes(Database $database): string
{
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
    $user = $database->getUser();
    $replacements = array(
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_STR],
    );
    $eventTypes = $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "countOfEventTypes" => sizeof($eventTypes),
        "eventTypes" => $eventTypes
    ));
}

$database = new Database();
$database->handleRequest('getEventTypes');
