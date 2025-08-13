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
            keyword1.name keyword1,
            keyword2.name keyword2,
            keyword3.name keyword3,
            house.name house,
            rarity.name rarity,
            releaseVersion.name releaseVersion,
            releaseVersion.releaseDate
        FROM hardest_card card
        JOIN hardest_cardType cardType
            ON card.cardTypeId = cardType.id
        LEFT JOIN hardest_keyword keyword1
            ON card.keywordId = keyword1.id
        LEFT JOIN hardest_keyword keyword2
            ON card.keywordId2 = keyword2.id
        LEFT JOIN hardest_keyword keyword3
            ON card.keywordId3 = keyword3.id
        JOIN hardest_house house
            ON card.houseId = house.id
        JOIN hardest_rarity rarity
            ON card.rarityId = rarity.id
        JOIN hardest_releaseVersion releaseVersion
            ON card.releaseVersionId = releaseVersion.id;
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
        $finalCards[] = $card;
    }

    return $database->responseSuccess(array(
        "countOfCards" => sizeof($finalCards),
        "cards" => $finalCards
    ));
}

$database = new Database();
$database->handleRequest('getCards');
