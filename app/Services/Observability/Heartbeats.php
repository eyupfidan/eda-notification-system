<?php

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Liveness signals of the background processes, kept in Redis so the API can report them.
 * Workers beat on every loop iteration (throttled); the scheduler beats on each recovery sweep.
 */
class Heartbeats
{
    private const WORKERS_KEY = 'notifications:heartbeat:workers';

    private const SCHEDULER_KEY = 'notifications:heartbeat:scheduler';

    /** A worker that has not beaten for this long is considered gone. */
    public const WORKER_TTL = 30;

    public function recordWorker(string $workerId): void
    {
        Redis::zadd(self::WORKERS_KEY, now()->timestamp, $workerId);
    }

    public function activeWorkers(): int
    {
        $cutoff = now()->timestamp - self::WORKER_TTL;

        Redis::zremrangebyscore(self::WORKERS_KEY, '-inf', (string) ($cutoff - 1));

        return (int) Redis::zcount(self::WORKERS_KEY, (string) $cutoff, '+inf');
    }

    public function recordScheduler(): void
    {
        Redis::set(self::SCHEDULER_KEY, (string) now()->timestamp);
    }

    public function lastSchedulerRun(): ?Carbon
    {
        $timestamp = Redis::get(self::SCHEDULER_KEY);

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public function forget(): void
    {
        Redis::del(self::WORKERS_KEY, self::SCHEDULER_KEY);
    }
}
