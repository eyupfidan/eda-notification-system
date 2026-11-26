<?php

namespace App\Providers;

use App\Providers\Notifications\NotificationProvider;
use App\Providers\Notifications\WebhookSiteProvider;
use App\Services\Delivery\CircuitBreaker;
use App\Services\Delivery\RetryPolicy;
use App\Services\Tracing\TracerProviderFactory;
use App\Services\Tracing\Tracing;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tracing::class, fn () => new Tracing(TracerProviderFactory::make()));

        $this->app->bind(NotificationProvider::class, fn ($app) => new WebhookSiteProvider(
            $app->make(HttpFactory::class),
            config('notifications.provider.url'),
            (float) config('notifications.provider.timeout'),
            $app->make(Tracing::class),
        ));

        $this->app->bind(RetryPolicy::class, fn () => RetryPolicy::fromConfig());
        $this->app->bind(CircuitBreaker::class, fn () => CircuitBreaker::fromConfig());
    }

    public function boot(): void
    {
        $this->bootTracing();
    }

    /**
     * Distributed tracing plumbing:
     *  - every queued job carries the W3C trace context of the code that dispatched it;
     *  - database queries become child spans of whatever span is active;
     *  - spans are exported at the end of each request and after each job.
     */
    private function bootTracing(): void
    {
        Queue::createPayloadUsing(fn () => app(Tracing::class)->propagationHeaders());

        if (config('tracing.db_spans')) {
            DB::listen(function (QueryExecuted $query) {
                $tracing = app(Tracing::class);

                if (! $tracing->hasActiveSpan()) {
                    return; // no orphan traces for background polling queries
                }

                $end = Clock::getDefault()->now();
                $operation = strtoupper(strtok(ltrim($query->sql), ' ') ?: 'QUERY');

                $tracing->tracer()->spanBuilder($operation.' '.$query->connection->getDatabaseName())
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setStartTimestamp($end - (int) ($query->time * 1_000_000))
                    ->setAttributes([
                        'db.system.name' => 'postgresql',
                        'db.operation.name' => $operation,
                        'db.query.text' => $query->sql,
                    ])
                    ->startSpan()
                    ->end($end);
            });
        }

        $this->app->terminating(fn () => app(Tracing::class)->flush());
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], fn () => app(Tracing::class)->flush());
    }
}
