<?php

use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InvalidStatusTransition;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\TraceRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Every API error uses the same envelope:
 * {"error": {"code": "...", "message": "...", "details": {...}}}
 */
$apiError = fn (int $status, string $code, string $message, array $details = []) => response()->json([
    'error' => array_filter([
        'code' => $code,
        'message' => $message,
        'details' => $details ?: null,
    ], fn ($value) => $value !== null),
], $status);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id first, so the request span can carry it.
        $middleware->prepend([AssignCorrelationId::class, TraceRequests::class]);
        // The app is only reachable through nginx (and Traefik in production), which terminate
        // TLS: trust their X-Forwarded-* headers so generated URLs (e.g. the OpenAPI server) use https.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'metrics', 'health') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiError) {
            if ($request->is('api/*', 'metrics', 'health')) {
                return $apiError(422, 'validation_failed', 'The given data was invalid.', $e->errors());
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($apiError) {
            if ($request->is('api/*', 'metrics', 'health')) {
                $message = match (true) {
                    $e->getPrevious() instanceof ModelNotFoundException => class_basename($e->getPrevious()->getModel()).' not found.',
                    $e->getMessage() !== '' => $e->getMessage(),
                    default => 'Resource not found.',
                };

                return $apiError(404, 'not_found', $message);
            }
        });

        $exceptions->render(function (InvalidStatusTransition $e, Request $request) use ($apiError) {
            if ($request->is('api/*', 'metrics', 'health')) {
                return $apiError(409, 'invalid_status_transition', $e->getMessage());
            }
        });

        $exceptions->render(function (IdempotencyConflict $e, Request $request) use ($apiError) {
            if ($request->is('api/*', 'metrics', 'health')) {
                return $apiError(422, 'idempotency_key_reused', $e->getMessage());
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($apiError) {
            if ($request->is('api/*', 'metrics', 'health')) {
                $status = $e->getStatusCode();
                $text = SymfonyResponse::$statusTexts[$status] ?? 'HTTP Error';

                return $apiError($status, str($text)->lower()->snake()->toString(), $e->getMessage() ?: $text.'.');
            }
        });
    })->create();
