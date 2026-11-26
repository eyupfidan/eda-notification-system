<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (taken from the X-Correlation-ID header when it
 * is well-formed, generated otherwise) and puts it in Laravel's Context:
 *  - every log line written while handling the request carries it,
 *  - jobs dispatched by the request inherit it, so worker logs carry it too,
 *  - it is echoed back in the response header.
 */
class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $correlationId = is_string($incoming) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
