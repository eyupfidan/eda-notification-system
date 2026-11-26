<?php

namespace Tests\Feature;

use App\Enums\Priority;
use App\Enums\Status;
use App\Events\NotificationsAccepted;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueAcceptedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_accepted_notifications_are_marked_queued_and_pushed_to_their_priority_queue(): void
    {
        $high = Notification::factory()->priority(Priority::High)->create();
        $normal = Notification::factory()->email()->priority(Priority::Normal)->create();
        $low = Notification::factory()->push()->priority(Priority::Low)->create();

        NotificationsAccepted::dispatch([$high->id, $normal->id, $low->id]);

        foreach ([$high, $normal, $low] as $notification) {
            $fresh = $notification->fresh();
            $this->assertSame(Status::Queued, $fresh->status);
            $this->assertNotNull($fresh->queued_at);
        }

        Queue::assertPushedOn('high', SendNotificationJob::class, fn ($job) => $job->notificationId === $high->id);
        Queue::assertPushedOn('normal', SendNotificationJob::class, fn ($job) => $job->notificationId === $normal->id);
        Queue::assertPushedOn('low', SendNotificationJob::class, fn ($job) => $job->notificationId === $low->id);
        Queue::assertPushed(SendNotificationJob::class, 3);
    }

    public function test_notifications_cancelled_before_queueing_are_not_dispatched(): void
    {
        $pending = Notification::factory()->create();
        $cancelled = Notification::factory()->status(Status::Cancelled)->create();

        NotificationsAccepted::dispatch([$pending->id, $cancelled->id]);

        $this->assertSame(Status::Cancelled, $cancelled->fresh()->status);
        Queue::assertPushed(SendNotificationJob::class, 1);
        Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->notificationId === $pending->id);
    }

    public function test_replaying_the_event_does_not_dispatch_twice(): void
    {
        $notification = Notification::factory()->create();

        NotificationsAccepted::dispatch([$notification->id]);
        NotificationsAccepted::dispatch([$notification->id]);

        Queue::assertPushed(SendNotificationJob::class, 1);
    }

    public function test_creating_through_the_api_queues_the_notification(): void
    {
        $response = $this->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello',
            'priority' => 'high',
        ])->assertStatus(202);

        $id = $response->json('data.id');

        $this->assertSame(Status::Queued, Notification::find($id)->status);
        Queue::assertPushedOn('high', SendNotificationJob::class, fn ($job) => $job->notificationId === $id);
    }

    public function test_a_full_batch_is_queued(): void
    {
        $item = ['channel' => 'push', 'recipient' => str_repeat('a', 64), 'content' => 'Sale', 'priority' => 'low'];

        $this->postJson('/api/v1/notifications/batch', ['notifications' => array_fill(0, 1000, $item)])
            ->assertStatus(202);

        $this->assertSame(1000, Notification::where('status', Status::Queued)->count());
        Queue::assertPushed(SendNotificationJob::class, 1000);
    }
}
