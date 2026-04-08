<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function postEvent(Database $database): string
{
    $user = $database->getUser();
    if (!$user) {
        return $database->responseUnauthorized();
    }
    $sql = <<<SQL
        INSERT INTO calendar_event (
            userId,
            date,
            startTime,
            endTime,
            eventTypeId,
            name,
            location
        )
        VALUES (
            :userId,
            :date,
            :startTime,
            :endTime,
            :eventTypeId,
            :name,
            :location
        )
    SQL;
    $date = $database->getStringParam('date');
    $startTime = $database->getStringParam('startTime');
    $endTime = $database->getStringParam('endTime');
    $eventTypeId = $database->getIntParam('eventType');
    $name = $database->getStringParam('name');
    $location = $database->getStringParam('location');
    $replacements = array(
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_INT],
        'date' => ['value' => $date, 'type' => PDO::PARAM_STR],
        'startTime' => ['value' => $startTime, 'type' => PDO::PARAM_STR],
        'endTime' => ['value' => $endTime, 'type' => PDO::PARAM_STR],
        'eventTypeId' => ['value' => $eventTypeId, 'type' => PDO::PARAM_INT],
        'name' => ['value' => $name, 'type' => PDO::PARAM_STR],
        'location' => ['value' => $location, 'type' => PDO::PARAM_STR]
    );
    $events = $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "countOfEvents" => sizeof($events),
        "events" => $events
    ));
}

$database = new Database();
$database->handleRequest(null, 'postEvent');
