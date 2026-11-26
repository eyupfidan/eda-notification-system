<?php

namespace App\Listeners;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Events\NotificationsAccepted;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\DB;

/**
 * Fans accepted notifications out to the priority queues.
 *
 * Rows are flipped pending → queued with a single UPDATE … RETURNING, so a notification
 * cancelled in the meantime is simply not returned and never dispatched.
 */
class QueueAcceptedNotifications
{
    private const CHUNK = 500;

    public function handle(NotificationsAccepted $event): void
    {
        foreach (array_chunk($event->notificationIds, self::CHUNK) as $ids) {
            $this->queue($ids);
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function queue(array $ids): void
    {
        $now = now();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::select(
            "UPDATE notifications
                SET status = ?, queued_at = ?, updated_at = ?
              WHERE status = ? AND id IN ({$placeholders})
          RETURNING id, channel, priority",
            [Status::Queued->value, $now, $now, Status::Pending->value, ...$ids],
        );

        foreach ($rows as $row) {
            dispatch(new SendNotificationJob(
                $row->id,
                Channel::from($row->channel),
                Priority::from($row->priority),
            ));
        }
    }
}
