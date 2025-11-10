<?php
declare(strict_types=1);

use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Logs\V1\LogRecord;

require_once __DIR__ . '/../../../vendor/autoload.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ct, 'application/x-protobuf') === false) {
    http_response_code(415);
    echo json_encode(['error' => 'unsupported_media_type']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$ce = strtolower($_SERVER['HTTP_CONTENT_ENCODING'] ?? '');
if ($ce === 'gzip' || $ce === 'x-gzip') {
    $raw = gzdecode($raw);
    if ($raw === false) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_gzip']);
        exit;
    }
}

try {
    $req = new ExportLogsServiceRequest();
    $req->mergeFromString($raw);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_protobuf']);
    exit;
}

$pdo = db();
$now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$insert = $pdo->prepare('INSERT OR REPLACE INTO debug_entries (uuid,type,content,created_at) VALUES (:uuid,:type,:content,:created_at)');

$inserted = 0;

foreach ($req->getResourceLogs() as $rl) {
    $resourceAttrs = kv_list_to_assoc($rl->getResource()->getAttributes());

    foreach ($rl->getScopeLogs() as $sl) {
        foreach ($sl->getLogRecords() as $lr) {
            $attrs = kv_list_to_assoc($lr->getAttributes()) + $resourceAttrs;

            $traceId = bin2hex($lr->getTraceId());
            $spanId  = bin2hex($lr->getSpanId());

            $body = any_to_php($lr->getBody());
            $msg  = is_scalar($body) ? (string)$body : json_encode($body);

            $content = [
                'message'   => $msg,
                'severity'  => $lr->getSeverityText() ?: null,
                'timestamp' => (string)$lr->getTimeUnixNano(),
                'trace_id'  => $traceId ?: null,
                'span_id'   => $spanId ?: null,
                'attributes'=> $attrs,
                'resource'  => $resourceAttrs,
            ];

            $uuid = ($traceId ?: bin2hex(random_bytes(16))) . '-' .
                    ($spanId  ?: bin2hex(random_bytes(8)))  . '-log-' . $lr->getObservedTimeUnixNano();

            $insert->execute([
                ':uuid' => $uuid,
                ':type' => 'log',
                ':content' => json_encode($content, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                ':created_at' => $now,
            ]);
            $inserted++;
        }
    }
}

echo json_encode(['ok' => true, 'inserted' => $inserted]);

// ---------- helpers ----------
function db(): PDO {
    $dbDir = '/app/databases';
    $dbFile = $dbDir . '/telescope.sqlite';
    if (!is_dir($dbDir)) { @mkdir($dbDir, 0777, true); }
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
    $pdo->exec('CREATE TABLE IF NOT EXISTS debug_entries (
        uuid TEXT PRIMARY KEY,
        type TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_entries_type_created ON debug_entries(type, created_at)');
    return $pdo;
}

function any_to_php(\Opentelemetry\Proto\Common\V1\AnyValue $v) {
    if (method_exists($v, 'hasStringValue') && $v->hasStringValue()) return $v->getStringValue();
    if (method_exists($v, 'hasBoolValue')   && $v->hasBoolValue())   return (bool)$v->getBoolValue();
    if (method_exists($v, 'hasIntValue')    && $v->hasIntValue())    return (int)$v->getIntValue();
    if (method_exists($v, 'hasDoubleValue') && $v->hasDoubleValue()) return (float)$v->getDoubleValue();
    if (method_exists($v, 'hasBytesValue')  && $v->hasBytesValue())  return base64_encode($v->getBytesValue());

    if (method_exists($v, 'hasArrayValue') && $v->hasArrayValue()) {
        $arr = [];
        foreach ($v->getArrayValue()->getValues() as $vv) {
            $arr[] = any_to_php($vv);
        }
        return $arr;
    }

    if (method_exists($v, 'hasKvlistValue') && $v->hasKvlistValue()) {
        return kv_list_to_assoc($v->getKvlistValue()->getValues());
    }

    return null;
}

/** @param \Google\Protobuf\RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|array $list */
function kv_list_to_assoc($list): array {
    $out = [];
    foreach ($list as $kv) {
        $out[$kv->getKey()] = any_to_php($kv->getValue());
    }
    return $out;
}
