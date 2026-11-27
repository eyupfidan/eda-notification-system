<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Shared "primary provider is unavailable" switch (state lives in Redis, so every worker agrees).
 *
 * When the primary provider stops taking messages for a while (webhook.site: request quota
 * reached → 429, URL expired or deleted → 404/410) `FailoverProvider` activates this switch and
 * sends to the fallback provider for `cooldown` seconds; afterwards the primary is tried again.
 */
class ProviderFailover
{
    private const KEY = 'notifications:provider:failover';

    public function __construct(
        private readonly bool $configured,
        private readonly int $cooldown,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            filled(config('notifications.provider.fallback_url')),
            (int) config('notifications.provider.failover_cooldown'),
        );
    }

    public function active(): bool
    {
        return $this->configured && (bool) Redis::exists(self::KEY);
    }

    public function activate(string $reason): void
    {
        Redis::setex(self::KEY, max(1, $this->cooldown), $reason);

        Log::warning('Primary provider unavailable, using the fallback provider', [
            'reason' => $reason,
            'retry_primary_in' => $this->cooldown,
        ]);
    }

    public function reset(): void
    {
        Redis::del(self::KEY);
    }

    /**
     * @return array{fallback_configured: bool, active: string, retry_primary_in: int, reason: ?string}
     */
    public function snapshot(): array
    {
        $active = $this->active();

        return [
            'fallback_configured' => $this->configured,
            'active' => $active ? 'fallback' : 'primary',
            'retry_primary_in' => $active ? max(0, (int) Redis::ttl(self::KEY)) : 0,
            'reason' => $active ? (Redis::get(self::KEY) ?: null) : null,
        ];
    }
}
