<?php

namespace App\Listeners;

use App\Events\NotificationsAccepted;
use App\Services\NotificationDispatcher;

/**
 * Fans accepted notifications out to the priority queues (pending → queued).
 * A notification cancelled in the meantime is no longer pending and is skipped.
 */
class QueueAcceptedNotifications
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function handle(NotificationsAccepted $event): void
    {
        $this->dispatcher->queuePending($event->notificationIds);
    }
}
