<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getKeywords(Database $database): string
{
    $sql = <<<SQL
        SELECT
            keyword.id,
            keyword.name,
            keyword.displayName,
            keyword.description
        FROM isBack_keyword keyword;
    SQL;
    $keywords = $database->query($sql);

    return $database->responseSuccess(array(
        "countOfKeywords" => sizeof($keywords),
        "keywords" => $keywords
    ));
}

$database = new Database();
$database->handleRequest('getKeywords');
