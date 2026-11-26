<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @return array<string, mixed>
     */
    private function sms(array $overrides = []): array
    {
        return ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Sale starts!', ...$overrides];
    }

    public function test_a_future_notification_is_stored_as_scheduled_and_not_queued(): void
    {
        $at = now()->addHours(2)->startOfSecond();

        $response = $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => $at->toIso8601String()]))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_at', $at->toIso8601String());

        $this->assertSame(Status::Scheduled, Notification::find($response->json('data.id'))->status);
        Queue::assertNothingPushed();
    }

    public function test_a_past_or_current_time_sends_right_away(): void
    {
        $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => now()->subMinute()->toIso8601String()]))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_scheduling_more_than_30_days_ahead_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => now()->addDays(31)->toIso8601String()]))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['scheduled_at']]]);

        $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => 'next tuesday-ish']))
            ->assertStatus(422);
    }

    public function test_time_zone_offsets_are_respected(): void
    {
        // 15:00 in Istanbul (+03:00) is 12:00 UTC.
        $this->travelTo('2026-11-27 10:00:00');

        $response = $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => '2026-11-27T15:00:00+03:00']))
            ->assertStatus(202);

        $this->assertSame('2026-11-27 12:00:00', Notification::find($response->json('data.id'))->scheduled_at->utc()->toDateTimeString());
    }

    public function test_a_batch_can_mix_immediate_and_scheduled_items(): void
    {
        $response = $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            $this->sms(),
            $this->sms(['scheduled_at' => now()->addDay()->toIso8601String()]),
        ]])->assertStatus(202)
            ->assertJsonPath('data.notifications.0.status', 'pending')
            ->assertJsonPath('data.notifications.1.status', 'scheduled');

        Queue::assertPushed(SendNotificationJob::class, 1);
        $this->getJson('/api/v1/batches/'.$response->json('data.batch_id'))
            ->assertJsonPath('data.statuses.queued', 1)
            ->assertJsonPath('data.statuses.scheduled', 1);
    }

    public function test_due_notifications_are_queued_when_their_time_comes(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        $dueSoon = Notification::factory()->scheduled(now()->addMinutes(5))->create();
        $later = Notification::factory()->scheduled(now()->addHour())->create();

        $this->artisan('notifications:dispatch-scheduled')->expectsOutputToContain('Queued 0')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travelTo('2026-11-27 10:05:00');
        $this->artisan('notifications:dispatch-scheduled')->expectsOutputToContain('Queued 1')->assertSuccessful();

        $this->assertSame(Status::Queued, $dueSoon->fresh()->status);
        $this->assertSame(Status::Scheduled, $later->fresh()->status);
        Queue::assertPushedOn($dueSoon->priority->queue(), SendNotificationJob::class, fn ($job) => $job->notificationId === $dueSoon->id);
    }

    public function test_a_large_campaign_due_at_once_is_released_completely(): void
    {
        $this->travelTo('2026-11-27 10:00:00');
        Notification::factory()->count(1203)->scheduled(now()->subSecond())->create();

        $this->artisan('notifications:dispatch-scheduled')->expectsOutputToContain('Queued 1203')->assertSuccessful();

        Queue::assertPushed(SendNotificationJob::class, 1203);
    }

    public function test_cancelled_scheduled_notifications_are_never_queued(): void
    {
        $notification = Notification::factory()->scheduled(now()->addMinute())->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->travel(2)->minutes();
        $this->artisan('notifications:dispatch-scheduled')->expectsOutputToContain('Queued 0');

        Queue::assertNothingPushed();
    }

    public function test_idempotent_replay_still_matches_after_the_scheduled_time_passed(): void
    {
        $payload = $this->sms(['scheduled_at' => now()->addMinutes(10)->toIso8601String()]);

        $first = $this->postJson('/api/v1/notifications', $payload, ['Idempotency-Key' => 'promo-1'])->assertStatus(202);
        $this->travel(1)->hour();

        $this->postJson('/api/v1/notifications', $payload, ['Idempotency-Key' => 'promo-1'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_a_different_schedule_with_the_same_key_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => now()->addHour()->toIso8601String()]), ['Idempotency-Key' => 'promo-2'])
            ->assertStatus(202);

        $this->postJson('/api/v1/notifications', $this->sms(['scheduled_at' => now()->addHours(2)->toIso8601String()]), ['Idempotency-Key' => 'promo-2'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    public function test_the_dispatcher_runs_every_ten_seconds(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('notifications:dispatch-scheduled')
            ->assertSuccessful();
    }
}
