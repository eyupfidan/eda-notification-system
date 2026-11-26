<?php

namespace App\Exceptions\Provider;

use Throwable;

/**
 * Temporary failure (timeout, connection error, 5xx, 429). The message may succeed later.
 */
class RetryableProviderException extends ProviderException
{
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
