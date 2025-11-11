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
    ON CONFLICT(identifier_code) DO UPDATE SET value = :value
QRY);

$settingsMap = [
    'preferredTimezone' => ['APP_TIMEZONE', 'string']
];

$settingsVal = array_reduce(array_keys($settingsMap), function($out, $in) use ($settingsMap) {
    if (! array_key_exists($in, $_POST))
        return $out;
 
    list($idCode, $type) = $settingsMap[$in];
    $out[$idCode] = [$type, $_POST[$in]];
    return $out;
}, []);

try {
    $pdo->beginTransaction();
    foreach($settingsVal as $idCode => $value) {
        list($type, $value) = $value;

        $insert->execute([
            ':code' => $idCode,
            ':value' => $value,
            ':type' => $type
        ]);
    }
    $pdo->commit();
    echo json_encode([
        "message" => "Saved!"
    ]);
} catch(PDOException $e) {
    $pdo->rollback();
    http_response_code(500);
    echo json_encode([
        "message" => "Error: ".get_class($e)." - ".$e->getMessage()
    ]);
}