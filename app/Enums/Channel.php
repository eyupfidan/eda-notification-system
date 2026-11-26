<?php

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /**
     * Maximum content length per channel (characters).
     * SMS: 10 concatenated GSM-7 segments; Push: typical provider payload limit.
     */
    public function maxContentLength(): int
    {
        return match ($this) {
            self::Sms => 1600,
            self::Email => 100_000,
            self::Push => 1000,
        };
    }

    /**
     * Whether the channel requires a subject/title.
     */
    public function requiresSubject(): bool
    {
        return $this === self::Email;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
