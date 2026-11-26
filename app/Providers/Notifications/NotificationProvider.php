<?php

namespace App\Providers\Notifications;

use App\Exceptions\Provider\PermanentProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Models\Notification;

/**
 * Gateway to the external SMS / Email / Push provider.
 */
interface NotificationProvider
{
    /**
     * @throws RetryableProviderException temporary problem (timeout, 5xx, 429): safe to try again later
     * @throws PermanentProviderException the provider rejected the message: retrying will not help
     */
    public function send(Notification $notification): ProviderResponse;
}
