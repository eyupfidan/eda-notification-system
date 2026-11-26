<?php

namespace App\Enums;

enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Each priority has its own queue; workers drain them in the order high > normal > low.
     */
    public function queue(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
