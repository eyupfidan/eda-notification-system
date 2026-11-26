<?php

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use App\Services\Observability\Heartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net run every minute by the scheduler. Finds notifications stuck in a
 * non-final state because something outside the happy path broke, and re-queues them.
 */
#[Signature('notifications:recover')]
#[Description('Re-queue notifications stuck in pending, processing or queued state')]
class RecoverStuckNotifications extends Command
{
    public function handle(NotificationDispatcher $dispatcher, Heartbeats $heartbeats): int
    {
        $heartbeats->recordScheduler();

        $config = config('notifications.recovery');

        $recovered = [
            'pending' => $dispatcher->requeueStalePending(now()->subSeconds($config['pending_after'])),
            'processing' => $dispatcher->requeueStaleProcessing(now()->subSeconds($config['processing_after'])),
            'queued' => $dispatcher->redispatchStaleQueued(now()->subSeconds($config['queued_after'])),
        ];

        if (array_sum($recovered) > 0) {
            Log::warning('Recovered stuck notifications', $recovered);
        }

        $this->components->info(sprintf(
            'Recovered: %d pending, %d processing, %d queued.',
            $recovered['pending'], $recovered['processing'], $recovered['queued'],
        ));

        return self::SUCCESS;
    }
}
