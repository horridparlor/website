<?php

const EVENT_EXISTS_SQL = <<<SQL
    SELECT :comparedValue, "Event" entityType
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT id
        FROM calendar_event
        WHERE id = :comparedValue
    )
SQL;

const EVENT_TYPE_EXISTS_SQL = <<<SQL
    SELECT :comparedValue, "Event" entityType
    FROM DUAL
    WHERE NOT EXISTS (
        SELECT id
        FROM calendar_eventType
        WHERE id = :comparedValue
    )
SQL;
