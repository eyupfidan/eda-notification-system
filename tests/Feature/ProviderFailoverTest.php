<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Providers\Notifications\FailoverProvider;
use App\Providers\Notifications\NotificationProvider;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\ProviderFailover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProviderFailoverTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY = 'https://primary.test/notify';

    private const FALLBACK = 'http://fallback.test/notify';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.provider.url' => self::PRIMARY,
            'notifications.provider.fallback_url' => self::FALLBACK,
            'notifications.provider.failover_cooldown' => 300,
        ]);
        app(CircuitBreaker::class)->reset(Channel::Sms);
        Http::preventStrayRequests();
    }

    private function fake(mixed $primary): void
    {
        Http::fake([
            self::PRIMARY => $primary,
            self::FALLBACK => Http::response(['messageId' => 'mock-1', 'status' => 'accepted', 'timestamp' => now()->toIso8601String()], 202),
        ]);
    }

    private function deliver(): Notification
    {
        $notification = Notification::factory()->status(Status::Queued)->create([
            'channel' => Channel::Sms, 'recipient' => '+905551234567', 'content' => 'Hello',
        ]);

        $job = new class($notification->id, $notification->channel, $notification->priority) extends SendNotificationJob
        {
            public function release($delay = 0): void {}
        };
        app()->call([$job, 'handle']);

        return $notification->fresh();
    }

    private function sentTo(string $url): int
    {
        return Http::recorded(fn (Request $r) => $r->url() === $url)->count();
    }

    public function test_the_failover_provider_is_used_only_when_a_fallback_is_configured(): void
    {
        $this->assertInstanceOf(FailoverProvider::class, app(NotificationProvider::class));

        config(['notifications.provider.fallback_url' => null]);
        $this->assertNotInstanceOf(FailoverProvider::class, app(NotificationProvider::class));
    }

    public function test_messages_go_to_the_primary_while_it_works(): void
    {
        $this->fake(Http::response(['messageId' => 'wh-1'], 202));

        $this->assertSame('wh-1', $this->deliver()->provider_message_id);
        $this->assertSame(0, $this->sentTo(self::FALLBACK));
        $this->assertFalse(app(ProviderFailover::class)->active());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unavailableStatuses(): array
    {
        return ['quota used up' => [429], 'url expired' => [404], 'url gone' => [410]];
    }

    #[DataProvider('unavailableStatuses')]
    public function test_the_same_message_is_delivered_by_the_fallback_when_the_primary_is_unavailable(int $status): void
    {
        $this->fake(Http::response(['error' => 'unavailable'], $status));

        $notification = $this->deliver();

        $this->assertSame(Status::Sent, $notification->status);
        $this->assertSame('mock-1', $notification->provider_message_id);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame(1, $this->sentTo(self::PRIMARY));
        $this->assertSame(1, $this->sentTo(self::FALLBACK));
        $this->assertTrue(app(ProviderFailover::class)->active());
    }

    public function test_the_primary_is_skipped_during_the_cooldown_and_tried_again_afterwards(): void
    {
        $quotaUsedUp = true;
        $this->fake(function () use (&$quotaUsedUp) {
            return $quotaUsedUp ? Http::response([], 429) : Http::response(['messageId' => 'wh-2'], 202);
        });

        $this->deliver();
        $this->deliver();

        $this->assertSame(1, $this->sentTo(self::PRIMARY), 'second message went straight to the fallback');
        $this->assertSame(2, $this->sentTo(self::FALLBACK));

        app(ProviderFailover::class)->reset(); // cooldown over
        $quotaUsedUp = false;

        $this->assertSame('wh-2', $this->deliver()->provider_message_id);
        $this->assertFalse(app(ProviderFailover::class)->active());
    }

    public function test_temporary_errors_stay_with_the_primary_and_its_retry_policy(): void
    {
        $this->fake(Http::response([], 503));

        $notification = $this->deliver();

        $this->assertSame(Status::Queued, $notification->status);
        $this->assertNotNull($notification->next_retry_at);
        $this->assertSame(0, $this->sentTo(self::FALLBACK));
        $this->assertFalse(app(ProviderFailover::class)->active());
    }

    public function test_a_fallback_error_is_classified_like_any_provider_error(): void
    {
        Http::fake([
            self::PRIMARY => Http::response([], 429),
            self::FALLBACK => Http::response([], 503),
        ]);

        $notification = $this->deliver();

        $this->assertSame(Status::Queued, $notification->status);
        $this->assertStringContainsString('503', $notification->last_error);
    }

    public function test_metrics_show_which_provider_is_active(): void
    {
        $this->getJson('/metrics')
            ->assertJsonPath('provider.fallback_configured', true)
            ->assertJsonPath('provider.active', 'primary');

        app(ProviderFailover::class)->activate('Provider responded with HTTP 429');

        $this->getJson('/metrics')
            ->assertJsonPath('provider.active', 'fallback')
            ->assertJsonPath('provider.reason', 'Provider responded with HTTP 429')
            ->assertJsonPath('provider.retry_primary_in', fn (int $s) => $s > 0 && $s <= 300);

        $this->assertStringContainsString(
            'notifications_provider_failover_active 1',
            $this->get('/metrics?format=prometheus')->getContent(),
        );
    }
}
