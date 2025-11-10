<?php
declare(strict_types=1);

use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Opentelemetry\Proto\Common\V1\AnyValue;
use Opentelemetry\Proto\Common\V1\KeyValue;
use Opentelemetry\Proto\Trace\V1\Span;

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
    $req = new ExportTraceServiceRequest();
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

foreach ($req->getResourceSpans() as $rs) {
    $resourceAttrs = kv_list_to_assoc($rs->getResource()->getAttributes());

    foreach ($rs->getScopeSpans() as $ss) {
        foreach ($ss->getSpans() as $sp) {
            $spanName = $sp->getName();
            $spanKind = $sp->getKind();
            $status   = $sp->getStatus()->getCode(); // 0 unset, 1 ok, 2 error

            $attrs = kv_list_to_assoc($sp->getAttributes());
            $events = [];
            foreach ($sp->getEvents() as $ev) {
                $events[] = [
                    'name' => $ev->getName(),
                    'attributes' => kv_list_to_assoc($ev->getAttributes()),
                    'time_unix_nano' => (string)$ev->getTimeUnixNano(),
                ];
            }

            $class = classify_span($spanName, $spanKind, $attrs + $resourceAttrs, $events, status_code_name($status));
            $uuid  = bin2hex($sp->getTraceId()) . '-' . bin2hex($sp->getSpanId());
            $content = $class['content'] + [
                'trace_id' => bin2hex($sp->getTraceId()),
                'span_id'  => bin2hex($sp->getSpanId()),
                'resource' => $resourceAttrs,
            ];

            $insert->execute([
                ':uuid' => $uuid,
                ':type' => $class['type'],
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

function kv_to_php(\Opentelemetry\Proto\Common\V1\AnyValue $v) {
    if (method_exists($v, 'hasStringValue') && $v->hasStringValue()) return $v->getStringValue();
    if (method_exists($v, 'hasBoolValue')   && $v->hasBoolValue())   return (bool)$v->getBoolValue();
    if (method_exists($v, 'hasIntValue')    && $v->hasIntValue())    return (int)$v->getIntValue();
    if (method_exists($v, 'hasDoubleValue') && $v->hasDoubleValue()) return (float)$v->getDoubleValue();
    if (method_exists($v, 'hasBytesValue')  && $v->hasBytesValue())  return base64_encode($v->getBytesValue());

    if (method_exists($v, 'hasArrayValue') && $v->hasArrayValue()) {
        $arr = [];
        foreach ($v->getArrayValue()->getValues() as $vv) {
            $arr[] = kv_to_php($vv);
        }
        return $arr;
    }

    if (method_exists($v, 'hasKvlistValue') && $v->hasKvlistValue()) {
        return kv_list_to_assoc($v->getKvlistValue()->getValues());
    }

    // Unknown/empty
    return null;
}

/** @param \Google\Protobuf\RepeatedField<\Opentelemetry\Proto\Common\V1\KeyValue>|array $list */
function kv_list_to_assoc($list): array {
    $out = [];
    foreach ($list as $kv) {
        $out[$kv->getKey()] = kv_to_php($kv->getValue());
    }
    return $out;
}

function status_code_name(int $code): string {
    return $code === 2 ? 'ERROR' : ($code === 1 ? 'OK' : 'UNSET');
}

function attrs_get(array $attrs, string $key, $default = null) {
    $lk = strtolower($key);
    foreach ($attrs as $k => $v) if (strtolower($k) === $lk) return $v;
    return $default;
}
function attrs_has(array $attrs, array $keys): bool {
    foreach ($keys as $k) if (attrs_get($attrs, $k) !== null) return true;
    return false;
}
function first_attr(array $attrs, array $keys, $default = null) {
    foreach ($keys as $k) { $v = attrs_get($attrs, $k); if ($v !== null && $v !== '') return $v; }
    return $default;
}

function span_has_exception(array $events, array $attrs): bool {
    foreach ($events as $ev) if (isset($ev['name']) && strtolower($ev['name']) === 'exception') return true;
    $otel = attrs_get($attrs, 'otel.status_code');
    if ($otel && strtoupper((string)$otel) === 'ERROR') return true;
    return attrs_get($attrs, 'error') === true;
}

function is_sql_span(string $name, array $attrs): bool {
    if (attrs_has($attrs, [
        'db.statement','db.system','db.operation','db.operation.name',
        'db.query','db.query.text','db.sql.text',
        'sql.query','sql.query.text','sql.query.parameterized','sql','sql.text',
    ])) return true;
    $n = strtolower($name);
    if (preg_match('/\b(sql|query)\b/', $n)) return true;
    return (bool)preg_match('/^(select|insert|update|delete|merge|begin|commit|rollback)\b/i', $name);
}
function is_httpish(array $attrs): bool {
    return attrs_has($attrs, ['http.method','http.target','http.route','http.url','http.status_code']);
}
function is_rpc(array $attrs): bool {
    return attrs_has($attrs, ['rpc.system','rpc.service','rpc.method']);
}
function is_messaging(array $attrs): bool {
    return attrs_has($attrs, ['messaging.system','messaging.destination','messaging.operation']);
}

function build_http_payload(string $name, array $attrs): array {
    return [
        'name'   => $name,
        'method' => first_attr($attrs, ['http.method']),
        'target' => first_attr($attrs, ['http.target','http.route','http.url']),
        'host'   => first_attr($attrs, ['http.host','net.peer.name','server.address']),
        'status' => first_attr($attrs, ['http.status_code']),
        'attributes' => $attrs,
    ];
}
function build_query_payload(string $name, array $attrs): array {
    $statement = first_attr($attrs, [
        'db.statement','db.query.text','db.sql.text','db.query',
        'sql.query.text','sql.query.parameterized','sql.query','sql.text','sql',
    ]);
    if (!$statement && preg_match('/^(select|insert|update|delete|merge|begin|commit|rollback)\b/i', $name)) {
        $statement = $name;
    }
    return [
        'sql'        => $statement,
        'operation'  => first_attr($attrs, ['db.operation','db.operation.name']),
        'system'     => first_attr($attrs, ['db.system','db.vendor']),
        'database'   => first_attr($attrs, ['db.name','db.schema','db.instance']),
        'user'       => first_attr($attrs, ['db.user']),
        'attributes' => $attrs,
        'name'       => $name,
    ];
}
function build_exception_payload(string $name, array $attrs, array $events, string $statusCode): array {
    $exc = ['type'=>null,'message'=>null,'stacktrace'=>null];
    foreach ($events as $ev) {
        if (isset($ev['name']) && strtolower($ev['name']) === 'exception') {
            $ea = $ev['attributes'] ?? [];
            $exc['type']       = first_attr($ea, ['exception.type']);
            $exc['message']    = first_attr($ea, ['exception.message']);
            $exc['stacktrace'] = first_attr($ea, ['exception.stacktrace']);
            break;
        }
    }
    return ['name'=>$name,'status_code'=>$statusCode,'exception'=>$exc,'attributes'=>$attrs];
}

function classify_span(string $name, int $kind, array $attrs, array $events, string $statusCode): array {
    if ($statusCode === 'ERROR' || span_has_exception($events, $attrs)) {
        return ['type' => 'exception', 'content' => build_exception_payload($name, $attrs, $events, $statusCode)];
    }
    if (is_sql_span($name, $attrs)) {
        return ['type' => 'query', 'content' => build_query_payload($name, $attrs)];
    }
    if (is_httpish($attrs)) {
        if ($kind === Span\SpanKind::SPAN_KIND_SERVER) return ['type'=>'request','content'=>build_http_payload($name,$attrs)];
        if ($kind === Span\SpanKind::SPAN_KIND_CLIENT) return ['type'=>'client-request','content'=>build_http_payload($name,$attrs)];
        return ['type'=>'request','content'=>build_http_payload($name,$attrs)];
    }
    if (is_rpc($attrs)) {
        return ['type'=>'rpc','content'=>[
            'system'=>first_attr($attrs,['rpc.system']),
            'service'=>first_attr($attrs,['rpc.service']),
            'method'=>first_attr($attrs,['rpc.method']),
            'name'=>$name,'attributes'=>$attrs,
        ]];
    }
    if (is_messaging($attrs)) {
        return ['type'=>'messaging','content'=>[
            'system'=>first_attr($attrs,['messaging.system']),
            'destination'=>first_attr($attrs,['messaging.destination']),
            'operation'=>first_attr($attrs,['messaging.operation']),
            'name'=>$name,'attributes'=>$attrs,
        ]];
    }
    $n = strtolower($name);
    if (preg_match('/\b(http|request|get|post|put|delete|patch)\b/', $n)) {
        return ['type'=>'request','content'=>['name'=>$name,'attributes'=>$attrs]];
    }
    if (preg_match('/\b(client|fetch|call|invoke|outbound)\b/', $n)) {
        return ['type'=>'client-request','content'=>['name'=>$name,'attributes'=>$attrs]];
    }
    return ['type'=>'unknown','content'=>['name'=>$name,'attributes'=>$attrs]];
}
