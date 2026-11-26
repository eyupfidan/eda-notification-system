<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Idempotency-Key was already used for a request with a different payload.
 */
class IdempotencyConflict extends RuntimeException
{
    public static function payloadMismatch(string $key): self
    {
        return new self("Idempotency-Key [{$key}] was already used with a different request payload.");
    }
}
