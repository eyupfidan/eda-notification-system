<?php

namespace Tests\Feature;

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Providers\Notifications\NotificationProvider;
use App\Services\Delivery\CircuitBreaker;
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

    protected function setUp(): void
    {
        parent::setUp();

        app(CircuitBreaker::class)->reset(Channel::Sms);
    }

    /**
     * Runs the job's handle() and returns the delay it released itself with (null if not released).
     */
    private function runJob(Notification $notification): ?int
    {
        $job = new class($notification->id, $notification->channel, $notification->priority) extends SendNotificationJob
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = $delay;
            }
        };

        app()->call([$job, 'handle']);

        return $job->releasedFor;
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
     * @return array<string, array{int, string}>
     */
    public static function temporaryFailures(): array
    {
        return [
            '500 internal server error' => [500, 'oops'],
            '503 service unavailable' => [503, ''],
            '429 too many requests' => [429, ''],
        ];
    }

    #[DataProvider('temporaryFailures')]
    public function test_temporary_failures_are_retried_with_backoff(int $httpStatus, string $body): void
    {
        $this->fakeProvider(Http::response($body, $httpStatus));
        $notification = $this->queued();

        $released = $this->runJob($notification);

        $fresh = $notification->fresh();
        $this->assertSame(Status::Queued, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertStringContainsString((string) $httpStatus, $fresh->last_error);

        // First retry: base delay 10s ± 20% jitter
        $this->assertNotNull($released);
        $this->assertGreaterThanOrEqual(8, $released);
        $this->assertLessThanOrEqual(12, $released);
        $this->assertEqualsWithDelta(now()->addSeconds($released)->timestamp, $fresh->next_retry_at->timestamp, 2);

        $attempt = $fresh->deliveryAttempts->sole();
        $this->assertSame(AttemptOutcome::RetryableFailure, $attempt->outcome);
        $this->assertSame($httpStatus, $attempt->http_status);
    }

    public function test_retry_after_header_is_respected(): void
    {
        $this->fakeProvider(Http::response('', 429, ['Retry-After' => '120']));

        $released = $this->runJob($this->queued());

        $this->assertSame(120, $released);
    }

    public function test_timeouts_are_retried(): void
    {
        $this->fakeProvider(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $notification = $this->queued();

        $released = $this->runJob($notification);

        $this->assertNotNull($released);
        $this->assertSame(Status::Queued, $notification->fresh()->status);
        $attempt = $notification->fresh()->deliveryAttempts->sole();
        $this->assertSame(AttemptOutcome::RetryableFailure, $attempt->outcome);
        $this->assertNull($attempt->http_status);
        $this->assertStringContainsString('timed out', $attempt->error);
    }

    public function test_it_gives_up_after_the_maximum_number_of_attempts(): void
    {
        config(['notifications.retry.max_attempts' => 3]);
        $this->fakeProvider(Http::response('down', 503));
        $notification = $this->queued();

        $delays = [];
        for ($i = 0; $i < 3; $i++) {
            $delays[] = $this->runJob($notification);
        }

        $fresh = $notification->fresh();
        $this->assertSame(Status::Failed, $fresh->status);
        $this->assertSame(3, $fresh->attempts);
        $this->assertNull($fresh->next_retry_at);
        $this->assertNull($delays[2], 'The last attempt must not schedule another retry.');
        $this->assertGreaterThan($delays[0], $delays[1], 'Backoff must grow between attempts.');
        $this->assertSame([1, 2, 3], $fresh->deliveryAttempts->pluck('attempt')->all());
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public static function permanentFailures(): array
    {
        return [
            '400 bad request' => [400, ['error' => 'invalid number']],
            '404 not found' => [404, []],
            '202 without messageId' => [202, ['status' => 'accepted']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('permanentFailures')]
    public function test_permanent_failures_are_not_retried(int $httpStatus, array $body): void
    {
        $this->fakeProvider(Http::response($body, $httpStatus));
        $notification = $this->queued();

        $released = $this->runJob($notification);

        $fresh = $notification->fresh();
        $this->assertNull($released);
        $this->assertSame(Status::Failed, $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertSame(AttemptOutcome::PermanentFailure, $fresh->deliveryAttempts->sole()->outcome);
    }

    public function test_temporary_failures_feed_the_circuit_breaker_and_success_resets_it(): void
    {
        $breaker = app(CircuitBreaker::class);

        $this->fakeProvider(Http::sequence()
            ->push('', 503)
            ->push('', 503)
            ->push(['messageId' => 'm'], 202));

        $this->runJob($this->queued());
        $this->runJob($this->queued());
        $this->assertSame(2, $breaker->snapshot()['sms']['consecutive_failures']);

        $this->runJob($this->queued());
        $this->assertSame(0, $breaker->snapshot()['sms']['consecutive_failures']);
    }

    public function test_permanent_failures_do_not_trip_the_circuit_breaker(): void
    {
        $this->fakeProvider(Http::response([], 400));

        $this->runJob($this->queued());

        $this->assertSame(0, app(CircuitBreaker::class)->snapshot()['sms']['consecutive_failures']);
    }

    public function test_failed_job_marks_the_notification_failed(): void
    {
        $notification = $this->queued();

        (new SendNotificationJob($notification->id, $notification->channel, $notification->priority))
            ->failed(new RuntimeException('Database went away'));

        $fresh = $notification->fresh();
        $this->assertSame(Status::Failed, $fresh->status);
        $this->assertStringContainsString('Database went away', $fresh->last_error);
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
