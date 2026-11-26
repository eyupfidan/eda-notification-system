<?php

namespace App\Enums;

enum AttemptOutcome: string
{
    case Success = 'success';
    case RetryableFailure = 'retryable_failure';
    case PermanentFailure = 'permanent_failure';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
