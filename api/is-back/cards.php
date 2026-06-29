<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

function getCards(Database $database): string
{
    $sql = <<<SQL
        SELECT
            card.id,
            card.name,
            cardType.name type,
            card.power,
            card.altArts,
            keyword1.name keyword1,
            keyword2.name keyword2,
            keyword3.name keyword3,
            card.reminderVisible,
            card.reminder2Visible,
            card.reminder3Visible,
            YEAR(card.created_at) releaseYear
        FROM isBack_card card
        JOIN isBack_cardType cardType
            ON card.cardTypeId = cardType.id
        LEFT JOIN isBack_keyword keyword1
            ON card.keywordId = keyword1.id
        LEFT JOIN isBack_keyword keyword2
            ON card.keyword2Id = keyword2.id
        LEFT JOIN isBack_keyword keyword3
            ON card.keyword3Id = keyword3.id;
    SQL;
    $cards = $database->query($sql);
    $finalCards = array();
    foreach ($cards as $card) {
        $keywords = [];
        for ($i = 1; $i <= 3; $i++) {
            $field = 'keyword' . $i;
            if (!empty($card[$field])) {
                $keywords[] = $card[$field];
            }
            unset($card[$field]);
        }
        $card['keywords'] = $keywords;
        $card['altArts'] = isset($card['altArts']) && $card['altArts'] !== null ? (int)$card['altArts'] : null;
        $finalCards[] = $card;
    }

    return $database->responseSuccess(array(
        "countOfCards" => sizeof($finalCards),
        "cards" => $finalCards
    ));
}

$database = new Database();
$database->handleRequest('getCards');
