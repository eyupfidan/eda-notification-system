<?php

namespace App\Http\Middleware;

use App\Services\Tracing\Tracing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Root SERVER span for every HTTP request. Continues an incoming W3C `traceparent`
 * (so callers' traces connect), exposes the trace id to logs and to the client
 * (`X-Trace-Id`), and names the span after the matched route.
 */
class TraceRequests
{
    public function __construct(private readonly Tracing $tracing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $span = $this->tracing->tracer()
            ->spanBuilder($request->method().' '.$request->path())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($this->tracing->extract($request->headers->all()))
            ->setAttributes([
                'http.request.method' => $request->method(),
                'url.path' => '/'.ltrim($request->path(), '/'),
                'correlation_id' => Context::get('correlation_id'),
            ])
            ->startSpan();
        $scope = $span->activate();

        if ($traceId = $this->tracing->currentTraceId()) {
            Context::add('trace_id', $traceId);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $scope->detach();
            $span->end();

            throw $e;
        }

        if ($route = $request->route()?->uri()) {
            $span->updateName($request->method().' /'.ltrim($route, '/'));
            $span->setAttribute('http.route', '/'.ltrim($route, '/'));
        }

        $span->setAttribute('http.response.status_code', $response->getStatusCode());
        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        if ($traceId) {
            $response->headers->set('X-Trace-Id', $traceId);
        }

        $scope->detach();
        $span->end();

        return $response;
    }
}
