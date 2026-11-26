<?php

namespace App\Http\Resources;

use App\Models\NotificationAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationAttempt
 */
class NotificationAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attempt' => $this->attempt,
            'outcome' => $this->outcome,
            'http_status' => $this->http_status,
            'duration_ms' => $this->duration_ms,
            'provider_message_id' => $this->provider_message_id,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
