<?php

namespace App\Http\Controllers;

use App\Services\Observability\HealthChecker;
use App\Services\Observability\MetricsCollector;
use App\Services\Observability\PrometheusFormatter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as DocResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * `/health` and `/metrics` serve two audiences from the same data:
 * browsers get an HTML dashboard, machines get JSON (`?type=json` or `Accept: application/json`).
 */
#[Group('Observability', weight: 3)]
class ObservabilityController extends Controller
{
    /**
     * Real-time metrics.
     *
     * Queue depth per priority, notifications per status, success/failure rates, throughput
     * and latency (p50/p95/p99) over a time window, circuit breaker state and active workers.
     * `?window=1m|5m|15m|1h|24h` (default 5m). Returns JSON with `?type=json` or an
     * `Accept: application/json` header, otherwise an HTML dashboard. `?format=prometheus`
     * returns the Prometheus text format.
     */
    public function metrics(Request $request, MetricsCollector $collector, PrometheusFormatter $prometheus): JsonResponse|Response
    {
        $validated = $request->validate([
            'window' => ['sometimes', Rule::in(array_keys(MetricsCollector::WINDOWS))],
            'format' => ['sometimes', Rule::in(['json', 'prometheus'])],
            'type' => ['sometimes', Rule::in(['json', 'html'])],
        ]);

        $metrics = $collector->collect($validated['window'] ?? '5m');
        $format = $validated['format'] ?? null;

        if ($format === 'prometheus') {
            return response($prometheus->format($metrics), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
        }

        if ($format === 'json' || $this->wantsJson($request, $validated)) {
            return response()->json($metrics);
        }

        return response()->view('observability.metrics', [
            'metrics' => $metrics,
            'windows' => array_keys(MetricsCollector::WINDOWS),
        ]);
    }

    /**
     * Health check.
     *
     * Checks database, Redis, queue, worker heartbeats and the scheduler. Returns 503 when
     * the service cannot accept notifications (database or Redis down), 200 otherwise;
     * `status` is `ok` or `degraded` (e.g. no worker running). Returns JSON with `?type=json`
     * or an `Accept: application/json` header, otherwise an HTML dashboard (same status code).
     */
    #[DocResponse(503, 'Database or Redis unreachable: the service cannot accept notifications (`status: down`).')]
    public function health(Request $request, HealthChecker $checker): JsonResponse|Response
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['json', 'html'])],
        ]);

        $health = $checker->check();
        $body = [
            'status' => $health['status'],
            'timestamp' => now()->toIso8601String(),
            'checks' => $health['checks'],
        ];
        $status = $health['status'] === 'down' ? 503 : 200;

        if ($this->wantsJson($request, $validated)) {
            return response()->json($body, $status);
        }

        return response()->view('observability.health', ['health' => $body], $status);
    }

    /**
     * An explicit `?type=` wins; without it, API clients that ask for JSON get JSON.
     *
     * @param  array<string, mixed>  $validated
     */
    private function wantsJson(Request $request, array $validated): bool
    {
        return match ($validated['type'] ?? null) {
            'json' => true,
            'html' => false,
            default => $request->expectsJson(),
        };
    }
}
