<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised once notifications are durably stored. Fired only after the DB transaction
 * commits, so listeners never see rows that could still be rolled back.
 */
class NotificationsAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $notificationIds
     */
    public function __construct(
        public readonly array $notificationIds,
        public readonly ?string $batchId = null,
    ) {}
}
