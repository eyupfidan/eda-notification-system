<?php

namespace Tests\Feature;

use App\Services\Tracing\Tracing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\TestCase;

class TracingTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exporter = new InMemoryExporter;
        $provider = TracerProvider::builder()->addSpanProcessor(new SimpleSpanProcessor($this->exporter))->build();
        $this->app->instance(Tracing::class, new Tracing($provider));
    }

    /**
     * @return Collection<int, ImmutableSpan>
     */
    private function spans(): Collection
    {
        return collect($this->exporter->getSpans());
    }

    private function span(string $name): ImmutableSpan
    {
        $span = $this->spans()->first(fn (ImmutableSpan $s) => $s->getName() === $name);
        $this->assertNotNull($span, "Span [{$name}] not found. Got: ".$this->spans()->map->getName()->implode(', '));

        return $span;
    }

    public function test_every_request_gets_a_server_span_named_after_its_route(): void
    {
        $response = $this->getJson('/api/v1/notifications/01a21962-0000-7000-8000-000000000000')->assertNotFound();

        $span = $this->span('GET /api/v1/notifications/{notification}');
        $this->assertSame(SpanKind::KIND_SERVER, $span->getKind());
        $this->assertSame(404, $span->getAttributes()->get('http.response.status_code'));
        $this->assertSame($span->getTraceId(), $response->headers->get('X-Trace-Id'));
    }

    public function test_an_incoming_traceparent_is_continued(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';

        $this->getJson('/health', ['traceparent' => "00-{$traceId}-00f067aa0ba902b7-01"]);

        $span = $this->span('GET /health');
        $this->assertSame($traceId, $span->getTraceId());
        $this->assertSame('00f067aa0ba902b7', $span->getParentSpanId());
    }

    public function test_one_trace_follows_a_notification_from_the_api_through_the_queue_to_the_provider(): void
    {
        Http::fake(['provider.test/*' => Http::response(['messageId' => 'm-1'], 202)]);

        $response = $this->postJson('/api/v1/notifications', [
            'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Traced',
        ], ['X-Correlation-ID' => 'trace-me'])->assertStatus(202);

        $server = $this->span('POST /api/v1/notifications');
        $create = $this->span('notification.create');
        $deliver = $this->span('deliver sms');
        $provider = $this->span('POST provider');

        // Same trace end to end
        $traceId = $server->getTraceId();
        foreach ([$create, $deliver, $provider] as $span) {
            $this->assertSame($traceId, $span->getTraceId(), $span->getName().' is in another trace');
        }

        // Parent chain: request → create → (queue) → deliver → provider call
        $this->assertSame($server->getSpanId(), $create->getParentSpanId());
        $this->assertSame(SpanKind::KIND_CONSUMER, $deliver->getKind());
        $this->assertSame($deliver->getSpanId(), $provider->getParentSpanId());
        $this->assertSame(SpanKind::KIND_CLIENT, $provider->getKind());
        $this->assertSame(202, $provider->getAttributes()->get('http.response.status_code'));
        $this->assertSame($response->json('data.id'), $deliver->getAttributes()->get('notification.id'));
        $this->assertSame('trace-me', $server->getAttributes()->get('correlation_id'));

        // The provider receives our trace context
        Http::assertSent(function (Request $request) use ($traceId, $provider) {
            // version-traceid-parentid-flags; flags may carry more than the sampled bit
            return preg_match("/^00-{$traceId}-{$provider->getSpanId()}-[0-9a-f]{2}$/", $request->header('traceparent')[0] ?? '') === 1;
        });
    }

    public function test_database_queries_are_recorded_as_child_spans(): void
    {
        $this->getJson('/api/v1/notifications')->assertOk();

        $server = $this->span('GET /api/v1/notifications');
        $queries = $this->spans()->filter(fn (ImmutableSpan $s) => $s->getAttributes()->get('db.system.name') === 'postgresql');

        $this->assertNotEmpty($queries);
        $this->assertTrue($queries->every(fn (ImmutableSpan $s) => $s->getTraceId() === $server->getTraceId()));
        $this->assertStringContainsString('notifications', $queries->first()->getAttributes()->get('db.query.text'));
    }

    public function test_errors_are_marked_on_the_span(): void
    {
        try {
            $this->app->make(Tracing::class)->inSpan('boom', fn () => throw new \RuntimeException('kaput'));
        } catch (\RuntimeException) {
        }

        $span = $this->span('boom');
        $this->assertSame('Error', $span->getStatus()->getCode());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_tracing_disabled_is_a_no_op(): void
    {
        $this->app->forgetInstance(Tracing::class);
        config(['tracing.enabled' => false]);

        $this->getJson('/health')->assertOk()->assertHeaderMissing('X-Trace-Id');
    }
}
