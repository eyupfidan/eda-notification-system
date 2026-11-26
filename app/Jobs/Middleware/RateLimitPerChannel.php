<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Delivery\SlidingWindowRateLimiter;
use Closure;

/**
 * Caps provider calls at N per second per channel across *all* workers, using a
 * Redis sliding window (never more than N in any one-second span). A job over the
 * limit is not failed: it is released back to its queue until a slot frees up,
 * keeping its "queued" status and its delivery attempts.
 */
class RateLimitPerChannel
{
    public const KEY_PREFIX = 'notifications:rate-limit:';

    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $waitMs = app(SlidingWindowRateLimiter::class)->attempt(
            self::KEY_PREFIX.$job->channel->value,
            (int) config('notifications.rate_limit.per_second'),
        );

        if ($waitMs > 0) {
            // Queue delays have one-second granularity.
            $job->release((int) max(1, ceil($waitMs / 1000)));

            return;
        }

        $next($job);
    }
}
