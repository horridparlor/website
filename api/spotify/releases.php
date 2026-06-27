<?php

require_once '../../system/loadEnv.php';
loadEnv();

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

$clientId     = getenv('SPOTIFY_CLIENT_ID');
$clientSecret = getenv('SPOTIFY_CLIENT_SECRET');
$artistId     = '6zEclwyLsW20Rp4ui3vGNS';

if (!$clientId || !$clientSecret) {
    echo json_encode([]);
    exit;
}

$tokenCtx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Basic " . base64_encode("$clientId:$clientSecret"),
    'content'       => 'grant_type=client_credentials',
    'ignore_errors' => true,
]]);
$tokenJson = @file_get_contents('https://accounts.spotify.com/api/token', false, $tokenCtx);
$token     = json_decode($tokenJson, true)['access_token'] ?? null;

if (!$token) {
    http_response_code(502);
    echo json_encode([]);
    exit;
}

$albumCtx = stream_context_create(['http' => [
    'header'        => "Authorization: Bearer $token",
    'ignore_errors' => true,
]]);
$url  = "https://api.spotify.com/v1/artists/{$artistId}/albums?include_groups=album,single&limit=50&market=FI";
$body = @file_get_contents($url, false, $albumCtx);
$data = json_decode($body, true);

$releases = array_map(fn($item) => [
    'id'         => $item['id'],
    'title'      => $item['name'],
    'year'       => substr($item['release_date'] ?? '', 0, 4),
    'cover'      => $item['images'][0]['url'] ?? null,
    'trackCount' => $item['total_tracks'] ?? null,
], $data['items'] ?? []);

echo json_encode(array_values($releases));