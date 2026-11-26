<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Delivery\CircuitBreaker;
use Closure;

/**
 * While a channel's circuit is open the job is released until the cooldown ends,
 * without claiming the notification or spending one of its delivery attempts.
 */
class SkipWhenCircuitOpen
{
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $breaker = app(CircuitBreaker::class);

        if ($breaker->isOpen($job->channel)) {
            $job->release(max(1, $breaker->retryAfter($job->channel)));

            return;
        }

        $next($job);
    }
}
