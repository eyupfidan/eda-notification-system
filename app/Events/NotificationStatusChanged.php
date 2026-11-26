<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed over WebSocket (Laravel Reverb, Pusher protocol) whenever a notification changes status.
 *
 * Channels: `notifications.{id}` for one notification and `batches.{batchId}` for a whole batch.
 * Event name: `notification.status`.
 *
 * The payload is captured when the event is created, so it always describes *that* transition
 * even if the model changes again before the broadcast is sent.
 */
class NotificationStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @var array<string, mixed> */
    public readonly array $payload;

    public function __construct(Notification $notification)
    {
        $this->payload = [
            'id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel->value,
            'status' => $notification->status->value,
            'attempts' => $notification->attempts,
            'next_retry_at' => $notification->next_retry_at?->toIso8601String(),
            'last_error' => $notification->last_error,
            'updated_at' => $notification->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Public channels: ids are unguessable UUIDv7s. With authentication in place these
     * would become private channels authorised per API client.
     *
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return array_values(array_filter([
            new Channel('notifications.'.$this->payload['id']),
            $this->payload['batch_id'] !== null ? new Channel('batches.'.$this->payload['batch_id']) : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'notification.status';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
