<?php

namespace App\Rules;

use App\Rules\Concerns\ResolvesSiblingChannel;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces the per-channel character limit (multibyte-aware).
 */
class ContentWithinChannelLimit implements DataAwareRule, ValidationRule
{
    use ResolvesSiblingChannel;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $channel = $this->channelFor($attribute);

        if ($channel === null || ! is_string($value)) {
            return;
        }

        $max = $channel->maxContentLength();

        if (mb_strlen($value) > $max) {
            $fail("The :attribute may not be greater than {$max} characters for the {$channel->value} channel.");
        }
    }
}
