<?php

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Hands scheduled notifications whose time has come to the queues. Runs every 10 seconds;
 * keeps going in chunks until nothing is due, so a large campaign scheduled for the
 * same minute is released at once (the per-channel rate limit then paces delivery).
 */
#[Signature('notifications:dispatch-scheduled')]
#[Description('Queue scheduled notifications that are due')]
class DispatchScheduledNotifications extends Command
{
    private const MAX_ROUNDS = 100;

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $total = 0;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $queued = $dispatcher->queueDueScheduled(now());
            $total += $queued;

            if ($queued === 0) {
                break;
            }
        }

        if ($total > 0) {
            Log::info('Scheduled notifications queued', ['count' => $total]);
        }

        $this->components->info("Queued {$total} scheduled notification(s).");

        return self::SUCCESS;
    }
}
