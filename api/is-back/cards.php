<?php

use system\Database;

header('Content-Type: application/json');

include("../../system/Database.php");

const CARD_ROW_COLUMNS =
    "card.id, card.name, cardType.name type, card.cardTypeId, card.power, card.altArts, " .
    "card.keywordId, card.keyword2Id, card.keyword3Id, " .
    "keyword1.name keyword1, keyword2.name keyword2, keyword3.name keyword3, " .
    "card.reminderVisible, card.reminder2Visible, card.reminder3Visible, " .
    "YEAR(card.created_at) releaseYear";

const CARD_ROW_JOINS =
    "FROM isBack_card card " .
    "JOIN isBack_cardType cardType ON card.cardTypeId = cardType.id " .
    "LEFT JOIN isBack_keyword keyword1 ON card.keywordId = keyword1.id " .
    "LEFT JOIN isBack_keyword keyword2 ON card.keyword2Id = keyword2.id " .
    "LEFT JOIN isBack_keyword keyword3 ON card.keyword3Id = keyword3.id";

function mapCardRow(array $card): array {
    $keywords = [];
    for ($i = 1; $i <= 3; $i++) {
        $field = 'keyword' . $i;
        if (!empty($card[$field])) {
            $keywords[] = $card[$field];
        }
        unset($card[$field]);
    }
    $card['keywords']   = $keywords;
    $card['cardTypeId'] = (int) $card['cardTypeId'];
    $card['power']      = (int) $card['power'];
    $card['altArts']    = isset($card['altArts']) && $card['altArts'] !== null ? (int) $card['altArts'] : null;
    $card['keywordId']  = isset($card['keywordId']) && $card['keywordId'] !== null ? (int) $card['keywordId'] : null;
    $card['keyword2Id'] = isset($card['keyword2Id']) && $card['keyword2Id'] !== null ? (int) $card['keyword2Id'] : null;
    $card['keyword3Id'] = isset($card['keyword3Id']) && $card['keyword3Id'] !== null ? (int) $card['keyword3Id'] : null;
    return $card;
}

function getCards(Database $database): string
{
    $sql = "SELECT " . CARD_ROW_COLUMNS . "\n" . CARD_ROW_JOINS . ";";
    $rows = $database->query($sql);
    $finalCards = array_map('mapCardRow', $rows);

    $user = $database->getUser();
    $canManageCards = $user && ($user->isAdmin() || $user->canManageCards());

    return $database->responseSuccess(array(
        "countOfCards"   => sizeof($finalCards),
        "cards"          => $finalCards,
        "canManageCards" => $canManageCards,
    ));
}

function nullableIntReplacement(mixed $value): array {
    if (is_null($value)) {
        return ['value' => null, 'type' => \PDO::PARAM_NULL];
    }
    return ['value' => intval($value), 'type' => \PDO::PARAM_INT];
}

// ── Card image file naming — mirrors the JS convention in card-gallery/expansions ──
// (id) - (name, minus BBCode tags and a leading "The "), optionally " (N)" for alt art.

function removeBbcodeTags(string $s): string {
    return preg_replace('/\[[^\]]*\]/', '', $s);
}

function cardImgStem(int $id, string $name): string {
    $cleaned = removeBbcodeTags($name);
    if (str_starts_with($cleaned, 'The ')) {
        $cleaned = substr($cleaned, 4);
    }
    // Defensive: names are admin-entered but still shouldn't be able to escape the target directory.
    $cleaned = str_replace(['/', '\\', "\0"], '', $cleaned);
    return "$id - $cleaned";
}

function cardArtDir(): string { return __DIR__ . '/../../is-back/card-art/'; }
function cardImageDir(): string { return __DIR__ . '/../../is-back/card-images/'; }

// These directories are gitignored, so a fresh checkout won't have them yet. Create on
// demand rather than failing; the chmod is a fallback for the rare case ownership drifts
// (normally www-data's uid is aligned with the host user that owns these bind-mounted dirs).
function ensureWritableDir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
}

function cardFileName(int $id, string $name, int $artVersion): string {
    $suffix = $artVersion > 1 ? " ($artVersion)" : '';
    return cardImgStem($id, $name) . $suffix . '.png';
}

// Finds the on-disk file for a given card id + art version. Tries the exact filename the stored
// name would produce first; if that's missing (the file on disk has drifted from the DB name —
// e.g. renamed by hand, or a past edit that didn't propagate), falls back to a wildcard match on
// just the id prefix, e.g. "95 - *.png" for card 95, so a rename still finds and fixes it up.
function locateCardFile(string $dir, int $id, string $expectedName, int $artVersion, int $maxVersion): ?string {
    $exact = $dir . cardFileName($id, $expectedName, $artVersion);
    if (file_exists($exact)) return $exact;

    $suffix  = $artVersion > 1 ? " ($artVersion)" : '';
    $pattern = $dir . "$id - *" . $suffix . '.png';
    $matches = glob($pattern, GLOB_NOSORT) ?: [];

    if ($artVersion === 1) {
        // A bare "<id> - *.png" wildcard also matches alt-art files like "<id> - Foo (2).png" —
        // exclude those so the base version's fallback doesn't steal an alt-art file.
        $matches = array_values(array_filter($matches, function ($path) use ($maxVersion) {
            for ($v = 2; $v <= $maxVersion; $v++) {
                if (str_ends_with($path, " ($v).png")) return false;
            }
            return true;
        }));
    }

    return $matches[0] ?? null;
}

// Renames the card's files on disk to match its new name. Returns a list of human-readable
// failure messages (e.g. permission denied) for files that existed but couldn't be renamed —
// callers should surface these instead of swallowing them, since a silent failure here means the
// DB and the actual image filenames have quietly gone out of sync.
function renameCardImages(int $id, string $oldName, string $newName, ?int $altArts): array {
    if ($oldName === $newName) return [];
    $maxVersion = ($altArts ?? 0) + 1;
    $failures = [];
    foreach ([cardArtDir(), cardImageDir()] as $dir) {
        ensureWritableDir($dir);
        for ($v = 1; $v <= $maxVersion; $v++) {
            $oldPath = locateCardFile($dir, $id, $oldName, $v, $maxVersion);
            if (!$oldPath) continue; // missing on disk for this folder/version — leave it, rename whatever does exist
            $newPath = $dir . cardFileName($id, $newName, $v);
            if ($oldPath === $newPath) continue;
            error_clear_last();
            if (!@rename($oldPath, $newPath)) {
                $reason = error_get_last()['message'] ?? 'unknown error';
                $failures[] = basename($oldPath) . ' → ' . basename($newPath) . ' (' . $reason . ')';
            }
        }
    }
    return $failures;
}

function handleCardImageUpload(Database $database): string {
    $id         = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $kind       = $_POST['kind'] ?? '';
    $artVersion = isset($_POST['artVersion']) ? max(1, intval($_POST['artVersion'])) : 1;

    if (!$id) return Database::responseBadRequest('Missing id');
    if (!in_array($kind, ['art', 'image'], true)) return Database::responseBadRequest('Invalid kind');
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return Database::responseBadRequest('No file uploaded');
    }

    $rows = $database->query(
        'SELECT name, altArts FROM isBack_card WHERE id = :id',
        ['id' => Database::getIntReplacement($id)]
    );
    if (!$rows) return Database::responseBadRequest('Card not found');
    $name       = $rows[0]['name'];
    $maxVersion = (isset($rows[0]['altArts']) && $rows[0]['altArts'] !== null ? intval($rows[0]['altArts']) : 0) + 1;
    if ($artVersion > $maxVersion) {
        return Database::responseBadRequest("Art version exceeds this card's altArts count ($maxVersion)");
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
        return Database::responseBadRequest('File too large (max 10MB)');
    }
    $mime = function_exists('mime_content_type') ? mime_content_type($tmpPath) : null;
    if ($mime !== 'image/png') {
        return Database::responseBadRequest('Only PNG images are supported');
    }

    $dir        = $kind === 'art' ? cardArtDir() : cardImageDir();
    ensureWritableDir($dir);
    $targetPath = $dir . cardFileName($id, $name, $artVersion);
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return Database::responseBadRequest('Failed to save uploaded file');
    }

    return Database::responseSuccess(['uploaded' => true]);
}

function postCards(Database $database): string {
    $user = $database->getUser();
    if (!$user || !($user->isAdmin() || $user->canManageCards())) {
        return Database::responseUnauthorized();
    }

    if (!empty($_FILES)) {
        return handleCardImageUpload($database);
    }

    $data = $database->getRequestData();
    $id   = isset($data->id) ? intval($data->id) : 0;
    if (!$id) return Database::responseBadRequest('Missing id');

    $existingRows = $database->query(
        'SELECT id, name, altArts FROM isBack_card WHERE id = :id',
        ['id' => Database::getIntReplacement($id)]
    );
    if (!$existingRows) return Database::responseBadRequest('Card not found');
    $existing = $existingRows[0];

    $name = isset($data->name) ? trim(strval($data->name)) : '';
    if ($name === '') return Database::responseBadRequest('Name is required');

    $power = isset($data->power) && $data->power !== '' ? intval($data->power) : null;
    if (is_null($power) || $power < 0) return Database::responseBadRequest('Power is required');

    $cardTypeId = isset($data->cardTypeId) ? intval($data->cardTypeId) : 0;
    $validType  = $database->query(
        'SELECT id FROM isBack_cardType WHERE id = :id',
        ['id' => Database::getIntReplacement($cardTypeId)]
    );
    if (!$validType) return Database::responseBadRequest('Invalid type');

    $keywordFields = ['keywordId', 'keyword2Id', 'keyword3Id'];
    $keywordIds    = [];
    foreach ($keywordFields as $field) {
        $val = (isset($data->$field) && $data->$field !== '' && !is_null($data->$field))
            ? intval($data->$field)
            : null;
        $keywordIds[$field] = $val;
    }
    $nonNull = array_filter([$keywordIds['keywordId'], $keywordIds['keyword2Id'], $keywordIds['keyword3Id']], fn($v) => $v !== null);
    if (count($nonNull) !== count(array_unique($nonNull))) {
        return Database::responseBadRequest('The same keyword is selected more than once');
    }

    $database->query(
        <<<SQL
            UPDATE isBack_card
            SET name = :name,
                cardTypeId = :cardTypeId,
                power = :power,
                keywordId = :keywordId,
                keyword2Id = :keyword2Id,
                keyword3Id = :keyword3Id
            WHERE id = :id
        SQL,
        [
            'id'         => Database::getIntReplacement($id),
            'name'       => Database::getStringReplacement($name),
            'cardTypeId' => Database::getIntReplacement($cardTypeId),
            'power'      => Database::getIntReplacement($power),
            'keywordId'  => nullableIntReplacement($keywordIds['keywordId']),
            'keyword2Id' => nullableIntReplacement($keywordIds['keyword2Id']),
            'keyword3Id' => nullableIntReplacement($keywordIds['keyword3Id']),
        ]
    );

    $renameFailures = renameCardImages($id, $existing['name'], $name, isset($existing['altArts']) ? intval($existing['altArts']) : null);

    $row = $database->query(
        "SELECT " . CARD_ROW_COLUMNS . "\n" . CARD_ROW_JOINS . " WHERE card.id = :id",
        ['id' => Database::getIntReplacement($id)]
    );
    $response = ['card' => mapCardRow($row[0])];
    if ($renameFailures) {
        $response['error'] = 'Card saved, but failed to rename image file(s) on disk: ' . implode('; ', $renameFailures);
    }
    return Database::responseSuccess($response);
}

$database = new Database();
$database->handleRequest('getCards', 'postCards');
