<?php

namespace App\Services\Delivery;

use App\Exceptions\Provider\ProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Models\Notification;
use Closure;

/**
 * Decides whether a failed delivery is retried and how long to wait.
 *
 * - Only temporary failures (timeouts, 5xx, 429) are retried; 4xx are final.
 * - Exponential backoff: base * multiplier^(attempt-1), capped at max_delay.
 * - ±jitter so thousands of notifications failing together (provider outage during a
 *   flash sale) do not retry in the same second and cause a second spike.
 * - Never earlier than the provider's Retry-After.
 */
class RetryPolicy
{
    /** @var Closure(): float returns a random float in [0, 1) */
    private Closure $random;

    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $baseDelay,
        private readonly float $multiplier,
        private readonly int $maxDelay,
        private readonly float $jitter,
        ?Closure $random = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    public static function fromConfig(): self
    {
        $config = config('notifications.retry');

        return new self(
            (int) $config['max_attempts'],
            (int) $config['base_delay'],
            (float) $config['multiplier'],
            (int) $config['max_delay'],
            (float) $config['jitter'],
        );
    }

    public function maxAttemptsFor(Notification $notification): int
    {
        return $notification->attempt_limit ?? $this->maxAttempts;
    }

    public function shouldRetry(Notification $notification, int $attempt, ProviderException $e): bool
    {
        return $e instanceof RetryableProviderException
            && $attempt < $this->maxAttemptsFor($notification);
    }

    /**
     * Seconds to wait before the attempt that follows attempt number $attempt.
     */
    public function delayFor(int $attempt, ?int $retryAfter = null): int
    {
        $exponential = min($this->maxDelay, $this->baseDelay * $this->multiplier ** max(0, $attempt - 1));
        $jitterFactor = 1 + $this->jitter * (2 * ($this->random)() - 1);
        $delay = (int) round(min($this->maxDelay, $exponential * $jitterFactor));

        return max(1, $delay, $retryAfter ?? 0);
    }
}
