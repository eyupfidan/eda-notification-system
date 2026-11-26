<?php

namespace Tests\Unit;

use App\Exceptions\Provider\PermanentProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Models\Notification;
use App\Services\Delivery\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    private function policy(float $random = 0.5, int $maxAttempts = 5): RetryPolicy
    {
        // random = 0.5 → jitter factor exactly 1.0
        return new RetryPolicy($maxAttempts, baseDelay: 10, multiplier: 3, maxDelay: 900, jitter: 0.2, random: fn () => $random);
    }

    public function test_delay_grows_exponentially_and_is_capped(): void
    {
        $policy = $this->policy();

        $this->assertSame(
            [10, 30, 90, 270, 810, 900, 900],
            array_map(fn (int $attempt) => $policy->delayFor($attempt), range(1, 7)),
        );
    }

    public function test_jitter_spreads_delays_by_twenty_percent(): void
    {
        $this->assertSame(8, $this->policy(random: 0.0)->delayFor(1));
        $this->assertSame(12, $this->policy(random: 0.999999)->delayFor(1));
        $this->assertSame(72, $this->policy(random: 0.0)->delayFor(3));
        $this->assertSame(108, $this->policy(random: 0.999999)->delayFor(3));
    }

    public function test_jitter_never_exceeds_the_cap(): void
    {
        $this->assertSame(900, $this->policy(random: 0.999999)->delayFor(10));
    }

    public function test_provider_retry_after_wins_when_longer(): void
    {
        $policy = $this->policy();

        $this->assertSame(120, $policy->delayFor(1, retryAfter: 120));
        $this->assertSame(30, $policy->delayFor(2, retryAfter: 5));
    }

    public function test_only_temporary_failures_with_budget_left_are_retried(): void
    {
        $policy = $this->policy(maxAttempts: 3);
        $notification = new Notification;
        $temporary = new RetryableProviderException('503', 503);
        $permanent = new PermanentProviderException('400', 400);

        $this->assertTrue($policy->shouldRetry($notification, 1, $temporary));
        $this->assertTrue($policy->shouldRetry($notification, 2, $temporary));
        $this->assertFalse($policy->shouldRetry($notification, 3, $temporary));
        $this->assertFalse($policy->shouldRetry($notification, 1, $permanent));
    }

    public function test_per_notification_attempt_limit_overrides_the_default(): void
    {
        $policy = $this->policy(maxAttempts: 3);
        $notification = (new Notification)->forceFill(['attempt_limit' => 6]);

        $this->assertSame(6, $policy->maxAttemptsFor($notification));
        $this->assertTrue($policy->shouldRetry($notification, 5, new RetryableProviderException('503', 503)));
    }
}
