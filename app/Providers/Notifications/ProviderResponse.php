<?php

namespace App\Providers\Notifications;

final readonly class ProviderResponse
{
    public function __construct(
        public string $messageId,
        public int $httpStatus,
        public ?string $status = null,
        public ?string $timestamp = null,
    ) {}
}
