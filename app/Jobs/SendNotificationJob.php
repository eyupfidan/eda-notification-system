<?php

namespace App\Jobs;

use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Exceptions\Provider\PermanentProviderException;
use App\Exceptions\Provider\ProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Jobs\Middleware\RateLimitPerChannel;
use App\Models\Notification;
use App\Providers\Notifications\NotificationProvider;
use App\Providers\Notifications\ProviderResponse;
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
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Unlimited queue-level attempts: rate-limit releases must not count as failures.
     * Delivery attempts are tracked on the notification itself.
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
        return [new RateLimitPerChannel];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(NotificationProvider $provider): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $attempt = $notification->attempts + 1;

        if (! $notification->transitionIfStatus(Status::Queued, Status::Processing, ['attempts' => $attempt])) {
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
            $this->handleProviderFailure($notification, $attempt, $e, $this->elapsedMs($startedAt));

            return;
        } catch (Throwable $e) {
            // Not a provider verdict (e.g. a bug or infrastructure issue): put the notification
            // back so the queue's own retry can pick it up again.
            $notification->transitionIfStatus(Status::Processing, Status::Queued);

            throw $e;
        }

        $this->markSent($notification, $attempt, $response, $this->elapsedMs($startedAt));
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
            'channel' => $notification->channel->value,
            'attempt' => $attempt,
            'provider_message_id' => $response->messageId,
            'duration_ms' => $durationMs,
        ]);
    }

    private function handleProviderFailure(Notification $notification, int $attempt, ProviderException $e, int $durationMs): void
    {
        $notification->deliveryAttempts()->create([
            'attempt' => $attempt,
            'outcome' => $e instanceof RetryableProviderException ? AttemptOutcome::RetryableFailure : AttemptOutcome::PermanentFailure,
            'http_status' => $e->httpStatus,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
        ]);

        // Retry strategy for retryable failures is added in the next step;
        // for now every provider failure is final.
        $notification->transitionIfStatus(Status::Processing, Status::Failed, [
            'last_error' => $e->getMessage(),
        ]);

        Log::warning('Notification delivery failed', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'attempt' => $attempt,
            'http_status' => $e->httpStatus,
            'error' => $e->getMessage(),
            'permanent' => $e instanceof PermanentProviderException,
        ]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
