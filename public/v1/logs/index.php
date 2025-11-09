<?php
require '/app/vendor/autoload.php';

define('DB_DATABASE', '/app/databases/telescope.sqlite');

// Database Configuration
$db = DB::instance();

use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;

// --- Step 1. Validate Endpoint ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !str_ends_with($_SERVER['REQUEST_URI'], '/v1/logs')) {
    http_response_code(404);
    echo "Not Found";
    exit;
}

// --- Step 2. Content-Type Validation ---
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!str_contains($contentType, 'application/x-protobuf')) {
    http_response_code(415);
    echo "Expected Content-Type: application/x-protobuf";
    exit;
}

// --- Step 3. Read the Raw Body ---
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    exit("Bad Request: Empty body");
}

// --- Step 4. Parse OTLP Log Protobuf ---
try {
    $request = new ExportLogsServiceRequest();
    $request->mergeFromString($rawBody);

    $logCount = 0;

    foreach ($request->getResourceLogs() as $resourceLogs) {
        $serviceName = 'unknown';
        $attributes = $resourceLogs->getResource()->getAttributes();
        foreach ($attributes as $attr) {
            if ($attr->getKey() === 'service.name') {
                $serviceName = $attr->getValue()->getStringValue();
            }
        }

        foreach ($resourceLogs->getScopeLogs() as $scopeLogs) {
            foreach ($scopeLogs->getLogRecords() as $log) {
                $logCount++;

                $traceId = null;
                $spanId  = null;

                $spanAttrs = [];
                foreach ($log->getAttributes() as $attr) {
                    $key = $attr->getKey();
                    $val = $attr->getValue()->getStringValue(); // assuming string

                    if ($key === 'trace_id') {
                        $traceId = $val;
                        continue;
                    }
                    if ($key === 'span_id') {
                        $spanId = $val;
                        continue;
                    }

                    $spanAttrs[$key] = $val;
                }

                if (! $traceId || ! $spanId) continue;

                $uuid = sprintf("%s-%s-%d", $traceId, $spanId, $logCount - 2);
                $content = [
                    "message" => $body = $log->getBody()->getStringValue(),
                    "extra" => $spanAttrs
                ];

                $db->query(
                    'INSERT INTO debug_entries (uuid, type, content) VALUES (?, ?, ?)', 
                    $uuid, // uuid
                    'log', // type
                    json_encode($content), // content
                );

                error_log(sprintf(
                    "🪵 Log received: service=%s, TraceID=%s, SpanID=%s, body=%s",
                    $serviceName,
                    $traceId ?? 'unknown',
                    $spanId  ?? 'unknown',
                    $body
                ));
            }
        }
    }

    http_response_code(200);
    header('Content-Type: application/x-protobuf');
    echo ""; // Empty successful OTLP response
    error_log("✅ Successfully processed {$logCount} logs from service '{$serviceName}'.");

} catch (\Exception $e) {
    http_response_code(400);
    error_log("❌ Protobuf Deserialization Error: " . $e->getMessage());
    exit("Failed to parse OTLP log payload.");
}
