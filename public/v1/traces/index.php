<?php

// 1. Ensure the autoloader is included from the project root
require '/app/vendor/autoload.php';

define('DB_DATABASE', '/app/databases/telescope.sqlite');

// Database Configuration
$db = DB::instance();

// 2. *** CRITICAL UPDATE: USE THE CORRECT NAMESPACE ***
// The class is under 'Opentelemetry' and includes the 'Collector' path.
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;

// --- Remaining receiver logic remains the same ---

// 1. Basic Request Validation (assuming the file is routed to /v1/traces)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !str_ends_with($_SERVER['REQUEST_URI'], '/v1/traces')) {
    http_response_code(404);
    echo "Not Found";
    exit;
}

// ... (Content-Type and Raw Body reading logic)

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!str_contains($contentType, 'application/x-protobuf')) {
    http_response_code(415); // Unsupported Media Type
    echo "Expected Content-Type: application/x-protobuf";
    exit;
}

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    exit("Bad Request: Empty body");
}

// 3. Deserialize the Protobuf Payload
try {
    // Instantiate the request object using the newly corrected namespace
    $request = new ExportTraceServiceRequest();
    
    // Parse the raw binary string into the Protobuf object
    $request->mergeFromString($rawBody);
    
    // 4. Process the Trace Data (Example: logging span names)
    $spansCount = 0;
    foreach ($request->getResourceSpans() as $resourceSpans) {
        $serviceName = 'unknown'; // Logic to extract service name attribute...
        foreach ($resourceSpans->getScopeSpans() as $scopeSpans) {
            foreach ($scopeSpans->getSpans() as $span) {
                $spansCount++;

                // Determine span kind
                $kind = $span->getKind(); // integer
                $kindStr = match($kind) {
                    0 => 'UNSPECIFIED',
                    1 => 'INTERNAL',
                    2 => 'SERVER',
                    3 => 'CLIENT',
                    4 => 'PRODUCER',
                    5 => 'CONSUMER',
                    default => 'UNKNOWN',
                };
                $debugEntryKind = match($kindStr) {
                    'SERVER' => 'request',
                    'CLIENT' => (str_starts_with($span->getName(), 'sql ') ? 'query' : 'client-request'),
                    default  => 'unknown'
                };

                // Collect span attributes
                $spanAttrs = [];
                foreach ($span->getAttributes() as $kv) {
                    $key = $kv->getKey();
                    $valObj = $kv->getValue();

                    $spanAttrs[$key] = decode_anyvalue($valObj);
                }

                $uuid = sprintf("%s-%s", bin2hex($span->getTraceId()), bin2hex($span->getSpanId()));

                $content = [];

                if (in_array($debugEntryKind, ['request', 'client-request'])) {
                    $content = [
                        'uri' => arr_sole_by_key($spanAttrs, ['http.target', 'http.route']),
                        'method' => arr_sole_by_key($spanAttrs, ['http.method', 'http.request.method']),
                        'headers' => arr_sole_by_key($spanAttrs, ['http.request.header'], []),
                        'payload' => arr_sole_by_key($spanAttrs, ['http.request.body'], null),
                        'response' => [
                            'status_code' => arr_sole_by_key($spanAttrs, ['http.status_code', 'http.response.status_code']),
                            'headers' => arr_sole_by_key($spanAttrs, ['http.response.header'], []),
                            'content' => arr_sole_by_key($spanAttrs, ['http.response.body'], '...')
                        ]
                    ];
                }

                if (in_array($debugEntryKind, ['unknown'])) {
                    $content = [
                        'name' => $span->getName(),
                        'attributes' => $spanAttrs
                    ];
                }

                if (in_array($debugEntryKind, ['query'])) {
                    $sql = arr_sole_by_key($spanAttrs, ['db.query.text']);
                    $content = [
                        'sql' => $sql,
                        'attributes' => $spanAttrs
                    ];
                }

                $db->query(
                    'INSERT INTO debug_entries (uuid, type, content) VALUES (?, ?, ?)', 
                    $uuid, // uuid
                    $debugEntryKind, // type
                    json_encode($content), // content
                );

                error_log(sprintf(
                    "✅ Span %s received: Name='%s', UUID='%s', Attributes=%s",
                    sprintf('%s:%s', $debugEntryKind, $kindStr),
                    $span->getName(),
                    $uuid,
                    json_encode($spanAttrs)
                ));
            }
        }
    }

    // 5. Send the OTLP Success Response
    http_response_code(200);
    header('Content-Type: application/x-protobuf');
    echo ""; 
    error_log("Successfully processed {$spansCount} spans.");
    
} catch (\Exception $e) {
    // This catches protobuf parsing failures
    http_response_code(400);
    error_log("Protobuf Deserialization Error: " . $e->getMessage());
    exit("Failed to parse OTLP Protobuf payload.");
}