<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects malformed Idempotency-Key headers early (printable ASCII, max 200 chars).
 * The key itself is enforced by a unique column on notifications, so it survives
 * restarts and has no TTL.
 */
class ValidateIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key !== null && preg_match('/^[\x21-\x7E]{1,200}$/', $key) !== 1) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_idempotency_key',
                    'message' => 'Idempotency-Key must be 1-200 printable ASCII characters without spaces.',
                ],
            ], 400);
        }

        return $next($request);
    }
}
