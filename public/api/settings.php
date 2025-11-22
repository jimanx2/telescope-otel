<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';

define('DB_DATABASE', '/app/databases/telescope.sqlite');

// Database Configuration
$db = DB::instance();
$pdo = $db->getPdo();
$insert = $pdo->prepare(<<<QRY
    INSERT INTO preferences (identifier_code, value, type) VALUES (:code, :value, :type)
    ON CONFLICT(identifier_code) DO UPDATE SET value = :value, type = :type
QRY);

$settingsMap = [
    'preferredTimezone' => ['APP_TIMEZONE', 'string', fn ($val) => in_array($val, array_values(timezone_identifiers_list()))]
];

$settingsVal = array_reduce(array_keys($settingsMap), function($out, $in) use ($settingsMap) {
    if (! array_key_exists($in, $_POST))
        return $out;
 
    @list($idCode, $type, $validate) = $settingsMap[$in];

    $out[$idCode] = [$type, $_POST[$in], $validate];
    return $out;
}, []);

try {
    $pdo->beginTransaction();
    foreach($settingsVal as $idCode => $value) {
        list($type, $value, $validate) = $value;

        if (is_callable($validate) && ! $validate->__invoke($value)) {
            throw new \Exception("Validation error: " . $idCode . "_IS_INVALID");
        }
    
        $insert->execute([
            ':code' => $idCode,
            ':value' => $value,
            ':type' => $type
        ]);
    }
    $pdo->commit();
    sendResponse([
        "message" => "Saved!"
    ]);
} catch(PDOException|Exception $e) {
    $pdo->rollback();
    http_response_code(500);
    sendResponse([
        "message" => "Error: ".get_class($e)." - ".$e->getMessage()
    ]);
}

exit;

function sendResponse($responseObject)
{
    if (! headers_sent()) {
        header("Content-Type: application/json");
    }
    echo json_encode($responseObject);
}