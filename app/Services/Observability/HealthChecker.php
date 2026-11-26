<?php

namespace App\Services\Observability;

use App\Enums\Priority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness check of every dependency the service needs.
 *
 * - database / redis down     → "down"      (HTTP 503: cannot accept notifications)
 * - no worker / stale scheduler → "degraded" (HTTP 200: API works, delivery is delayed)
 */
class HealthChecker
{
    /** The recovery sweep runs every minute; older than this means the scheduler is not running. */
    private const SCHEDULER_STALE_AFTER = 180;

    public function __construct(private readonly Heartbeats $heartbeats) {}

    /**
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    public function check(): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('SELECT 1')),
            'redis' => $this->probe(fn () => Redis::ping()),
        ];

        $checks['queue'] = $checks['redis']['status'] === 'ok' ? $this->queue() : ['status' => 'down', 'error' => 'redis unavailable'];
        $checks['workers'] = $checks['redis']['status'] === 'ok' ? $this->workers() : ['status' => 'down', 'error' => 'redis unavailable'];
        $checks['scheduler'] = $checks['redis']['status'] === 'ok' ? $this->scheduler() : ['status' => 'down', 'error' => 'redis unavailable'];

        $status = match (true) {
            $checks['database']['status'] !== 'ok', $checks['redis']['status'] !== 'ok' => 'down',
            collect($checks)->contains(fn (array $c) => $c['status'] !== 'ok') => 'degraded',
            default => 'ok',
        };

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(callable $callback): array
    {
        $start = hrtime(true);

        try {
            $callback();

            return ['status' => 'ok', 'latency_ms' => round((hrtime(true) - $start) / 1_000_000, 2)];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => class_basename($e).': '.str($e->getMessage())->limit(200)];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        try {
            $depth = collect(Priority::cases())->sum(fn (Priority $p) => Queue::size($p->queue()));

            return ['status' => 'ok', 'depth' => (int) $depth];
        } catch (Throwable $e) {
            return ['status' => 'down', 'error' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workers(): array
    {
        $active = $this->heartbeats->activeWorkers();

        return ['status' => $active > 0 ? 'ok' : 'degraded', 'active' => $active];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $lastRun = $this->heartbeats->lastSchedulerRun();
        $fresh = $lastRun !== null && $lastRun->diffInSeconds(now()) <= self::SCHEDULER_STALE_AFTER;

        return ['status' => $fresh ? 'ok' : 'degraded', 'last_run' => $lastRun?->toIso8601String()];
    }
}
