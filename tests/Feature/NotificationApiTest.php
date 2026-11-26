<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Events\NotificationsAccepted;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NotificationsAccepted::class]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sms(array $overrides = []): array
    {
        return [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Your order has shipped.',
            'priority' => 'high',
            ...$overrides,
        ];
    }

    // ---------------------------------------------------------------- create

    public function test_it_accepts_a_notification_and_raises_the_accepted_event(): void
    {
        $response = $this->postJson('/api/v1/notifications', $this->sms());

        $response->assertStatus(202)
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.status', 'pending');

        $id = $response->json('data.id');
        $this->assertTrue(Str::isUuid($id));
        $response->assertHeader('Location', url("/api/v1/notifications/{$id}"));
        $this->assertDatabaseHas('notifications', ['id' => $id, 'recipient' => '+905551234567']);

        Event::assertDispatched(NotificationsAccepted::class, fn ($e) => $e->notificationIds === [$id]);
    }

    public function test_priority_defaults_to_normal(): void
    {
        $payload = $this->sms();
        unset($payload['priority']);

        $this->postJson('/api/v1/notifications', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        $this->postJson('/api/v1/notifications', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['channel', 'recipient', 'content']]]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'unknown channel' => [['channel' => 'fax'], 'channel'],
            'sms without E.164 number' => [['recipient' => '05551234567'], 'recipient'],
            'sms content over 1600 chars' => [['content' => str_repeat('a', 1601)], 'content'],
            'email with invalid address' => [['channel' => 'email', 'recipient' => 'not-an-email', 'subject' => 'Hi'], 'recipient'],
            'email without subject' => [['channel' => 'email', 'recipient' => 'jane@example.com'], 'subject'],
            'push with short token' => [['channel' => 'push', 'recipient' => 'abc'], 'recipient'],
            'push content over 1000 chars' => [['channel' => 'push', 'recipient' => str_repeat('a', 64), 'content' => str_repeat('ü', 1001)], 'content'],
            'unknown priority' => [['priority' => 'urgent'], 'priority'],
            'metadata not an object' => [['metadata' => 'x'], 'metadata'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_payloads(array $overrides, string $field): void
    {
        $this->postJson('/api/v1/notifications', $this->sms($overrides))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => [$field]]]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_content_limit_counts_characters_not_bytes(): void
    {
        // 1600 multibyte characters is still within the SMS limit.
        $this->postJson('/api/v1/notifications', $this->sms(['content' => str_repeat('ş', 1600)]))
            ->assertStatus(202);
    }

    // ----------------------------------------------------------- idempotency

    public function test_same_idempotency_key_and_payload_returns_the_original_notification(): void
    {
        $first = $this->postJson('/api/v1/notifications', $this->sms(), ['Idempotency-Key' => 'order-42']);
        $second = $this->postJson('/api/v1/notifications', $this->sms(), ['Idempotency-Key' => 'order-42']);

        $first->assertStatus(202);
        $second->assertStatus(200)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeaderMissing('Location') // PHP-FPM would turn a 200 + Location into a 302
            ->assertHeader('Content-Location', url('/api/v1/notifications/'.$first->json('data.id')))
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('notifications', 1);
        Event::assertDispatchedTimes(NotificationsAccepted::class, 1);
    }

    public function test_reusing_an_idempotency_key_with_a_different_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications', $this->sms(), ['Idempotency-Key' => 'order-42'])->assertStatus(202);

        $this->postJson('/api/v1/notifications', $this->sms(['content' => 'Different']), ['Idempotency-Key' => 'order-42'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_malformed_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications', $this->sms(), ['Idempotency-Key' => 'has spaces'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_idempotency_key');
    }

    // ---------------------------------------------------------------- batch

    public function test_it_accepts_a_batch_atomically_with_a_shared_batch_id(): void
    {
        $response = $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            $this->sms(),
            ['channel' => 'email', 'recipient' => 'jane@example.com', 'subject' => 'Sale', 'content' => 'Flash sale!'],
            ['channel' => 'push', 'recipient' => str_repeat('f', 64), 'content' => 'Breaking news', 'metadata' => ['url' => '/news/1']],
        ]]);

        $response->assertStatus(202)
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(3, 'data.notifications')
            ->assertJsonPath('data.notifications.1.channel', 'email')
            ->assertJsonPath('data.notifications.2.status', 'pending');

        $batchId = $response->json('data.batch_id');
        $response->assertHeader('Location', url("/api/v1/batches/{$batchId}"));
        $this->assertSame(3, Notification::where('batch_id', $batchId)->count());
        $this->assertSame(['url' => '/news/1'], Notification::where('channel', 'push')->first()->metadata);

        Event::assertDispatched(NotificationsAccepted::class, fn ($e) => $e->batchId === $batchId
            && $e->notificationIds === $response->json('data.notifications.*.id'));
    }

    public function test_batch_accepts_exactly_the_maximum_size(): void
    {
        $this->postJson('/api/v1/notifications/batch', ['notifications' => array_fill(0, 1000, $this->sms())])
            ->assertStatus(202)
            ->assertJsonPath('data.total', 1000);

        $this->assertDatabaseCount('notifications', 1000);
    }

    public function test_batch_larger_than_the_maximum_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications/batch', ['notifications' => array_fill(0, 1001, $this->sms())])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notifications']]]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_one_invalid_item_rejects_the_whole_batch(): void
    {
        $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            $this->sms(),
            $this->sms(['recipient' => 'bad']),
            ['channel' => 'email', 'recipient' => 'jane@example.com', 'content' => 'No subject'],
        ]])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notifications.1.recipient', 'notifications.2.subject']]]);

        $this->assertDatabaseCount('notifications', 0);
        Event::assertNotDispatched(NotificationsAccepted::class);
    }

    public function test_batch_idempotency_replays_the_same_batch(): void
    {
        $payload = ['notifications' => [$this->sms(), $this->sms(['recipient' => '+905559876543'])]];

        $first = $this->postJson('/api/v1/notifications/batch', $payload, ['Idempotency-Key' => 'campaign-7']);
        $second = $this->postJson('/api/v1/notifications/batch', $payload, ['Idempotency-Key' => 'campaign-7']);

        $first->assertStatus(202);
        $second->assertStatus(200)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.batch_id', $first->json('data.batch_id'))
            ->assertJsonPath('data.notifications.*.id', $first->json('data.notifications.*.id'));

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_batch_idempotency_key_with_a_different_payload_is_rejected(): void
    {
        $this->postJson('/api/v1/notifications/batch', ['notifications' => [$this->sms()]], ['Idempotency-Key' => 'campaign-7'])
            ->assertStatus(202);

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [$this->sms(), $this->sms()]], ['Idempotency-Key' => 'campaign-7'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_reused');
    }

    // ----------------------------------------------------------------- query

    public function test_it_shows_a_notification_with_its_delivery_attempts(): void
    {
        $notification = Notification::factory()->status(Status::Sent)->create();
        NotificationAttempt::factory()->for($notification)->retryableFailure()->create(['attempt' => 1]);
        NotificationAttempt::factory()->for($notification)->create(['attempt' => 2]);

        $this->getJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonCount(2, 'data.delivery_attempts')
            ->assertJsonPath('data.delivery_attempts.0.outcome', 'retryable_failure')
            ->assertJsonPath('data.delivery_attempts.1.outcome', 'success');
    }

    public function test_unknown_or_malformed_ids_return_404(): void
    {
        $this->getJson('/api/v1/notifications/'.Str::uuid7())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/v1/notifications/not-a-uuid')->assertNotFound();
        $this->getJson('/api/v1/batches/'.Str::uuid7())
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Batch not found.');
    }

    public function test_batch_status_counts_notifications_per_status(): void
    {
        $batchId = (string) Str::uuid7();
        Notification::factory()->count(3)->inBatch($batchId)->status(Status::Sent)->create();
        Notification::factory()->count(2)->inBatch($batchId)->status(Status::Failed)->create();
        Notification::factory()->inBatch($batchId)->status(Status::Queued)->create();
        Notification::factory()->status(Status::Sent)->create(); // other batch

        $this->getJson("/api/v1/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.statuses.sent', 3)
            ->assertJsonPath('data.statuses.failed', 2)
            ->assertJsonPath('data.statuses.queued', 1)
            ->assertJsonPath('data.statuses.pending', 0);
    }

    // ---------------------------------------------------------------- cancel

    #[DataProvider('cancellableStatuses')]
    public function test_it_cancels_notifications_that_are_not_being_delivered(Status $status): void
    {
        $notification = Notification::factory()->status($status)->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($notification->fresh()->cancelled_at);
    }

    /**
     * @return array<string, array{Status}>
     */
    public static function cancellableStatuses(): array
    {
        return ['scheduled' => [Status::Scheduled], 'pending' => [Status::Pending], 'queued' => [Status::Queued]];
    }

    #[DataProvider('nonCancellableStatuses')]
    public function test_it_refuses_to_cancel_once_delivery_started_or_finished(Status $status): void
    {
        $notification = Notification::factory()->status($status)->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_status_transition');

        $this->assertSame($status, $notification->fresh()->status);
    }

    /**
     * @return array<string, array{Status}>
     */
    public static function nonCancellableStatuses(): array
    {
        return ['processing' => [Status::Processing], 'sent' => [Status::Sent], 'failed' => [Status::Failed]];
    }

    public function test_cancelling_twice_is_idempotent(): void
    {
        $notification = Notification::factory()->status(Status::Pending)->create();

        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")->assertOk();
        $this->patchJson("/api/v1/notifications/{$notification->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_a_batch_only_touches_cancellable_notifications(): void
    {
        $batchId = (string) Str::uuid7();
        Notification::factory()->count(2)->inBatch($batchId)->status(Status::Pending)->create();
        Notification::factory()->inBatch($batchId)->status(Status::Queued)->create();
        Notification::factory()->inBatch($batchId)->status(Status::Processing)->create();
        Notification::factory()->inBatch($batchId)->status(Status::Sent)->create();

        $this->patchJson("/api/v1/batches/{$batchId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.cancelled', 3)
            ->assertJsonPath('data.statuses.cancelled', 3)
            ->assertJsonPath('data.statuses.processing', 1)
            ->assertJsonPath('data.statuses.sent', 1);
    }

    // ------------------------------------------------------------------ list

    public function test_it_lists_notifications_newest_first_with_filters(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $old = Notification::factory()->sms()->status(Status::Sent)->create();

        Carbon::setTestNow('2026-11-25 10:00:00');
        $match = Notification::factory()->sms()->status(Status::Sent)->create();
        Notification::factory()->email()->status(Status::Sent)->create();
        Notification::factory()->sms()->status(Status::Failed)->create();

        Carbon::setTestNow();

        $this->getJson('/api/v1/notifications?status=sent&channel=sms&from=2026-11-24&to=2026-11-25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);

        $this->getJson('/api/v1/notifications?status=sent&channel=sms')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$match->id, $old->id]);
    }

    public function test_it_paginates_with_a_cursor(): void
    {
        Notification::factory()->count(5)->create();

        $page1 = $this->getJson('/api/v1/notifications?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $page2 = $this->getJson("/api/v1/notifications?per_page=2&cursor={$cursor}")->assertOk()->assertJsonCount(2, 'data');
        $page3 = $this->getJson('/api/v1/notifications?per_page=2&cursor='.$page2->json('meta.next_cursor'))->assertOk();

        $page3->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);

        $ids = [...$page1->json('data.*.id'), ...$page2->json('data.*.id'), ...$page3->json('data.*.id')];
        $this->assertCount(5, array_unique($ids));
    }

    public function test_it_rejects_invalid_list_filters(): void
    {
        $this->getJson('/api/v1/notifications?status=done&per_page=500&from=2026-11-25&to=2026-11-01')
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['status', 'per_page', 'to']]]);
    }
}
