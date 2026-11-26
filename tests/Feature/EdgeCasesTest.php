<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Events\NotificationsAccepted;
use App\Exceptions\InvalidStatusTransition;
use App\Jobs\SendNotificationJob;
use App\Listeners\RecordWorkerHeartbeat;
use App\Models\Notification;
use App\Services\NotificationDispatcher;
use App\Services\NotificationService;
use App\Services\Observability\Heartbeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Concurrency races and rarely-hit paths that the main feature tests do not reach.
 */
class EdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /** Rows a "competing request" committed on its own connection; cleaned up after each test. */
    private array $raceRowIds = [];

    protected function tearDown(): void
    {
        if ($this->raceRowIds !== []) {
            DB::connection('race')->table('notifications')->whereIn('id', $this->raceRowIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Simulates a second request that passes the "key already used?" pre-check at the same
     * time as ours and commits first: right before our INSERT runs, a row with the same
     * idempotency key is committed through a separate database connection.
     *
     * @param  array<string, mixed>  $row
     */
    private function competingRequestCommitsFirst(array $row): void
    {
        config(['database.connections.race' => config('database.connections.pgsql')]);
        $raced = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$raced, $row) {
            if ($raced || ! str_starts_with($query, 'insert into "notifications"')) {
                return;
            }

            $raced = true;
            $this->raceRowIds[] = $row['id'];
            DB::connection('race')->table('notifications')->insert($row + [
                'status' => 'pending',
                'priority' => 'normal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    // ----------------------------------------------------------- idempotency

    public function test_losing_the_idempotency_race_returns_the_winners_notification(): void
    {
        Event::fake([NotificationsAccepted::class]);
        $winnerId = (string) Str::uuid7();
        $this->competingRequestCommitsFirst([
            'id' => $winnerId,
            'idempotency_key' => 'race-key',
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello',
        ]);

        $this->postJson('/api/v1/notifications', [
            'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello',
        ], ['Idempotency-Key' => 'race-key'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $winnerId);

        Event::assertNotDispatched(NotificationsAccepted::class);
    }

    public function test_losing_the_batch_idempotency_race_returns_the_winners_batch(): void
    {
        Event::fake([NotificationsAccepted::class]);
        $winnerBatch = (string) Str::uuid7();
        $this->competingRequestCommitsFirst([
            'id' => (string) Str::uuid7(),
            'batch_id' => $winnerBatch,
            'idempotency_key' => 'race-batch#0',
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello',
        ]);

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hello'],
        ]], ['Idempotency-Key' => 'race-batch'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.batch_id', $winnerBatch);
    }

    public function test_batch_replay_with_same_size_but_different_items_is_rejected(): void
    {
        Event::fake([NotificationsAccepted::class]);
        $item = fn (string $content) => ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => $content];

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [$item('A'), $item('B')]], ['Idempotency-Key' => 'k'])
            ->assertStatus(202);

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [$item('A'), $item('changed')]], ['Idempotency-Key' => 'k'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    // ---------------------------------------------------------------- cancel

    public function test_concurrent_cancel_requests_both_succeed(): void
    {
        $notification = Notification::factory()->status(Status::Pending)->create();
        $staleCopy = Notification::find($notification->id);

        app(NotificationService::class)->cancel($notification);
        $result = app(NotificationService::class)->cancel($staleCopy); // still thinks it is pending

        $this->assertSame(Status::Cancelled, $result->status);
    }

    public function test_cancelling_an_unknown_batch_returns_404(): void
    {
        $this->patchJson('/api/v1/batches/'.Str::uuid7().'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Batch not found.');
    }

    // --------------------------------------------------------- state machine

    public function test_compare_and_set_rejects_an_impossible_source_state(): void
    {
        $notification = Notification::factory()->status(Status::Sent)->create();

        $this->expectException(InvalidStatusTransition::class);

        $notification->transitionIfStatus(Status::Sent, Status::Queued);
    }

    public function test_retrying_nothing_is_a_no_op(): void
    {
        $this->assertSame(0, app(NotificationDispatcher::class)->retryFailed([]));
    }

    // ------------------------------------------------------------------ jobs

    public function test_a_job_for_a_deleted_notification_exits_quietly(): void
    {
        Http::preventStrayRequests();
        $notification = Notification::factory()->status(Status::Queued)->create();
        $job = new SendNotificationJob($notification->id, Channel::Sms, Priority::High);
        $notification->delete();

        app()->call([$job, 'handle']);

        Http::assertNothingSent();
    }

    // --------------------------------------------------------- observability

    public function test_metrics_read_real_redis_queue_depth(): void
    {
        config(['queue.default' => 'redis']);
        Queue::connection('redis')->pushOn('high', new SendNotificationJob((string) Str::uuid7(), Channel::Sms, Priority::High));
        Queue::connection('redis')->laterOn('low', 60, new SendNotificationJob((string) Str::uuid7(), Channel::Push, Priority::Low));

        $this->getJson('/metrics')
            ->assertOk()
            ->assertJsonPath('queues.high.ready', 1)
            ->assertJsonPath('queues.low.delayed', 1)
            ->assertJsonPath('queues.normal.total', 0);
    }

    public function test_worker_loop_events_become_throttled_heartbeats(): void
    {
        $lastBeat = new \ReflectionProperty(RecordWorkerHeartbeat::class, 'lastBeat');
        $lastBeat->setValue(null, 0);

        event(new Looping('redis', 'high,normal,low'));
        $this->assertSame(1, app(Heartbeats::class)->activeWorkers());

        // A second loop within the throttle interval does not write again.
        app(Heartbeats::class)->forget();
        event(new Looping('redis', 'high,normal,low'));
        $this->assertSame(0, app(Heartbeats::class)->activeWorkers());
    }
}
