<div align="center">

# EDA Notification System

**Event-driven notification service for SMS, Email and Push, built with Laravel.**

[![CI](https://github.com/eyupfidan/eda-notification-system/actions/workflows/ci.yml/badge.svg)](https://github.com/eyupfidan/eda-notification-system/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![Redis](https://img.shields.io/badge/Redis-7-DC382D?logo=redis&logoColor=white)
![Tests](https://img.shields.io/badge/tests-175%20passing-2ea44f)
![Coverage](https://img.shields.io/badge/coverage-99.5%25-2ea44f)

<img src="docs/architecture.svg" alt="Architecture" width="900">

</div>

## Features

- **Async by design:** the API stores notifications and answers `202`. Queue workers deliver them.
- **Batches** of up to 1000 notifications, stored atomically (all or nothing).
- **Templates** with `{{ variables }}`: send `template_id` + `variables` instead of `content`.
- **Scheduled delivery:** set `scheduled_at` (ISO 8601, up to 30 days ahead) and the notification is sent at that time, give or take 10 s.
- **Priority queues** (`high > normal > low`) and a strict **100 msg/s per channel** limit shared by all workers.
- **No duplicates:** `Idempotency-Key` support, atomic claiming of each delivery, idempotency key forwarded to the provider.
- **Smart retries:** exponential backoff with jitter, `Retry-After` support, a circuit breaker per channel,
  manual and batch retry, and a recovery sweep for anything stuck.
- **Real-time updates over WebSocket** (Laravel Reverb): subscribe to `notifications.{id}` or `batches.{id}`.
- **Observable:** correlation ids, JSON logs, `/metrics` and `/health` (HTML dashboards, JSON, Prometheus), and
  **distributed tracing** (OpenTelemetry → Jaeger) from the API request through the queue to the provider.

## Quick start

```bash
git clone https://github.com/eyupfidan/eda-notification-system.git
cd eda-notification-system
docker compose up -d --build
```

| | |
|---|---|
| API | http://localhost:8080/api/v1 |
| API docs (Swagger) | http://localhost:8080/docs/api · [`docs/openapi.json`](docs/openapi.json) |
| Health / Metrics dashboards | http://localhost:8080/health · http://localhost:8080/metrics (JSON: `?type=json`) |
| Live status demo (WebSocket) | http://localhost:8080/realtime |
| Traces (Jaeger) | http://localhost:16686 |

**Provider.** Create a URL on [webhook.site](https://webhook.site) that answers `202` with
`{"messageId": "<uuid>", "status": "accepted", "timestamp": "<ISO8601>"}`, then run:

```bash
echo "NOTIFICATION_PROVIDER_URL=https://webhook.site/<your-uuid>" > .env && docker compose up -d
```

> [!NOTE]
> webhook.site's free plan accepts only ~50 requests per URL. For batches and load tests, run `make up-mock`
> to use the bundled mock provider (same contract, no quota, optional failure injection such as
> `MOCK_FAILURE_RATE=0.3`).

## API

| Method | Endpoint | |
|---|---|---|
| `POST` | `/api/v1/notifications` | Create one notification |
| `POST` | `/api/v1/notifications/batch` | Create up to 1000 notifications |
| `GET` | `/api/v1/notifications/{id}` | Status and delivery attempts |
| `GET` | `/api/v1/notifications` | Filter by `status`, `channel`, `priority`, `batch_id`, `from`, `to` (cursor pagination) |
| `PATCH` | `/api/v1/notifications/{id}/cancel` | Cancel before delivery starts |
| `POST` | `/api/v1/notifications/{id}/retry` | Retry a failed notification |
| `GET` · `PATCH` · `POST` | `/api/v1/batches/{id}` · `/cancel` · `/retry` | Batch status, cancel, retry |
| `CRUD` · `POST` | `/api/v1/templates` · `/{id}/preview` | Message templates and preview |

```bash
curl -X POST http://localhost:8080/api/v1/notifications \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: order-1042' \
  -d '{"channel":"sms","recipient":"+905551234567","content":"Your order has shipped","priority":"high"}'
```

Templates: create one with `{"name":"order-shipped","channel":"sms","content":"Hi {{ name }}, order {{ order.id }} has shipped"}`,
then send `{"channel":"sms","recipient":"+905551234567","template_id":"…","variables":{"name":"Jane","order":{"id":"A-1042"}}}`.
The text is rendered when the notification is created, so later template edits never change messages already accepted.

Validation: SMS uses E.164 numbers and up to 1600 characters, Email needs an address and a `subject`, Push needs a
device token and up to 1000 characters. Errors share one format: `{"error": {"code", "message", "details"}}`.

## How it works

**Lifecycle:** `(scheduled →) pending → queued → processing → sent`. A temporary failure goes back to `queued` with a retry time.
A permanent failure, or running out of attempts, ends in `failed`. `scheduled`, `pending` and `queued` notifications can be cancelled.

| Provider response | Result |
|---|---|
| `2xx` + `messageId` | `sent` |
| timeout, `5xx`, `429` | retry after 10s → 30s → 90s → 270s (±20% jitter, max 5 attempts) |
| other `4xx` | `failed` right away, since retrying cannot help |

<details>
<summary><b>Key design decisions</b></summary>

- **Sliding-window rate limiter** (Redis sorted set + Lua): never more than 100 sends in *any* one-second span.
  A fixed window allowed 108 under load.
- **Atomic claim:** `UPDATE … WHERE status = 'queued'` before every send. Duplicate jobs, or jobs for notifications
  cancelled a moment earlier, never reach the provider.
- **Idempotency key in a unique column:** no TTL, and safe under races. 10 concurrent requests with the same key create 1 row.
- **Event after commit:** jobs are queued only for rows that are really saved.
  Bursts become queue depth, not API latency.
- **Circuit breaker:** 10 temporary failures in a row pause a channel for 30s without spending attempts.
- **Recovery sweep** (every minute, `FOR UPDATE SKIP LOCKED`): re-queues notifications stuck after crashes or Redis loss.
- **Scheduling in the database, not in Redis delays:** scheduled notifications stay cancellable, survive Redis loss and
  use no queue memory while waiting. A command releases due ones every 10 s with `FOR UPDATE SKIP LOCKED`.
- **Cursor pagination:** costs the same on page 1 and page 10 000 of a table with millions of rows.

</details>

**Measured:** 1000 SMS with 8 workers in 19s, at most 100/s. With the mock failing 30% of calls, 300 notifications
were all delivered through retries.

## Real-time updates

Every status change is pushed over WebSocket (Pusher protocol, so any Pusher client works) on
`notifications.{id}` and `batches.{batchId}` as the `notification.status` event:
`{"id", "batch_id", "channel", "status", "attempts", "next_retry_at", "last_error", "updated_at"}`.
Batch cancel and retry also send `batch.updated` with the new counts. Broadcasting is best effort:
if the WebSocket server is down, delivery goes on. Open http://localhost:8080/realtime and click
**Send demo batch** to watch it live (the page has an English / Turkish switch).

```js
const pusher = new Pusher('eda-local-key', { wsHost: 'localhost', wsPort: 8085, forceTLS: false, enabledTransports: ['ws'], cluster: 'local' });
pusher.subscribe('batches.<batch-id>').bind('notification.status', e => console.log(e.id, e.status));
```

## Observability

- `X-Correlation-ID` is accepted or generated, returned, stored on the notification and present in every log line and provider call.
- `GET /metrics?window=5m`: queue depth, status counts, success/failure rates, throughput, p50/p95/p99 latency, circuit state.
  Add `&format=prometheus` for Prometheus.
- `GET /health`: database, Redis, queue, workers and scheduler. Returns `503` if DB or Redis is down.
- Both open as auto-refreshing dashboards in a browser (English / Turkish). Add `?type=json` (or send
  `Accept: application/json`) to get the JSON; `?type=html` forces the dashboard.
- **Tracing:** every response has `X-Trace-Id`; open it in Jaeger (http://localhost:16686) to see
  `POST /api/v1/notifications → notification.create → deliver sms → POST provider` plus DB queries in one trace.
  An incoming W3C `traceparent` is continued and forwarded to the provider.

## Deploying with Dokploy

Create a **Docker Compose** service (not an Application: the stack has nine containers) from this
repository with Compose Path `./docker-compose.dokploy.yml`.

1. **Environment:** set `APP_KEY` (`echo "base64:$(openssl rand -base64 32)"`), `APP_URL`
   (`https://your-domain`), `DB_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` and
   `NOTIFICATION_PROVIDER_URL`. The deploy stops with a clear message if a required one is missing.
2. **Domains:** add your domain to the `nginx` service, container port `80`, with HTTPS. WebSocket
   traffic goes through the same domain (nginx proxies `/app` and `/apps` to Reverb).
   Optionally add a domain to `jaeger` (port `16686`) for the trace UI; it has no authentication.
3. Deploy. Migrations run when `app` starts; check `https://your-domain/health`.

No host ports are published. Postgres and Redis keep their data in named volumes.

## Development

```bash
make test        # 175 tests (PostgreSQL + Redis, isolated test databases)
make coverage    # coverage summary
make up-mock     # run with the mock provider
make docs        # regenerate docs/openapi.json
make help        # all commands
```

Working with an AI agent? [`AGENTS.md`](AGENTS.md) (also loaded via [`CLAUDE.md`](CLAUDE.md)) and
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) describe the code map, rules and flows; a Claude Code hook and a CI job
make sure they are updated together with structural changes.

CI runs lint, tests, an agent-docs check and a Docker Compose smoke test on every push. Settings such as
`NOTIFICATION_RATE_LIMIT_PER_SECOND`, `NOTIFICATION_MAX_ATTEMPTS` and `WORKER_REPLICAS` are listed in [`.env.example`](.env.example).
