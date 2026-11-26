<?php

namespace App\Exceptions\Provider;

use RuntimeException;
use Throwable;

abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
