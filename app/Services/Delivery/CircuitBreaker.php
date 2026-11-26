<?php

namespace App\Services\Delivery;

use App\Enums\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Per-channel circuit breaker shared by all workers (state lives in Redis).
 *
 * closed    → every temporary provider failure increments a counter (expires after `window`);
 * open      → once the counter reaches `failure_threshold`, the channel pauses for `cooldown`
 *             seconds: jobs are released untouched instead of hammering a provider that is down;
 * half-open → after the cooldown traffic resumes; one success resets the counter, one more
 *             failure re-opens the circuit immediately (the counter is still above threshold).
 */
class CircuitBreaker
{
    private const PREFIX = 'notifications:circuit:';

    public function __construct(
        private readonly int $failureThreshold,
        private readonly int $window,
        private readonly int $cooldown,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('notifications.circuit_breaker');

        return new self((int) $config['failure_threshold'], (int) $config['window'], (int) $config['cooldown']);
    }

    public function isOpen(Channel $channel): bool
    {
        return (bool) Redis::exists($this->openKey($channel));
    }

    /**
     * Seconds until the circuit closes again (0 when closed).
     */
    public function retryAfter(Channel $channel): int
    {
        return max(0, (int) Redis::ttl($this->openKey($channel)));
    }

    public function recordSuccess(Channel $channel): void
    {
        Redis::del($this->failuresKey($channel));
    }

    public function recordFailure(Channel $channel): void
    {
        $failures = (int) Redis::incr($this->failuresKey($channel));

        if ($failures === 1) {
            Redis::expire($this->failuresKey($channel), $this->window);
        }

        if ($failures >= $this->failureThreshold && ! $this->isOpen($channel)) {
            Redis::setex($this->openKey($channel), $this->cooldown, (string) now()->timestamp);

            Log::warning('Circuit opened: provider failing, pausing channel', [
                'channel' => $channel->value,
                'consecutive_failures' => $failures,
                'cooldown_seconds' => $this->cooldown,
            ]);
        }
    }

    /**
     * @return array<string, array{state: string, consecutive_failures: int, retry_after: int}>
     */
    public function snapshot(): array
    {
        $state = [];

        foreach (Channel::cases() as $channel) {
            $open = $this->isOpen($channel);
            $state[$channel->value] = [
                'state' => $open ? 'open' : 'closed',
                'consecutive_failures' => (int) Redis::get($this->failuresKey($channel)),
                'retry_after' => $open ? $this->retryAfter($channel) : 0,
            ];
        }

        return $state;
    }

    public function reset(Channel $channel): void
    {
        Redis::del($this->failuresKey($channel), $this->openKey($channel));
    }

    private function failuresKey(Channel $channel): string
    {
        return self::PREFIX.$channel->value.':failures';
    }

    private function openKey(Channel $channel): string
    {
        return self::PREFIX.$channel->value.':open';
    }
}
