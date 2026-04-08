<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getEvents(Database $database): string
{
    $user = $database->getUser();
    if (!$user) {
        return $database->responseUnauthorized();
    }
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
        WHERE event.userId = :userId
    SQL;
    $replacements = array(
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_INT],
    );
    $events = $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "countOfEvents" => sizeof($events),
        "events" => $events
    ));
}

$database = new Database();
$database->handleRequest('getEvents');

