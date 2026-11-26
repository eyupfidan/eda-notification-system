<?php

namespace Database\Factories;

use App\Enums\AttemptOutcome;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationAttempt>
 */
class NotificationAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'attempt' => 1,
            'outcome' => AttemptOutcome::Success,
            'http_status' => 202,
            'duration_ms' => fake()->numberBetween(40, 400),
            'provider_message_id' => fake()->uuid(),
            'error' => null,
        ];
    }

    public function retryableFailure(int $httpStatus = 503): static
    {
        return $this->state(fn () => [
            'outcome' => AttemptOutcome::RetryableFailure,
            'http_status' => $httpStatus,
            'provider_message_id' => null,
            'error' => "Provider responded with HTTP {$httpStatus}",
        ]);
    }

    public function permanentFailure(int $httpStatus = 400): static
    {
        return $this->state(fn () => [
            'outcome' => AttemptOutcome::PermanentFailure,
            'http_status' => $httpStatus,
            'provider_message_id' => null,
            'error' => "Provider responded with HTTP {$httpStatus}",
        ]);
    }
}
