<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoveryAndRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function make(Status $status, int $minutesAgo, array $attributes = []): Notification
    {
        $at = now()->subMinutes($minutesAgo);

        return Notification::factory()->status($status)->create([
            'created_at' => $at,
            'updated_at' => $at,
            'queued_at' => $status === Status::Queued ? $at : null,
            ...$attributes,
        ]);
    }

    // ------------------------------------------------------------- recovery

    public function test_it_recovers_notifications_stuck_in_non_final_states(): void
    {
        $stalePending = $this->make(Status::Pending, minutesAgo: 5);
        $staleProcessing = $this->make(Status::Processing, minutesAgo: 5);
        $staleQueued = $this->make(Status::Queued, minutesAgo: 30);

        $this->artisan('notifications:recover')
            ->expectsOutputToContain('Recovered: 1 pending, 1 processing, 1 queued.')
            ->assertSuccessful();

        foreach ([$stalePending, $staleProcessing, $staleQueued] as $notification) {
            $this->assertSame(Status::Queued, $notification->fresh()->status);
            Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->notificationId === $notification->id);
        }
        Queue::assertPushed(SendNotificationJob::class, 3);
    }

    public function test_it_leaves_healthy_and_final_notifications_alone(): void
    {
        $this->make(Status::Pending, minutesAgo: 0);                  // just accepted
        $this->make(Status::Processing, minutesAgo: 1);               // worker still busy
        $this->make(Status::Queued, 30, [                 // waiting for a scheduled retry
            'next_retry_at' => now()->addMinutes(5),
        ]);
        $this->make(Status::Scheduled, minutesAgo: 60);
        $this->make(Status::Sent, minutesAgo: 60);
        $this->make(Status::Failed, minutesAgo: 60);
        $this->make(Status::Cancelled, minutesAgo: 60);

        $this->artisan('notifications:recover')
            ->expectsOutputToContain('Recovered: 0 pending, 0 processing, 0 queued.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_running_recovery_twice_does_not_dispatch_twice(): void
    {
        $this->make(Status::Pending, minutesAgo: 5);

        $this->artisan('notifications:recover');
        $this->artisan('notifications:recover');

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_recovery_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('notifications:recover')
            ->assertSuccessful();
    }

    // --------------------------------------------------------- manual retry

    public function test_a_failed_notification_can_be_retried_with_a_fresh_attempt_budget(): void
    {
        $notification = Notification::factory()->status(Status::Failed)->create(['attempts' => 5]);

        $this->postJson("/api/v1/notifications/{$notification->id}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.attempts', 5)
            ->assertJsonPath('data.max_attempts', 10);

        $this->assertNull($notification->fresh()->failed_at);
        Queue::assertPushedOn($notification->priority->queue(), SendNotificationJob::class);
    }

    public function test_only_failed_notifications_can_be_retried(): void
    {
        $notification = Notification::factory()->status(Status::Sent)->create();

        $this->postJson("/api/v1/notifications/{$notification->id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_status_transition');

        Queue::assertNothingPushed();
    }

    public function test_failed_notifications_of_a_batch_can_be_retried_together(): void
    {
        $batchId = (string) Str::uuid7();
        Notification::factory()->count(3)->inBatch($batchId)->status(Status::Failed)->create();
        Notification::factory()->count(2)->inBatch($batchId)->status(Status::Sent)->create();

        $this->postJson("/api/v1/batches/{$batchId}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.retried', 3)
            ->assertJsonPath('data.statuses.queued', 3)
            ->assertJsonPath('data.statuses.sent', 2)
            ->assertJsonPath('data.statuses.failed', 0);

        Queue::assertPushed(SendNotificationJob::class, 3);
    }

    public function test_retrying_an_unknown_batch_returns_404(): void
    {
        $this->postJson('/api/v1/batches/'.Str::uuid7().'/retry')->assertNotFound();
    }
}
