<?php

namespace App\Providers\Notifications;

use App\Exceptions\Provider\ProviderException;
use App\Models\Notification;
use App\Services\Delivery\ProviderFailover;

/**
 * Primary provider with a fallback for when the primary stops taking messages altogether.
 *
 * Only statuses that mean "this endpoint will not accept anything for a while" switch over:
 * 429 (webhook.site: request quota reached), 404 / 410 (URL expired or deleted). The primary
 * rejected those requests, so sending the same message to the fallback cannot duplicate it.
 * Timeouts and 5xx stay with the primary and its retry policy / circuit breaker.
 */
class FailoverProvider implements NotificationProvider
{
    public const FAILOVER_STATUSES = [404, 410, 429];

    public function __construct(
        private readonly NotificationProvider $primary,
        private readonly NotificationProvider $fallback,
        private readonly ProviderFailover $failover,
    ) {}

    public function send(Notification $notification): ProviderResponse
    {
        if ($this->failover->active()) {
            return $this->fallback->send($notification);
        }

        try {
            return $this->primary->send($notification);
        } catch (ProviderException $e) {
            if (! in_array($e->httpStatus, self::FAILOVER_STATUSES, true)) {
                throw $e;
            }

            $this->failover->activate($e->getMessage());

            return $this->fallback->send($notification);
        }
    }
}
