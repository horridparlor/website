<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");
include("../../system/PoemCenterHelper.php");

const POEM_STOPWORDS = [
    // Finnish
    'ja', 'on', 'ei', 'se', 'en', 'et', 'te', 'me', 'hän', 'he', 'sen', 'niin', 'kun', 'kuin',
    'mutta', 'että', 'joka', 'jos', 'kuten', 'tai', 'oli', 'olen', 'olet', 'ovat', 'ollut',
    'minä', 'sinä', 'sinä', 'minun', 'sinun', 'meidän', 'teidän', 'heidän', 'tämä', 'tuo',
    'nämä', 'nuo', 'tässä', 'siinä', 'tänne', 'sinne', 'täällä', 'siellä', 'vain', 'jo',
    'kaikki', 'kanssa', 'ilman', 'nyt', 'aina', 'koska', 'vielä', 'kuka', 'mikä', 'miksi',
    'sen', 'sitä', 'sitä', 'ne', 'niitä', 'niiden', 'yksi', 'kaksi',
    // English
    'the', 'and', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'of', 'in', 'on', 'it', 'i',
    'you', 'he', 'she', 'we', 'they', 'this', 'that', 'these', 'those', 'but', 'or', 'for',
    'with', 'as', 'at', 'by', 'not', 'be', 'been', 'so', 'if', 'my', 'your', 'his', 'her',
];

function getStats(Database $database): string
{
    poemCenterRequireAdmin($database);

    $poems = $database->query(
        'SELECT content, title, writtenDate, createdAt FROM poem WHERE isDeleted = 0'
    );

    $totalPoems = sizeof($poems);
    $totalWords = 0;
    $totalLines = 0;
    $wordCounts = [];
    $perMonth = [];

    foreach ($poems as $poem) {
        $content = (string)$poem['content'];
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        $lines = array_filter($lines, fn($l) => trim($l) !== '');
        $totalLines += sizeof($lines);

        $tokens = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower($content, 'UTF-8'));
        foreach ($tokens as $token) {
            $token = trim($token, "'");
            if (mb_strlen($token) <= 2) continue;
            if (in_array($token, POEM_STOPWORDS, true)) continue;
            $totalWords++;
            $wordCounts[$token] = ($wordCounts[$token] ?? 0) + 1;
        }

        $month = substr($poem['writtenDate'] ?: $poem['createdAt'], 0, 7);
        $perMonth[$month] = ($perMonth[$month] ?? 0) + 1;
    }

    arsort($wordCounts);
    $topWords = [];
    foreach (array_slice($wordCounts, 0, 30, true) as $word => $count) {
        $topWords[] = ['word' => $word, 'count' => $count];
    }

    ksort($perMonth);
    $poemsPerMonth = [];
    foreach ($perMonth as $month => $count) {
        $poemsPerMonth[] = ['month' => $month, 'count' => $count];
    }

    return Database::responseSuccess([
        'totalPoems' => $totalPoems,
        'totalWords' => $totalWords,
        'avgWordsPerPoem' => $totalPoems ? round($totalWords / $totalPoems, 1) : 0,
        'avgLinesPerPoem' => $totalPoems ? round($totalLines / $totalPoems, 1) : 0,
        'poemsPerMonth' => $poemsPerMonth,
        'topWords' => $topWords,
    ]);
}

$database = new Database();
$database->handleRequest('getStats');
