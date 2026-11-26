<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Tracing\Tracing;
use Closure;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * CONSUMER span around a delivery job, continuing the trace of the request that queued it
 * (the `traceparent` is stored in the queue payload). Retries reuse the payload, so every
 * attempt of a notification shows up in the same trace.
 */
class TraceJob
{
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $tracing = app(Tracing::class);
        $payload = $job->job?->payload() ?? [];

        $tracing->inSpan(
            'deliver '.$job->channel->value,
            function () use ($job, $next, $tracing) {
                if ($traceId = $tracing->currentTraceId()) {
                    Context::add('trace_id', $traceId);
                }

                $next($job);
            },
            [
                'messaging.system' => 'redis',
                'messaging.operation.type' => 'process',
                'messaging.destination.name' => $job->queue,
                'notification.id' => $job->notificationId,
                'notification.channel' => $job->channel->value,
                'queue.attempt' => $job->job?->attempts(),
            ],
            SpanKind::KIND_CONSUMER,
            $tracing->extract(array_filter(['traceparent' => $payload['traceparent'] ?? null, 'tracestate' => $payload['tracestate'] ?? null])),
        );
    }
}
