<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\Status;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Observability\Heartbeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(Heartbeats::class)->forget();
        foreach (Channel::cases() as $channel) {
            app(CircuitBreaker::class)->reset($channel);
        }
    }

    // ---------------------------------------------------------------- metrics

    private function seedTraffic(): void
    {
        // 3 SMS sent (latency 1s, 2s, 3s after acceptance), 1 SMS failed, 1 email sent.
        foreach ([1000, 2000, 3000] as $i => $ms) {
            $n = Notification::factory()->sms()->status(Status::Sent)->create([
                'created_at' => now()->subSeconds(30),
                'sent_at' => now()->subSeconds(30)->addMilliseconds($ms),
            ]);
            NotificationAttempt::factory()->for($n)->create(['duration_ms' => 100 * ($i + 1)]);
        }

        $failed = Notification::factory()->sms()->status(Status::Failed)->create(['failed_at' => now()->subSeconds(10)]);
        NotificationAttempt::factory()->for($failed)->retryableFailure()->create(['attempt' => 1, 'duration_ms' => 500]);

        $email = Notification::factory()->email()->status(Status::Sent)->create(['sent_at' => now()]);
        NotificationAttempt::factory()->for($email)->create(['duration_ms' => 50]);

        // Outside the 5m window: must be ignored.
        $old = Notification::factory()->sms()->status(Status::Sent)->create(['sent_at' => now()->subHour()]);
        NotificationAttempt::factory()->for($old)->create(['created_at' => now()->subHour()]);

        Notification::factory()->count(2)->status(Status::Queued)->create();
    }

    public function test_metrics_report_rates_latency_and_state(): void
    {
        $this->seedTraffic();
        app(Heartbeats::class)->recordWorker('worker-1');

        $response = $this->getJson('/metrics')->assertOk();

        $response
            ->assertJsonPath('window', '5m')
            ->assertJsonStructure(['queues' => ['high' => ['ready', 'delayed', 'reserved', 'total'], 'normal', 'low']])
            ->assertJsonPath('notifications_by_status.sent', 5)
            ->assertJsonPath('notifications_by_status.failed', 1)
            ->assertJsonPath('notifications_by_status.queued', 2)
            // final outcomes in the window
            ->assertJsonPath('delivery.sms.sent', 3)
            ->assertJsonPath('delivery.sms.failed', 1)
            ->assertJsonPath('delivery.sms.success_rate', 0.75)
            ->assertJsonPath('delivery.sms.latency_ms.p50', 2000)
            ->assertJsonPath('delivery.all.sent', 4)
            ->assertJsonPath('delivery.push.success_rate', null)
            // provider calls in the window
            ->assertJsonPath('provider_attempts.sms.total', 4)
            ->assertJsonPath('provider_attempts.sms.retryable_failure', 1)
            ->assertJsonPath('provider_attempts.sms.success_rate', 0.75)
            ->assertJsonPath('provider_attempts.sms.latency_ms.p50', 250)
            ->assertJsonPath('provider_attempts.all.total', 5)
            ->assertJsonPath('circuit_breakers.sms.state', 'closed')
            ->assertJsonPath('workers.active', 1)
            ->assertJsonPath('rate_limit_per_second_per_channel', 100);
    }

    public function test_metrics_window_can_be_widened(): void
    {
        $this->seedTraffic();

        $this->getJson('/metrics?window=24h')
            ->assertOk()
            ->assertJsonPath('delivery.sms.sent', 4);
    }

    public function test_metrics_reject_unknown_windows(): void
    {
        $this->getJson('/metrics?window=7d')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_metrics_are_available_in_prometheus_format(): void
    {
        $this->seedTraffic();
        foreach (range(1, 10) as $_) {
            app(CircuitBreaker::class)->recordFailure(Channel::Push);
        }

        $body = $this->get('/metrics?format=prometheus')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString('# TYPE notifications_queue_depth gauge', $body);
        $this->assertStringContainsString('notifications_status{status="queued"} 2', $body);
        $this->assertStringContainsString('notifications_delivered{channel="sms",outcome="sent",window="5m"} 3', $body);
        $this->assertStringContainsString('notifications_provider_latency_ms{channel="sms",quantile="0.5",window="5m"} 250', $body);
        $this->assertStringContainsString('notifications_circuit_open{channel="push"} 1', $body);
    }

    // ----------------------------------------------------------------- health

    public function test_health_is_ok_when_everything_runs(): void
    {
        app(Heartbeats::class)->recordWorker('worker-1');
        app(Heartbeats::class)->recordScheduler();

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.queue.status', 'ok')
            ->assertJsonPath('checks.workers.active', 1)
            ->assertJsonPath('checks.scheduler.status', 'ok');
    }

    public function test_health_is_degraded_without_workers_or_scheduler(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.workers.status', 'degraded')
            ->assertJsonPath('checks.scheduler.status', 'degraded');
    }

    public function test_stale_worker_heartbeats_are_not_counted(): void
    {
        $this->travel(-(Heartbeats::WORKER_TTL + 5))->seconds();
        app(Heartbeats::class)->recordWorker('crashed-worker');
        $this->travelBack();

        $this->assertSame(0, app(Heartbeats::class)->activeWorkers());
    }

    // ------------------------------------------------------- html / json switch

    public function test_browsers_get_the_metrics_dashboard(): void
    {
        $this->get('/metrics?window=1h', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertViewIs('observability.metrics')
            ->assertViewHas('metrics', fn (array $m) => $m['window'] === '1h')
            ->assertSee('Queue depth')
            ->assertSee('Kuyruk derinliği', false);
    }

    public function test_type_json_returns_the_metrics_json_without_an_accept_header(): void
    {
        $this->seedTraffic();

        $this->get('/metrics?type=json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('window', '5m')
            ->assertJsonPath('notifications_by_status.sent', 5);
    }

    public function test_type_html_wins_over_an_accept_json_header(): void
    {
        $this->getJson('/metrics?type=html')->assertOk()->assertViewIs('observability.metrics');
        $this->getJson('/health?type=html')->assertViewIs('observability.health');
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->get('/metrics?type=xml')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->get('/health?type=xml')->assertStatus(422);
    }

    public function test_browsers_get_the_health_dashboard(): void
    {
        $this->get('/health', ['Accept' => 'text/html'])
            ->assertOk()
            ->assertViewIs('observability.health')
            ->assertViewHas('health', fn (array $h) => $h['status'] === 'degraded')
            ->assertSee('Service health')
            ->assertSee('Servis sağlığı', false);
    }

    public function test_type_json_returns_the_health_json_without_an_accept_header(): void
    {
        $this->get('/health?type=json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 'degraded')
            ->assertJsonStructure(['status', 'timestamp', 'checks' => ['database', 'redis', 'queue', 'workers', 'scheduler']]);
    }
}
