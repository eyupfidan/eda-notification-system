<?php

namespace App\Exceptions;

use App\Enums\Status;
use DomainException;

class InvalidStatusTransition extends DomainException
{
    public static function between(Status $from, Status $to): self
    {
        return new self("Cannot transition notification from [{$from->value}] to [{$to->value}].");
    }
}
