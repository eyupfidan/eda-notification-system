<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed on `batches.{batchId}` after a bulk operation (batch cancel / batch retry),
 * carrying the batch's new status counts. Event name: `batch.updated`.
 */
class BatchUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array{batch_id: string, total: int, statuses: array<string, int>}  $summary
     */
    public function __construct(public readonly array $summary) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('batches.'.$this->summary['batch_id'])];
    }

    public function broadcastAs(): string
    {
        return 'batch.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->summary;
    }
}
