<?php

namespace Tests\Feature;

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_notification_with_defaults_and_enum_casts(): void
    {
        $notification = Notification::create([
            'channel' => Channel::Sms,
            'recipient' => '+905551234567',
            'content' => 'Hello',
        ])->refresh();

        $this->assertTrue(Str::isUuid($notification->id));
        $this->assertSame(Channel::Sms, $notification->channel);
        $this->assertSame(Priority::Normal, $notification->priority);
        $this->assertSame(Status::Pending, $notification->status);
        $this->assertSame(0, $notification->attempts);
    }

    public function test_transition_to_persists_status_and_lifecycle_timestamp(): void
    {
        $notification = Notification::factory()->create();

        $notification->transitionTo(Status::Queued);

        $fresh = $notification->fresh();
        $this->assertSame(Status::Queued, $fresh->status);
        $this->assertNotNull($fresh->queued_at);
    }

    public function test_transition_to_rejects_invalid_transition(): void
    {
        $notification = Notification::factory()->status(Status::Sent)->create();

        $this->expectException(InvalidStatusTransition::class);

        $notification->transitionTo(Status::Queued);
    }

    public function test_compare_and_set_transition_succeeds_only_once(): void
    {
        $notification = Notification::factory()->status(Status::Queued)->create();

        // Two workers holding the same row in memory (e.g. a duplicated job).
        $workerA = Notification::find($notification->id);
        $workerB = Notification::find($notification->id);

        $this->assertTrue($workerA->transitionIfStatus(Status::Queued, Status::Processing));
        $this->assertFalse($workerB->transitionIfStatus(Status::Queued, Status::Processing));

        $this->assertSame(Status::Processing, $notification->fresh()->status);
    }

    public function test_cancel_loses_the_race_once_processing_has_started(): void
    {
        $notification = Notification::factory()->status(Status::Queued)->create();
        $staleCopy = Notification::find($notification->id);

        $notification->transitionIfStatus(Status::Queued, Status::Processing);

        $cancelled = $staleCopy->transitionIfStatus(Status::cancellable(), Status::Cancelled);

        $this->assertFalse($cancelled);
        $this->assertSame(Status::Processing, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->cancelled_at);
    }

    public function test_compare_and_set_updates_in_memory_model_and_extra_attributes(): void
    {
        $notification = Notification::factory()->status(Status::Processing)->create();

        $ok = $notification->transitionIfStatus(Status::Processing, Status::Sent, [
            'provider_message_id' => 'msg-123',
        ]);

        $this->assertTrue($ok);
        $this->assertSame(Status::Sent, $notification->status);
        $this->assertSame('msg-123', $notification->provider_message_id);
        $this->assertNotNull($notification->fresh()->sent_at);
        $this->assertFalse($notification->isDirty());
    }

    public function test_idempotency_key_is_unique(): void
    {
        Notification::factory()->create(['idempotency_key' => 'order-42-sms']);

        $this->expectException(QueryException::class);

        Notification::factory()->create(['idempotency_key' => 'order-42-sms']);
    }

    public function test_database_rejects_unknown_channel(): void
    {
        $this->expectException(QueryException::class);

        Notification::query()->insert([
            'id' => (string) Str::uuid7(),
            'channel' => 'fax',
            'recipient' => '123',
            'content' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_delivery_attempts_are_ordered_and_cascade_on_delete(): void
    {
        $notification = Notification::factory()->create();
        NotificationAttempt::factory()->for($notification)->create(['attempt' => 2]);
        NotificationAttempt::factory()->for($notification)->retryableFailure()->create(['attempt' => 1]);

        $this->assertSame([1, 2], $notification->deliveryAttempts->pluck('attempt')->all());
        $this->assertSame(AttemptOutcome::RetryableFailure, $notification->deliveryAttempts->first()->outcome);

        $notification->delete();

        $this->assertSame(0, NotificationAttempt::count());
    }
}
