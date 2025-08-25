<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getEvents(Database $database): string
{
    $sql = <<<SQL
        SELECT
            event.id,
            event.eventTypeId,
            event.name,
            event.location,
            color.color,
            event.date,
            event.startTime,
            event.endTime
        FROM calendar_event event
        JOIN calendar_eventType eventType
            ON eventType.id = event.eventTypeId
        JOIN calendar_color color
            ON eventType.colorId = color.id
        JOIN user
            ON event.userId = user.id
    SQL;
    $user = $database->getUser();
    $replacements = array(
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_STR],
    );
    $keywords = $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "countOfKeywords" => sizeof($keywords),
        "keywords" => $keywords
    ));
}

$database = new Database();
$database->handleRequest(null, 'getEvents');
