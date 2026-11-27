# AGENTS.md

Guide for AI coding agents (and humans) working in this repository. Read this first, then
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the deeper design.

> **Keep this file and `docs/ARCHITECTURE.md` in sync with the code.** When you add, remove or
> rename a component, change a flow, a table, a queue, a Redis key, a config value or a command,
> update both files in the same change. See [Keeping these docs current](#keeping-these-docs-current).

## What this is

Event-driven notification service (SMS, Email, Push) on **Laravel 13 / PHP 8.4**, **PostgreSQL 18**
and **Redis 7**. The API stores notifications and answers `202`; queue workers deliver them to an
external provider (webhook.site or the bundled mock) with priority queues, a per-channel rate limit,
retries, a circuit breaker and real-time WebSocket updates (Laravel Reverb).

## Commands

Everything runs in Docker; nothing but Docker is needed on the host.

| Task | Command |
|---|---|
| Start the stack | `docker compose up -d --build` (or `make up`) |
| Start with the local mock provider | `make up-mock` |
| Production deploy (Dokploy, "Docker Compose" service) | `docker-compose.dokploy.yml`; see README → Deploying with Dokploy |
| Rebuild app, workers, scheduler after code changes | `make restart` (the image has no bind mount) |
| Run all tests | `make test` |
| Coverage summary | `make coverage` |
| Lint / format | `vendor/bin/pint` (CI runs `vendor/bin/pint --test`) |
| Regenerate the OpenAPI spec | `make docs` → `docs/openapi.json` |
| Shell in the app container | `make shell` |

Tests run against the `notifications_test` database and Redis DB `15` only; `tests/TestCase.php`
refuses anything else and flushes Redis DB 15 before every test.

## Code map

| Path | Responsibility |
|---|---|
| `app/Http/Controllers/Api/V1/` | `NotificationController`, `BatchController`, `TemplateController` (thin; delegate to services) |
| `app/Http/Controllers/ObservabilityController.php` | `/metrics`, `/health` |
| `app/Http/Requests/` | Validation. `Concerns/NotificationRules` holds the shared rules and the second pass (templates, email subject, length) |
| `app/Http/Middleware/` | `AssignCorrelationId`, `TraceRequests` (both global), `ValidateIdempotencyKey` |
| `app/Services/NotificationService.php` | Create, batch, idempotency replay, cancel, retry, list, batch summary |
| `app/Services/NotificationDispatcher.php` | **Every** transition into `queued` + job dispatch (`UPDATE … RETURNING`) |
| `app/Services/Delivery/` | `RetryPolicy`, `CircuitBreaker`, `SlidingWindowRateLimiter` |
| `app/Services/Templates/` | `TemplateRenderer`, `NotificationPayloadResolver` |
| `app/Services/Realtime/StatusBroadcaster.php` | Best-effort WebSocket broadcasts |
| `app/Services/Observability/` | `MetricsCollector`, `PrometheusFormatter`, `HealthChecker`, `Heartbeats` |
| `app/Services/Tracing/` | `Tracing` (span helpers, W3C propagation), `TracerProviderFactory` (OTLP → Jaeger or no-op) |
| `app/Jobs/SendNotificationJob.php` | Claim → send → sent / retry / failed |
| `app/Jobs/Middleware/` | `TraceJob`, `SkipWhenCircuitOpen`, `RateLimitPerChannel` |
| `app/Listeners/` | `QueueAcceptedNotifications`, `RecordWorkerHeartbeat` |
| `app/Events/` | `NotificationsAccepted` (after commit), `NotificationStatusChanged`, `BatchUpdated` (broadcast) |
| `app/Models/` | `Notification` (state transitions), `NotificationAttempt`, `Template` |
| `app/Enums/` | `Channel`, `Priority`, `Status` (state machine), `AttemptOutcome` |
| `app/Providers/Notifications/` | `NotificationProvider` contract, `WebhookSiteProvider` |
| `app/Console/Commands/` | `notifications:dispatch-scheduled` (10 s), `notifications:recover` (1 min) |
| `config/notifications.php` | All service tuning (limits, retry, circuit breaker, recovery, provider) |
| `config/tracing.php` | OpenTelemetry on/off, OTLP endpoint, DB spans |
| `docker/` | PHP-FPM image, nginx, Postgres init (test DB), mock provider |
| `tests/` | PHPUnit feature + unit tests (one file per area) |

## Rules that must not be broken

1. **Status changes go through the model or the dispatcher.** Use `Notification::transitionIfStatus()`
   (atomic compare-and-set) or `transitionTo()`; for transitions into `queued` use
   `NotificationDispatcher`. Never `update(['status' => …])` directly. Allowed transitions live in
   `App\Enums\Status::allowedTransitions()`; update its tests when you change them.
2. **Never call the provider without claiming first.** `SendNotificationJob` must win
   `queued → processing` before sending; this is what makes duplicate jobs harmless.
3. **Provider errors must be classified:** throw `RetryableProviderException` (timeouts, 5xx, 429) or
   `PermanentProviderException` (other 4xx, malformed success). The retry policy depends on it.
4. **Broadcasting and tracing are best effort.** Go through `StatusBroadcaster` / `Tracing`; never let
   a WebSocket or tracing failure break delivery. New outgoing HTTP calls should run in a CLIENT span
   (`Tracing::inSpan`) and forward `Tracing::propagationHeaders()`.
5. **Idempotency keys stay in the unique column.** Batch items use `{key}#{index}`.
6. **Dates are stored in UTC.** Normalise user-supplied times (with offsets) before saving.
7. **Migrations are append-only.** Never edit a migration that has been committed; add a new one.
8. **API errors use the envelope** `{"error": {"code", "message", "details"}}` (see `bootstrap/app.php`).
9. **Tests accompany behaviour changes.** Run `make test` and `vendor/bin/pint` before committing.
10. **No AI attribution in commits or PRs** (no `Co-Authored-By` trailers or "generated with" lines).

## Conventions

- PHP 8.4, `declare`-free Laravel style, Pint defaults. Constructor property promotion, `readonly`
  value objects, enums instead of string constants.
- Controllers stay thin; business logic lives in `app/Services`.
- Commit messages: conventional prefix (`feat:`, `fix:`, `test:`, `docs:`, `ci:`, `chore:`), short
  imperative subject, bullet body explaining *why*.
- After API changes run `make docs` and commit `docs/openapi.json`. Scramble reads PHPDoc and
  `#[Response]`, `#[HeaderParameter]`, `#[Group]` attributes on controllers.
- User-facing docs: `README.md` (short). Design docs: `docs/ARCHITECTURE.md`.

## Keeping these docs current

Update the docs **in the same commit** as the code change:

| You changed… | Update |
|---|---|
| Added / removed / renamed a class, service, job, command, event or listener | `AGENTS.md` code map, `docs/ARCHITECTURE.md` components |
| A migration (table, column, index) | `docs/ARCHITECTURE.md` → Data model |
| Status transitions, retry, rate limit, circuit breaker, recovery, scheduling | `docs/ARCHITECTURE.md` → relevant flow; `AGENTS.md` rules if an invariant changed |
| Routes / endpoints | `README.md` API table, `make docs`, `docs/ARCHITECTURE.md` → API surface |
| Redis keys, queues, config values, env vars | `docs/ARCHITECTURE.md` → Redis / Configuration; `.env.example` |
| Docker services or ports | `AGENTS.md` commands if affected, `docs/ARCHITECTURE.md` → Runtime |

Remove entries for things that no longer exist. Enforcement:

- **Claude Code:** a Stop hook (`.claude/settings.json` → `scripts/check-agent-docs.sh`) blocks
  finishing a turn when structural files changed but neither `AGENTS.md` nor `docs/ARCHITECTURE.md` did.
- **CI:** the `Agent docs in sync` job runs the same script on every push and pull request.
- If a structural change truly needs no doc update, add `[skip-docs]` to the commit message.
