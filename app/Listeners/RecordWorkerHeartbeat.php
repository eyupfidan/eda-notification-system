<?php

namespace App\Listeners;

use App\Services\Observability\Heartbeats;
use Illuminate\Queue\Events\Looping;

/**
 * Queue workers fire `Looping` before every poll (about once a second when idle);
 * we turn that into a heartbeat, written at most every 5 seconds per worker process.
 */
class RecordWorkerHeartbeat
{
    private const INTERVAL = 5;

    private static int $lastBeat = 0;

    public function __construct(private readonly Heartbeats $heartbeats) {}

    public function handle(Looping $event): void
    {
        if (time() - self::$lastBeat < self::INTERVAL) {
            return;
        }

        self::$lastBeat = time();
        $this->heartbeats->recordWorker(gethostname().':'.getmypid());
    }
}
