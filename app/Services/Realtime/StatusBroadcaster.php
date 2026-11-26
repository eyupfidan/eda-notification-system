<?php

namespace App\Services\Realtime;

use App\Events\BatchUpdated;
use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real-time updates are best effort: if the WebSocket server is down, delivery must go on.
 * Any broadcasting error is logged and swallowed here.
 */
class StatusBroadcaster
{
    public function statusChanged(Notification $notification): void
    {
        $this->broadcast(new NotificationStatusChanged($notification), [
            'notification_id' => $notification->id,
            'status' => $notification->status->value,
        ]);
    }

    /**
     * @param  array{batch_id: string, total: int, statuses: array<string, int>}  $summary
     */
    public function batchUpdated(array $summary): void
    {
        $this->broadcast(new BatchUpdated($summary), ['batch_id' => $summary['batch_id']]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function broadcast(object $event, array $context): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }

        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Real-time broadcast failed', $context + ['error' => $e->getMessage()]);
        }
    }
}
