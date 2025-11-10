````markdown
# Telescope OTEL (highly alpha version)

**Telescope OTEL** is a lightweight OpenTelemetry receiver and viewer inspired by Laravel Telescope.  
It collects incoming OTLP traces and logs via HTTP (`x-protobuf`), stores them in a simple SQLite database, and provides a clean web UI for browsing entries grouped by **Trace ID**.

---

## ✨ Features

- **OpenTelemetry-compatible endpoints**
  - `POST /v1/traces` — accepts OTLP trace export requests (`application/x-protobuf`)
  - `POST /v1/logs` — accepts OTLP log export requests (`application/x-protobuf`)
- **Simple database**
  - Uses SQLite for storage (`/app/databases/telescope.sqlite`)
  - Stores decoded spans and log records in JSON format
- **Minimal web UI**
  - View recent entries grouped by Trace ID
  - Telescope-like list/detail interface
  - Basic authentication for dashboard access
- **Container-ready**
  - Official image: [`jimanx2/telescope-otel`](https://hub.docker.com/r/jimanx2/telescope-otel)
  - Tiny PHP 8.3 + Alpine base image, no external dependencies

---

## 🚀 Quick Start

```bash
docker run -d \
  -p 8215:1215 \
  -e DASHBOARD_USER=admin \
  -e DASHBOARD_PASS=changeme \
  --name telescope-otel \
  jimanx2/telescope-otel
````

Open **[http://localhost:8215](http://localhost:8215)** and log in with the credentials above.
Entries will appear as your OTLP exporter starts sending data.

---

## ⚙️ Example Exporter Config (OTLP/HTTP)

### Using OpenTelemetry Collector

```yaml
exporters:
  otlphttp/telescope:
    endpoint: http://telescope-otel:1215
    tls:
      insecure: true
    traces_endpoint: http://telescope-otel:1215/v1/traces
    logs_endpoint:   http://telescope-otel:1215/v1/logs
    compression: none  # must be disabled
```

---

## 🧩 Database Schema

```sql
CREATE TABLE IF NOT EXISTS debug_entries (
  uuid        TEXT PRIMARY KEY,
  type        TEXT NOT NULL,
  content     TEXT NOT NULL,
  created_at  DATETIME DEFAULT (datetime('now'))
);
```

Entries are categorized as:

* `request`
* `client-request`
* `query`
* `exception`
* `log`
* `unknown`

---

## 🔐 Authentication

Default credentials (can be overridden via environment variables):

```bash
DASHBOARD_USER=admin
DASHBOARD_PASS=securepassword123
```

---

## 🧱 Development

```bash
docker compose up --build
# or manually:
php -S 0.0.0.0:1215 -t public
```

UI files are under `public/`, and handlers for OTLP ingestion are under `public/v1/`.

---

## 🛠 Roadmap

* [ ] Add gzip support for OTLP HTTP
* [ ] Expose JSON API endpoints (`/api/entries`)
* [ ] Add pagination and filters in UI
* [ ] Optional retention policy for old entries
* [ ] Grafana Tempo compatibility bridge

---

## 📜 License

MIT License © 2025 JimanX2

```

---

Would you like me to make this a bit more *marketing-style* (like a Docker Hub landing page) — with badges, image size info, and a “Why use this?” section? It’d help for public publishing.
```
