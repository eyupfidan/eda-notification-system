<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => Channel::Sms,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'subject' => null,
            'content' => fake()->sentence(),
            'priority' => fake()->randomElement(Priority::cases()),
            'status' => Status::Pending,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => Channel::Sms,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'subject' => null,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => Channel::Email,
            'recipient' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'content' => fake()->paragraph(),
        ]);
    }

    public function push(): static
    {
        return $this->state(fn () => [
            'channel' => Channel::Push,
            'recipient' => fake()->sha256(),
            'subject' => fake()->sentence(3),
        ]);
    }

    public function priority(Priority $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }

    public function status(Status $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => Status::Scheduled,
            'scheduled_at' => $at ?? now()->addHour(),
        ]);
    }

    public function inBatch(string $batchId): static
    {
        return $this->state(fn () => ['batch_id' => $batchId]);
    }
}
