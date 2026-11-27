<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Events\BatchUpdated;
use App\Events\NotificationStatusChanged;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function enableBroadcasting(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([NotificationStatusChanged::class, BatchUpdated::class]);
    }

    public function test_every_status_change_of_a_delivery_is_broadcast(): void
    {
        $this->enableBroadcasting();
        Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);
        $notification = Notification::factory()->status(Status::Queued)->create();

        dispatch(new SendNotificationJob($notification->id, $notification->channel, $notification->priority));

        $statuses = [];
        Event::assertDispatched(NotificationStatusChanged::class, function (NotificationStatusChanged $e) use (&$statuses, $notification) {
            $statuses[] = $e->payload['status'];

            return $e->payload['id'] === $notification->id;
        });
        $this->assertSame(['processing', 'sent'], $statuses);
    }

    public function test_the_event_targets_notification_and_batch_channels_with_a_compact_payload(): void
    {
        $batchId = (string) Str::uuid7();
        $notification = Notification::factory()->inBatch($batchId)->status(Status::Queued)->create(['attempts' => 2]);

        $event = new NotificationStatusChanged($notification);

        $this->assertSame(
            ["notifications.{$notification->id}", "batches.{$batchId}"],
            array_map(fn ($channel) => $channel->name, $event->broadcastOn()),
        );
        $this->assertSame('notification.status', $event->broadcastAs());
        $this->assertSame(['id', 'batch_id', 'channel', 'status', 'attempts', 'next_retry_at', 'last_error', 'updated_at'], array_keys($event->broadcastWith()));
        $this->assertSame(2, $event->broadcastWith()['attempts']);
    }

    public function test_single_notifications_only_use_their_own_channel(): void
    {
        $notification = Notification::factory()->create();

        $this->assertCount(1, (new NotificationStatusChanged($notification))->broadcastOn());
    }

    public function test_cancelling_broadcasts_the_new_status(): void
    {
        $this->enableBroadcasting();
        $notification = Notification::factory()->status(Status::Pending)->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")->assertOk();

        Event::assertDispatched(NotificationStatusChanged::class, fn ($e) => $e->payload['status'] === 'cancelled');
    }

    public function test_batch_operations_broadcast_the_new_counts(): void
    {
        $this->enableBroadcasting();
        $batchId = (string) Str::uuid7();
        Notification::factory()->count(2)->inBatch($batchId)->status(Status::Pending)->create();

        $this->patchJson("/api/v1/batches/{$batchId}/cancel")->assertOk();

        Event::assertDispatched(BatchUpdated::class, fn (BatchUpdated $e) => $e->broadcastOn()[0]->name === "batches.{$batchId}"
            && $e->broadcastWith()['statuses']['cancelled'] === 2);
    }

    public function test_delivery_continues_when_the_websocket_server_is_down(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
                'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
            ],
        ]);
        Log::spy();
        Http::fake(['provider.test/*' => Http::response(['messageId' => 'm'], 202)]);
        $notification = Notification::factory()->status(Status::Queued)->create();

        dispatch(new SendNotificationJob($notification->id, $notification->channel, $notification->priority));

        $this->assertSame(Status::Sent, $notification->fresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Real-time broadcast failed')->atLeast()->once();
    }

    public function test_the_demo_page_is_served(): void
    {
        $this->get('/realtime')
            ->assertOk()
            ->assertSee('Live notification status')
            ->assertSee('Canlı bildirim durumu', false)
            ->assertSee('data-lang="tr"', false)
            ->assertSee('pusher-js', false);
    }
}
