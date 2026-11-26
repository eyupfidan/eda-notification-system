<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Caps provider calls at N per second per channel across *all* workers, using a
 * Redis-backed fixed one-second window. A job over the limit is not failed: it is
 * released back to its queue for a second, keeping its "queued" status.
 */
class RateLimitPerChannel
{
    public const KEY_PREFIX = 'notifications:rate-limit:';

    public function handle(SendNotificationJob $job, Closure $next): void
    {
        Redis::throttle(self::KEY_PREFIX.$job->channel->value)
            ->allow((int) config('notifications.rate_limit.per_second'))
            ->every(1)
            ->block(0)
            ->then(
                fn () => $next($job),
                fn () => $job->release(1),
            );
    }
}
