<?php

namespace Tests\Feature;

use App\Enums\AttemptOutcome;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Providers\Notifications\NotificationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/notify';

    private function queued(array $attributes = []): Notification
    {
        return Notification::factory()->status(Status::Queued)->create([
            'recipient' => '+905551234567',
            'content' => 'Your code is 1234',
            ...$attributes,
        ]);
    }

    private function runJob(Notification $notification): void
    {
        (new SendNotificationJob($notification->id, $notification->channel, $notification->priority))
            ->handle(app(NotificationProvider::class));
    }

    private function fakeProvider(mixed $response): void
    {
        Http::preventStrayRequests();
        Http::fake([self::PROVIDER_URL => $response]);
    }

    public function test_successful_delivery_marks_the_notification_sent(): void
    {
        $this->fakeProvider(Http::response([
            'messageId' => 'msg-123', 'status' => 'accepted', 'timestamp' => '2026-11-26T12:00:00Z',
        ], 202));
        $notification = $this->queued();

        $this->runJob($notification);

        $fresh = $notification->fresh();
        $this->assertSame(Status::Sent, $fresh->status);
        $this->assertSame('msg-123', $fresh->provider_message_id);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNotNull($fresh->sent_at);

        $attempt = $fresh->deliveryAttempts->sole();
        $this->assertSame(AttemptOutcome::Success, $attempt->outcome);
        $this->assertSame(202, $attempt->http_status);
        $this->assertNotNull($attempt->duration_ms);
    }

    public function test_request_matches_the_provider_contract(): void
    {
        $this->fakeProvider(Http::response(['messageId' => 'm', 'status' => 'accepted'], 202));
        $notification = $this->queued();

        $this->runJob($notification);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::PROVIDER_URL
            && $request->data() === ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Your code is 1234']
            && $request->header('Idempotency-Key') === [$notification->id]);
    }

    /**
     * @return array<string, array{int, array<string, mixed>|string, array<string, string>, AttemptOutcome}>
     */
    public static function failures(): array
    {
        return [
            '500 server error' => [500, 'oops', [], AttemptOutcome::RetryableFailure],
            '429 too many requests' => [429, '', ['Retry-After' => '3'], AttemptOutcome::RetryableFailure],
            '400 bad request' => [400, ['error' => 'invalid number'], [], AttemptOutcome::PermanentFailure],
            '202 without messageId' => [202, ['status' => 'accepted'], [], AttemptOutcome::PermanentFailure],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body
     * @param  array<string, string>  $headers
     */
    #[DataProvider('failures')]
    public function test_provider_failures_are_recorded_and_classified(int $httpStatus, array|string $body, array $headers, AttemptOutcome $outcome): void
    {
        $this->fakeProvider(Http::response($body, $httpStatus, $headers));
        $notification = $this->queued();

        $this->runJob($notification);

        $fresh = $notification->fresh();
        $this->assertSame(Status::Failed, $fresh->status);
        $this->assertNotNull($fresh->last_error);

        $attempt = $fresh->deliveryAttempts->sole();
        $this->assertSame($outcome, $attempt->outcome);
        $this->assertSame($httpStatus, $attempt->http_status);
    }

    public function test_timeouts_are_retryable_failures(): void
    {
        $this->fakeProvider(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $notification = $this->queued();

        $this->runJob($notification);

        $attempt = $notification->fresh()->deliveryAttempts->sole();
        $this->assertSame(AttemptOutcome::RetryableFailure, $attempt->outcome);
        $this->assertNull($attempt->http_status);
        $this->assertStringContainsString('timed out', $attempt->error);
    }

    public function test_cancelled_notifications_are_never_sent(): void
    {
        $this->fakeProvider(Http::response(['messageId' => 'm'], 202));
        $notification = Notification::factory()->status(Status::Cancelled)->create();

        $this->runJob($notification);

        Http::assertNothingSent();
        $this->assertSame(Status::Cancelled, $notification->fresh()->status);
    }

    public function test_a_duplicate_job_does_not_send_twice(): void
    {
        $this->fakeProvider(Http::response(['messageId' => 'm'], 202));
        $notification = $this->queued();

        $this->runJob($notification);
        $this->runJob($notification); // e.g. the same job delivered twice by the queue

        Http::assertSentCount(1);
        $this->assertSame(1, $notification->fresh()->attempts);
    }

    public function test_missing_provider_url_fails_with_a_clear_message(): void
    {
        config(['notifications.provider.url' => null]);
        Http::preventStrayRequests();
        $notification = $this->queued();

        $this->runJob($notification);

        $fresh = $notification->fresh();
        $this->assertSame(Status::Failed, $fresh->status);
        $this->assertStringContainsString('NOTIFICATION_PROVIDER_URL', $fresh->last_error);
    }

    public function test_unexpected_errors_put_the_notification_back_in_the_queue(): void
    {
        $this->app->instance(NotificationProvider::class, new class implements NotificationProvider
        {
            public function send(Notification $notification): never
            {
                throw new RuntimeException('Something unexpected');
            }
        });
        $notification = $this->queued();

        try {
            $this->runJob($notification);
            $this->fail('The exception should be rethrown so the queue retries the job.');
        } catch (RuntimeException $e) {
            $this->assertSame('Something unexpected', $e->getMessage());
        }

        $this->assertSame(Status::Queued, $notification->fresh()->status);
    }

    public function test_dispatching_runs_through_the_queue_pipeline(): void
    {
        $this->fakeProvider(Http::response(['messageId' => 'msg-via-queue'], 202));
        $notification = $this->queued();

        // sync queue in tests: exercises job middleware (rate limiter) + handle()
        dispatch(new SendNotificationJob($notification->id, $notification->channel, $notification->priority));

        $this->assertSame(Status::Sent, $notification->fresh()->status);
    }
}
