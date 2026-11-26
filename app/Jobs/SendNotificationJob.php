<?php

namespace App\Jobs;

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Exceptions\Provider\ProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Jobs\Middleware\RateLimitPerChannel;
use App\Jobs\Middleware\SkipWhenCircuitOpen;
use App\Models\Notification;
use App\Providers\Notifications\NotificationProvider;
use App\Providers\Notifications\ProviderResponse;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\RetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one notification to the external provider.
 *
 * Safe to run more than once for the same notification: it first claims the row with an
 * atomic queued → processing transition, so a duplicate job (or a job for a notification
 * that was cancelled meanwhile) exits without calling the provider.
 *
 * Outcomes:
 *  - success                         → sent
 *  - temporary failure, budget left  → queued again, job released with exponential backoff
 *  - permanent failure / no budget   → failed (can be retried manually through the API)
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Unlimited queue-level attempts: rate-limit / circuit-breaker releases and retry
     * backoffs must not count as failures. Delivery attempts are tracked on the notification.
     */
    public int $tries = 0;

    /** Unexpected (non-provider) exceptions tolerated before the job is failed. */
    public int $maxExceptions = 3;

    /** Must stay below the queue's retry_after (90s). */
    public int $timeout = 30;

    public function __construct(
        public readonly string $notificationId,
        public readonly Channel $channel,
        Priority $priority,
    ) {
        $this->onQueue($priority->queue());
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SkipWhenCircuitOpen, new RateLimitPerChannel];
    }

    /**
     * Backoff for unexpected exceptions only; provider failures use RetryPolicy.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(NotificationProvider $provider, RetryPolicy $retryPolicy, CircuitBreaker $circuitBreaker): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $attempt = $notification->attempts + 1;

        $claimed = $notification->transitionIfStatus(Status::Queued, Status::Processing, [
            'attempts' => $attempt,
            'next_retry_at' => null,
        ]);

        if (! $claimed) {
            Log::info('Notification skipped: not in queued state', [
                'notification_id' => $notification->id,
                'status' => $notification->fresh()?->status->value,
            ]);

            return;
        }

        $startedAt = hrtime(true);

        try {
            $response = $provider->send($notification);
        } catch (ProviderException $e) {
            $this->handleProviderFailure($notification, $attempt, $e, $this->elapsedMs($startedAt), $retryPolicy, $circuitBreaker);

            return;
        } catch (Throwable $e) {
            // Not a provider verdict (e.g. a bug or infrastructure issue): put the notification
            // back so the queue's own retry (backoff()) can pick it up again.
            $notification->transitionIfStatus(Status::Processing, Status::Queued);

            throw $e;
        }

        $circuitBreaker->recordSuccess($this->channel);
        $this->markSent($notification, $attempt, $response, $this->elapsedMs($startedAt));
    }

    /**
     * Called by the queue once maxExceptions is exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $notification = Notification::find($this->notificationId);

        $notification?->transitionIfStatus([Status::Queued, Status::Processing], Status::Failed, [
            'last_error' => 'Delivery job failed: '.($exception?->getMessage() ?? 'unknown error'),
            'next_retry_at' => null,
        ]);
    }

    private function markSent(Notification $notification, int $attempt, ProviderResponse $response, int $durationMs): void
    {
        $notification->deliveryAttempts()->create([
            'attempt' => $attempt,
            'outcome' => AttemptOutcome::Success,
            'http_status' => $response->httpStatus,
            'duration_ms' => $durationMs,
            'provider_message_id' => $response->messageId,
        ]);

        $notification->transitionIfStatus(Status::Processing, Status::Sent, [
            'provider_message_id' => $response->messageId,
            'last_error' => null,
        ]);

        Log::info('Notification sent', [
            'notification_id' => $notification->id,
            'channel' => $this->channel->value,
            'attempt' => $attempt,
            'provider_message_id' => $response->messageId,
            'duration_ms' => $durationMs,
        ]);
    }

    private function handleProviderFailure(
        Notification $notification,
        int $attempt,
        ProviderException $e,
        int $durationMs,
        RetryPolicy $retryPolicy,
        CircuitBreaker $circuitBreaker,
    ): void {
        $retryable = $e instanceof RetryableProviderException;

        $notification->deliveryAttempts()->create([
            'attempt' => $attempt,
            'outcome' => $retryable ? AttemptOutcome::RetryableFailure : AttemptOutcome::PermanentFailure,
            'http_status' => $e->httpStatus,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
        ]);

        // Only provider-wide trouble trips the breaker; a 4xx is about this one message.
        if ($retryable) {
            $circuitBreaker->recordFailure($this->channel);
        }

        $context = [
            'notification_id' => $notification->id,
            'channel' => $this->channel->value,
            'attempt' => $attempt,
            'max_attempts' => $retryPolicy->maxAttemptsFor($notification),
            'http_status' => $e->httpStatus,
            'error' => $e->getMessage(),
        ];

        if ($retryPolicy->shouldRetry($notification, $attempt, $e)) {
            $delay = $retryPolicy->delayFor($attempt, $retryable ? $e->retryAfterSeconds : null);

            $notification->transitionIfStatus(Status::Processing, Status::Queued, [
                'last_error' => $e->getMessage(),
                'next_retry_at' => now()->addSeconds($delay),
            ]);

            $this->release($delay);

            Log::warning('Notification delivery failed, retry scheduled', $context + ['retry_in_seconds' => $delay]);

            return;
        }

        $notification->transitionIfStatus(Status::Processing, Status::Failed, [
            'last_error' => $e->getMessage(),
        ]);

        Log::error('Notification delivery failed permanently', $context + ['retryable' => $retryable]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
