<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'correlation_id' => $this->correlation_id,
            'channel' => $this->channel,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'template_id' => $this->template_id,
            'template_variables' => $this->template_variables,
            'priority' => $this->priority,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->attempt_limit ?? (int) config('notifications.retry.max_attempts'),
            'provider_message_id' => $this->provider_message_id,
            'last_error' => $this->last_error,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'delivery_attempts' => NotificationAttemptResource::collection($this->whenLoaded('deliveryAttempts')),
        ];
    }
}
