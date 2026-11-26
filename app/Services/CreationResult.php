<?php

namespace App\Services;

use App\Models\Notification;

final readonly class CreationResult
{
    public function __construct(
        public Notification $notification,
        public bool $replayed,
    ) {}
}
