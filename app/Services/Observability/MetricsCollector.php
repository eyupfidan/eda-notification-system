<?php

namespace App\Services\Observability;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Services\Delivery\CircuitBreaker;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Builds the real-time metrics snapshot: queue depth, outcome rates and latency.
 * All time-window figures come from indexed queries on notifications / notification_attempts.
 */
class MetricsCollector
{
    /** Allowed window sizes, in seconds. */
    public const WINDOWS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '24h' => 86400];

    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
        private readonly Heartbeats $heartbeats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(string $window = '5m'): array
    {
        $seconds = self::WINDOWS[$window];
        $since = now()->subSeconds($seconds);

        return [
            'generated_at' => now()->toIso8601String(),
            'window' => $window,
            'queues' => $this->queues(),
            'notifications_by_status' => $this->statusCounts(),
            'delivery' => $this->delivery($since, $seconds),
            'provider_attempts' => $this->attempts($since),
            'circuit_breakers' => $this->circuitBreaker->snapshot(),
            'workers' => ['active' => $this->heartbeats->activeWorkers()],
            'rate_limit_per_second_per_channel' => (int) config('notifications.rate_limit.per_second'),
        ];
    }

    /**
     * Jobs waiting in Redis per priority queue: ready to run, delayed (retry backoff /
     * rate-limit release) and reserved (being processed by a worker right now).
     *
     * @return array<string, array{ready: int, delayed: int, reserved: int, total: int}>
     */
    private function queues(): array
    {
        $queue = Queue::connection();
        $depth = [];

        foreach (Priority::cases() as $priority) {
            $name = $priority->queue();

            if ($queue instanceof RedisQueue) {
                $ready = (int) $queue->pendingSize($name);
                $delayed = (int) $queue->delayedSize($name);
                $reserved = (int) $queue->reservedSize($name);
            } else {
                [$ready, $delayed, $reserved] = [(int) $queue->size($name), 0, 0];
            }

            $depth[$name] = ['ready' => $ready, 'delayed' => $delayed, 'reserved' => $reserved, 'total' => $ready + $delayed + $reserved];
        }

        return $depth;
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $counts = DB::table('notifications')
            ->selectRaw('status, count(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(Status::cases())
            ->mapWithKeys(fn (Status $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])
            ->all();
    }

    /**
     * Final outcomes reached inside the window, and end-to-end latency (accepted → sent).
     *
     * @return array<string, mixed>
     */
    private function delivery(Carbon $since, int $seconds): array
    {
        $sent = $this->rollup(DB::select(
            'SELECT channel,
                    count(*) AS sent,
                    round(avg(extract(epoch FROM sent_at - created_at) * 1000)) AS avg_ms,
                    percentile_cont(0.5)  WITHIN GROUP (ORDER BY extract(epoch FROM sent_at - created_at) * 1000) AS p50_ms,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM sent_at - created_at) * 1000) AS p95_ms,
                    percentile_cont(0.99) WITHIN GROUP (ORDER BY extract(epoch FROM sent_at - created_at) * 1000) AS p99_ms
               FROM notifications
              WHERE sent_at >= ?
           GROUP BY ROLLUP (channel)',
            [$since],
        ));

        $failed = $this->rollup(DB::select(
            'SELECT channel, count(*) AS failed FROM notifications WHERE failed_at >= ? GROUP BY ROLLUP (channel)',
            [$since],
        ));

        $result = [];

        foreach (['all', ...Channel::values()] as $channel) {
            $s = $sent[$channel] ?? null;
            $sentCount = (int) ($s->sent ?? 0);
            $failedCount = (int) ($failed[$channel]->failed ?? 0);
            $finished = $sentCount + $failedCount;

            $result[$channel] = [
                'sent' => $sentCount,
                'failed' => $failedCount,
                'success_rate' => $finished > 0 ? round($sentCount / $finished, 4) : null,
                'failure_rate' => $finished > 0 ? round($failedCount / $finished, 4) : null,
                'throughput_per_second' => round($sentCount / $seconds, 2),
                'latency_ms' => $this->latency($s),
            ];
        }

        return $result;
    }

    /**
     * Every provider call inside the window, including the ones that will be retried.
     *
     * @return array<string, mixed>
     */
    private function attempts(Carbon $since): array
    {
        $rows = $this->rollup(DB::select(
            "SELECT n.channel,
                    count(*) AS total,
                    count(*) FILTER (WHERE a.outcome = 'success') AS success,
                    count(*) FILTER (WHERE a.outcome = 'retryable_failure') AS retryable_failure,
                    count(*) FILTER (WHERE a.outcome = 'permanent_failure') AS permanent_failure,
                    round(avg(a.duration_ms)) AS avg_ms,
                    percentile_cont(0.5)  WITHIN GROUP (ORDER BY a.duration_ms) AS p50_ms,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY a.duration_ms) AS p95_ms,
                    percentile_cont(0.99) WITHIN GROUP (ORDER BY a.duration_ms) AS p99_ms
               FROM notification_attempts a
               JOIN notifications n ON n.id = a.notification_id
              WHERE a.created_at >= ?
           GROUP BY ROLLUP (n.channel)",
            [$since],
        ));

        $result = [];

        foreach (['all', ...Channel::values()] as $channel) {
            $r = $rows[$channel] ?? null;
            $total = (int) ($r->total ?? 0);

            $result[$channel] = [
                'total' => $total,
                'success' => (int) ($r->success ?? 0),
                'retryable_failure' => (int) ($r->retryable_failure ?? 0),
                'permanent_failure' => (int) ($r->permanent_failure ?? 0),
                'success_rate' => $total > 0 ? round((int) $r->success / $total, 4) : null,
                'latency_ms' => $this->latency($r),
            ];
        }

        return $result;
    }

    /**
     * Keys ROLLUP rows by channel; the grand-total row (channel NULL) becomes "all".
     *
     * @param  list<object>  $rows
     * @return array<string, object>
     */
    private function rollup(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row->channel ?? 'all'] = $row;
        }

        return $keyed;
    }

    /**
     * @return array{avg: ?int, p50: ?int, p95: ?int, p99: ?int}
     */
    private function latency(?object $row): array
    {
        $value = fn (string $key) => isset($row->{$key}) ? (int) round((float) $row->{$key}) : null;

        return ['avg' => $value('avg_ms'), 'p50' => $value('p50_ms'), 'p95' => $value('p95_ms'), 'p99' => $value('p99_ms')];
    }
}
