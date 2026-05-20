<?php

use system\Database;
use system\AccessBlock;
use system\StandardType;
use system\SqlComparison;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/AccessBlock.php");
include "../../system/Sql/event.php";

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

function modifyEvent(Database $database): string
{
    $user = $database->getUser();
    if (!$user) {
        return $database->responseUnauthorized();
    }
    $requiredParams = array(
        array(
            'param' => 'eventId',
            'type' => StandardType::NUMBER,
            'exists' => new SqlComparison(EVENT_EXISTS_SQL),
        ),
        array(
            'param' => 'date',
            'type' => StandardType::DATE,
            'required' => false
        ),
        array(
            'param' => 'startTime',
            'type' => StandardType::STRING,
            'required' => false
        ),
        array(
            'param' => 'endTime',
            'type' => StandardType::STRING,
            'required' => false
        ),
        array(
            'param' => 'eventTypeId',
            'type' => StandardType::NUMBER,
            'exists' => new SqlComparison(EVENT_TYPE_EXISTS_SQL),
            'required' => false

        ),
        array(
            'param' => 'name',
            'type' => StandardType::STRING,
            'required' => false
        ),
        array(
            'param' => 'location',
            'type' => StandardType::STRING,
            'required' => false
        )
    );
    $missingParam = AccessBlock::findMissingParam($requiredParams, $database);
    if ($missingParam) {
        return $database->responseBadRequest($missingParam);
    }
    $sql = <<<SQL
        UPDATE calendar_event
    SQL;
    $eventId = $database->getIntParam('eventId');
    $replacements = array(
        'eventId' => ['value' => $eventId, 'type' => PDO::PARAM_INT],
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_INT]
    );

    $updates = [];

    $date = $database->getStringParam('date');
    if ($date) {
        $updates[] = "date = :date";
        $replacements['date'] = ['value' => $date, 'type' => PDO::PARAM_STR];
    }

    $startTime = $database->getStringParam('startTime');
    if ($startTime) {
        $updates[] = "startTime = :startTime";
        $replacements['startTime'] = ['value' => $startTime, 'type' => PDO::PARAM_STR];
    }

    $endTime = $database->getStringParam('endTime');
    if ($endTime) {
        $updates[] = "endTime = :endTime";
        $replacements['endTime'] = ['value' => $endTime, 'type' => PDO::PARAM_STR];
    }

    $eventTypeId = $database->getIntParam('eventTypeId');
    if ($eventTypeId) {
        $updates[] = "eventTypeId = :eventTypeId";
        $replacements['eventTypeId'] = ['value' => $eventTypeId, 'type' => PDO::PARAM_INT];
    }

    $name = $database->getStringParam('name');
    if ($name) {
        $updates[] = "name = :name";
        $replacements['name'] = ['value' => $name, 'type' => PDO::PARAM_STR];
    }

    $location = $database->getStringParam('location');
    if ($location) {
        $updates[] = "location = :location";
        $replacements['location'] = ['value' => $location, 'type' => PDO::PARAM_STR];
    }

    if (empty($updates)) {
        return $database->responseBadRequest("No fields to update");
    } else {
        $sql .= ' SET ' . implode(", ", $updates);
    }

    $sql .= <<<SQL
        WHERE id = :eventId 
        AND userId = :userId
    SQL;

    $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "eventId" => $eventId
    ));
}

function deleteEvent(Database $database): string
{
    $user = $database->getUser();
    if (!$user) {
        return $database->responseUnauthorized();
    }

    $requiredParams = array(
        array(
            'param' => 'eventId',
            'type' => StandardType::NUMBER,
        )
    );
    $missingParam = AccessBlock::findMissingParam($requiredParams, $database);
    if ($missingParam) {
        return $database->responseBadRequest($missingParam);
    }

    $eventId = $database->getIntParam('eventId');
    $sql = <<<SQL
        DELETE FROM calendar_event
        WHERE id = :eventId
        AND userId = :userId
    SQL;
    $replacements = array(
        'eventId' => ['value' => $eventId, 'type' => PDO::PARAM_INT],
        'userId' => ['value' => $user->getId(), 'type' => PDO::PARAM_INT],
    );
    $database->query($sql, $replacements);

    return $database->responseSuccess(array(
        "eventId" => $eventId
    ));
}

$database = new Database();
$database->handleRequest(null, 'postEvent', 'modifyEvent', 'deleteEvent');
