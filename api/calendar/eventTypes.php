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
    SQL;
    $eventTypes = $database->query($sql);

    return $database->responseSuccess(array(
        "countOfEventTypes" => sizeof($eventTypes),
        "eventTypes" => $eventTypes
    ));
}

$database = new Database();
$database->handleRequest(null, 'getEventTypes');
