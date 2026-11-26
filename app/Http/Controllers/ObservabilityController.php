<?php

namespace App\Http\Controllers;

use App\Services\Observability\HealthChecker;
use App\Services\Observability\MetricsCollector;
use App\Services\Observability\PrometheusFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ObservabilityController extends Controller
{
    /**
     * Real-time metrics.
     *
     * Queue depth per priority, notifications per status, success/failure rates, throughput
     * and latency (p50/p95/p99) over a time window, circuit breaker state and active workers.
     * `?window=1m|5m|15m|1h|24h` (default 5m). `?format=prometheus` returns the Prometheus
     * text format.
     */
    public function metrics(Request $request, MetricsCollector $collector, PrometheusFormatter $prometheus): JsonResponse|Response
    {
        $validated = $request->validate([
            'window' => ['sometimes', Rule::in(array_keys(MetricsCollector::WINDOWS))],
            'format' => ['sometimes', Rule::in(['json', 'prometheus'])],
        ]);

        $metrics = $collector->collect($validated['window'] ?? '5m');

        if (($validated['format'] ?? 'json') === 'prometheus') {
            return response($prometheus->format($metrics), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
        }

        return response()->json($metrics);
    }

    /**
     * Health check.
     *
     * Checks database, Redis, queue, worker heartbeats and the scheduler. Returns 503 when
     * the service cannot accept notifications (database or Redis down), 200 otherwise;
     * `status` is `ok` or `degraded` (e.g. no worker running).
     */
    public function health(HealthChecker $checker): JsonResponse
    {
        $health = $checker->check();

        return response()->json([
            'status' => $health['status'],
            'timestamp' => now()->toIso8601String(),
            'checks' => $health['checks'],
        ], $health['status'] === 'down' ? 503 : 200);
    }
}
