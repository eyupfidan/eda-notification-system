<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Jobs\Middleware\SkipWhenCircuitOpen;
use App\Jobs\SendNotificationJob;
use App\Services\Delivery\CircuitBreaker;
use Illuminate\Support\Str;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.circuit_breaker' => ['failure_threshold' => 3, 'window' => 60, 'cooldown' => 30]]);
        $this->app->forgetInstance(CircuitBreaker::class);
        $this->breaker = app(CircuitBreaker::class);

        foreach (Channel::cases() as $channel) {
            $this->breaker->reset($channel);
        }
    }

    public function test_it_opens_after_consecutive_failures_and_only_for_that_channel(): void
    {
        $this->breaker->recordFailure(Channel::Sms);
        $this->breaker->recordFailure(Channel::Sms);
        $this->assertFalse($this->breaker->isOpen(Channel::Sms));

        $this->breaker->recordFailure(Channel::Sms);

        $this->assertTrue($this->breaker->isOpen(Channel::Sms));
        $this->assertFalse($this->breaker->isOpen(Channel::Email));
        $this->assertEqualsWithDelta(30, $this->breaker->retryAfter(Channel::Sms), 1);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $this->breaker->recordFailure(Channel::Sms);
        $this->breaker->recordFailure(Channel::Sms);
        $this->breaker->recordSuccess(Channel::Sms);
        $this->breaker->recordFailure(Channel::Sms);

        $this->assertFalse($this->breaker->isOpen(Channel::Sms));
        $this->assertSame(1, $this->breaker->snapshot()['sms']['consecutive_failures']);
    }

    public function test_snapshot_reports_state_per_channel(): void
    {
        foreach (range(1, 3) as $_) {
            $this->breaker->recordFailure(Channel::Push);
        }

        $snapshot = $this->breaker->snapshot();

        $this->assertSame('open', $snapshot['push']['state']);
        $this->assertSame('closed', $snapshot['sms']['state']);
        $this->assertGreaterThan(0, $snapshot['push']['retry_after']);
    }

    public function test_jobs_are_released_untouched_while_the_circuit_is_open(): void
    {
        foreach (range(1, 3) as $_) {
            $this->breaker->recordFailure(Channel::Sms);
        }

        $job = new class((string) Str::uuid7(), Channel::Sms, Priority::High) extends SendNotificationJob
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = $delay;
            }
        };

        $handled = false;
        (new SkipWhenCircuitOpen)->handle($job, function () use (&$handled) {
            $handled = true;
        });

        $this->assertFalse($handled);
        $this->assertEqualsWithDelta(30, $job->releasedFor, 1);
    }
}
