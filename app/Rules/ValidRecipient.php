<?php

namespace App\Rules;

use App\Enums\Channel;
use App\Rules\Concerns\ResolvesSiblingChannel;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the recipient format for the notification's channel:
 * SMS: E.164 phone number, Email: address, Push: device token.
 */
class ValidRecipient implements DataAwareRule, ValidationRule
{
    use ResolvesSiblingChannel;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $channel = $this->channelFor($attribute);

        // Unknown/missing channel is reported by the channel rule itself.
        if ($channel === null || ! is_string($value)) {
            return;
        }

        $valid = match ($channel) {
            Channel::Sms => preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1,
            Channel::Email => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            Channel::Push => preg_match('/^[A-Za-z0-9:_\-.]{16,320}$/', $value) === 1,
        };

        if (! $valid) {
            $fail(match ($channel) {
                Channel::Sms => 'The :attribute must be a phone number in E.164 format (e.g. +905551234567).',
                Channel::Email => 'The :attribute must be a valid email address.',
                Channel::Push => 'The :attribute must be a valid device token (16-320 characters).',
            });
        }
    }
}
