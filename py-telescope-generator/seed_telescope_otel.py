#!/usr/bin/env python3
import argparse, os, time, json, requests
from typing import List

# OTLP protobufs
from opentelemetry.proto.collector.trace.v1 import trace_service_pb2 as TraceService
from opentelemetry.proto.collector.logs.v1 import logs_service_pb2 as LogsService
from opentelemetry.proto.trace.v1 import trace_pb2 as TracePB
from opentelemetry.proto.logs.v1 import logs_pb2 as LogsPB
from opentelemetry.proto.common.v1 import common_pb2 as CommonPB
from opentelemetry.proto.resource.v1 import resource_pb2 as ResourcePB

NS = 1_000_000_000

def now_ns() -> int:
    return int(time.time() * NS)

def kv(key: str, value) -> CommonPB.KeyValue:
    anyv = CommonPB.AnyValue()
    if isinstance(value, bool):
        anyv.bool_value = value
    elif isinstance(value, int):
        anyv.int_value = value
    elif isinstance(value, float):
        anyv.double_value = value
    else:
        anyv.string_value = str(value)
    return CommonPB.KeyValue(key=key, value=anyv)

def mk_span(
    name: str,
    trace_id: bytes,
    span_id: bytes,
    kind: int = TracePB.Span.SPAN_KIND_INTERNAL,
    attrs: dict | None = None,
    status_code: int = TracePB.Status.StatusCode.STATUS_CODE_UNSET,
    events: List[TracePB.Span.Event] | None = None,
    duration_ms: int = 50,
) -> TracePB.Span:
    start = now_ns()
    end = start + duration_ms * 1_000_000
    span = TracePB.Span(
        trace_id=trace_id,
        span_id=span_id,
        name=name,
        kind=kind,
        start_time_unix_nano=start,
        end_time_unix_nano=end,
        status=TracePB.Status(code=status_code),
    )
    if attrs:
        span.attributes.extend([kv(k, v) for k, v in attrs.items()])
    if events:
        span.events.extend(events)
    return span

def exception_event(exc_type: str, message: str, stack: str) -> TracePB.Span.Event:
    return TracePB.Span.Event(
        time_unix_nano=now_ns(),
        name="exception",
        attributes=[
            kv("exception.type", exc_type),
            kv("exception.message", message),
            kv("exception.stacktrace", stack),
        ],
    )

def make_resource(service_name: str, extra: dict | None = None) -> ResourcePB.Resource:
    res = ResourcePB.Resource()
    attrs = {"service.name": service_name}
    if extra:
        attrs.update(extra)
    res.attributes.extend([kv(k, v) for k, v in attrs.items()])
    return res

def build_traces_request() -> TraceService.ExportTraceServiceRequest:
    trace_id = os.urandom(16)

    spans = [
        # request (SERVER)
        mk_span(
            name="HTTP GET /orders",
            trace_id=trace_id,
            span_id=os.urandom(8),
            kind=TracePB.Span.SPAN_KIND_SERVER,
            attrs={"http.method": "GET", "http.target": "/orders", "http.status_code": 200},
        ),
        # client-request (CLIENT)
        mk_span(
            name="GET api.backend.example",
            trace_id=trace_id,
            span_id=os.urandom(8),
            kind=TracePB.Span.SPAN_KIND_CLIENT,
            attrs={
                "net.peer.name": "api.backend.example",
                "http.method": "GET",
                "http.url": "https://api.backend.example/users/42",
                "http.status_code": 200,
            },
        ),
        # query (SQL-ish)
        mk_span(
            name="SELECT users",
            trace_id=trace_id,
            span_id=os.urandom(8),
            kind=TracePB.Span.SPAN_KIND_INTERNAL,
            attrs={
                "db.system": "mysql",
                "db.statement": "SELECT * FROM users WHERE id = 42",
                "db.user": "app",
                "net.peer.name": "mysql-primary",
            },
        ),
        # exception (SERVER + error + event)
        mk_span(
            name="processPayment",
            trace_id=trace_id,
            span_id=os.urandom(8),
            kind=TracePB.Span.SPAN_KIND_SERVER,
            attrs={"http.method": "POST", "http.target": "/pay", "http.status_code": 500},
            status_code=TracePB.Status.StatusCode.STATUS_CODE_ERROR,
            events=[exception_event("RuntimeError", "Payment gateway timeout", "Traceback...")],
        ),
        # unknown/minimal
        mk_span(name="heartbeat", trace_id=trace_id, span_id=os.urandom(8)),
    ]

    scope_spans = TracePB.ScopeSpans(
        scope=CommonPB.InstrumentationScope(name="manual-seeder"),
        spans=spans,
    )

    rs = TracePB.ResourceSpans(
        resource=make_resource("telescope-otel-seeder", {"deployment.environment": "local"}),
        scope_spans=[scope_spans],
    )

    return TraceService.ExportTraceServiceRequest(resource_spans=[rs])

def build_logs_request(trace_id: bytes, span_id: bytes) -> LogsService.ExportLogsServiceRequest:
    lr_info = LogsPB.LogRecord(
        time_unix_nano=now_ns(),
        severity_text="INFO",
        body=CommonPB.AnyValue(string_value="User viewed /orders"),
        trace_id=trace_id,
        span_id=span_id,
    )
    lr_info.attributes.extend([kv("http.target", "/orders"), kv("user.id", 42)])

    lr_error = LogsPB.LogRecord(
        time_unix_nano=now_ns(),
        severity_text="ERROR",
        body=CommonPB.AnyValue(string_value="Payment service timeout"),
        trace_id=trace_id,
        span_id=span_id,
    )
    lr_error.attributes.extend([kv("component", "payment"), kv("retry", False)])

    scope_logs = LogsPB.ScopeLogs(
        scope=CommonPB.InstrumentationScope(name="manual-seeder"),
        log_records=[lr_info, lr_error],
    )

    rl = LogsPB.ResourceLogs(resource=make_resource("telescope-otel-seeder"), scope_logs=[scope_logs])
    return LogsService.ExportLogsServiceRequest(resource_logs=[rl])

def post_protobuf(url: str, message) -> requests.Response:
    data = message.SerializeToString()
    headers = {"Content-Type": "application/x-protobuf"}
    return requests.post(url, data=data, headers=headers, timeout=10)

def main():
    ap = argparse.ArgumentParser(description="Seed telescope-otel with sample OTLP traces and logs.")
    ap.add_argument("--endpoint", default="http://localhost:8215", help="Base URL (no trailing slash).")
    args = ap.parse_args()

    traces_req = build_traces_request()
    first_span = traces_req.resource_spans[0].scope_spans[0].spans[0]
    logs_req = build_logs_request(first_span.trace_id, first_span.span_id)

    tr = post_protobuf(f"{args.endpoint}/v1/traces", traces_req)
    lg = post_protobuf(f"{args.endpoint}/v1/logs",   logs_req)

    print(json.dumps({
        "traces": {"status_code": tr.status_code, "text": tr.text[:200]},
        "logs":   {"status_code": lg.status_code, "text": lg.text[:200]},
    }, indent=2))

if __name__ == "__main__":
    main()
