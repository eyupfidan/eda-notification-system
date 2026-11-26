<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    private function create(array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello',
        ], $headers);
    }

    public function test_an_incoming_correlation_id_is_kept_and_echoed(): void
    {
        Queue::fake();

        $response = $this->create(['X-Correlation-ID' => 'checkout-flow-42'])
            ->assertStatus(202)
            ->assertHeader('X-Correlation-ID', 'checkout-flow-42')
            ->assertJsonPath('data.correlation_id', 'checkout-flow-42');

        $this->assertSame('checkout-flow-42', Notification::find($response->json('data.id'))->correlation_id);
    }

    public function test_a_correlation_id_is_generated_when_missing_or_malformed(): void
    {
        Queue::fake();

        $generated = $this->create()->headers->get('X-Correlation-ID');
        $replaced = $this->create(['X-Correlation-ID' => 'has spaces & symbols!'])->headers->get('X-Correlation-ID');

        $this->assertTrue(Str::isUuid($generated));
        $this->assertTrue(Str::isUuid($replaced));
    }

    public function test_every_response_carries_a_correlation_id(): void
    {
        $this->getJson('/api/v1/notifications/'.Str::uuid7())
            ->assertNotFound()
            ->assertHeader('X-Correlation-ID');

        $this->get('/health')->assertHeader('X-Correlation-ID');
    }

    public function test_batch_notifications_share_the_request_correlation_id(): void
    {
        Queue::fake();
        $item = ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi'];

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [$item, $item]], ['X-Correlation-ID' => 'campaign-7'])
            ->assertStatus(202);

        $this->assertSame(['campaign-7'], Notification::pluck('correlation_id')->unique()->values()->all());
    }

    public function test_the_job_restores_the_correlation_id_and_forwards_it_to_the_provider(): void
    {
        // Capture the log context that is active while the job talks to the provider.
        $contextDuringSend = [];
        Http::fake(function () use (&$contextDuringSend) {
            $contextDuringSend = Context::all();

            return Http::response(['messageId' => 'm'], 202);
        });
        $notification = Notification::factory()->status(Status::Queued)->create(['correlation_id' => 'trace-abc']);

        // e.g. re-dispatched by the recovery sweep: no request context at all
        Context::flush();
        dispatch(new SendNotificationJob($notification->id, $notification->channel, $notification->priority));

        $this->assertSame('trace-abc', $contextDuringSend['correlation_id'] ?? null);
        $this->assertSame($notification->id, $contextDuringSend['notification_id'] ?? null);
        $this->assertSame('sms', $contextDuringSend['channel'] ?? null);
        Http::assertSent(fn (Request $request) => $request->header('X-Correlation-ID') === ['trace-abc']);
    }
}
