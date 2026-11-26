<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Jobs\Middleware\RateLimitPerChannel;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitPerChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.rate_limit.per_second' => 3]);

        foreach (Channel::cases() as $channel) {
            Redis::del(RateLimitPerChannel::KEY_PREFIX.$channel->value);
        }
    }

    /**
     * A job that records releases instead of talking to a real queue.
     */
    private function job(Channel $channel): SendNotificationJob
    {
        return new class((string) Str::uuid7(), $channel, Priority::Normal) extends SendNotificationJob
        {
            public ?int $releasedFor = null;

            public function release($delay = 0): void
            {
                $this->releasedFor = $delay;
            }
        };
    }

    /**
     * @return array{int, list<SendNotificationJob>}
     */
    private function runThroughLimiter(Channel $channel, int $jobs): array
    {
        $handled = 0;
        $all = [];

        for ($i = 0; $i < $jobs; $i++) {
            $job = $this->job($channel);
            (new RateLimitPerChannel)->handle($job, function () use (&$handled) {
                $handled++;
            });
            $all[] = $job;
        }

        return [$handled, $all];
    }

    public function test_jobs_over_the_per_second_limit_are_released_not_failed(): void
    {
        [$handled, $jobs] = $this->runThroughLimiter(Channel::Sms, 5);

        $this->assertSame(3, $handled);
        $this->assertSame([null, null, null, 1, 1], array_map(fn ($job) => $job->releasedFor, $jobs));
    }

    public function test_each_channel_has_its_own_budget(): void
    {
        [$sms] = $this->runThroughLimiter(Channel::Sms, 4);
        [$email] = $this->runThroughLimiter(Channel::Email, 4);
        [$push] = $this->runThroughLimiter(Channel::Push, 2);

        $this->assertSame([3, 3, 2], [$sms, $email, $push]);
    }

    public function test_the_budget_resets_after_one_second(): void
    {
        $this->runThroughLimiter(Channel::Sms, 3);
        [$blocked] = $this->runThroughLimiter(Channel::Sms, 1);

        sleep(1);
        // Wait for the next window boundary to avoid flakiness.
        usleep(100_000);

        [$afterWindow] = $this->runThroughLimiter(Channel::Sms, 1);

        $this->assertSame(0, $blocked);
        $this->assertSame(1, $afterWindow);
    }
}
