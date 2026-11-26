<?php

namespace App\Providers\Notifications;

use App\Exceptions\Provider\PermanentProviderException;
use App\Exceptions\Provider\RetryableProviderException;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Sends notifications to the simulated provider (webhook.site).
 *
 * Request:  POST {url} {"to": "...", "channel": "sms", "content": "..."}
 * Response: 202 {"messageId": "...", "status": "accepted", "timestamp": "..."}
 */
class WebhookSiteProvider implements NotificationProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $url,
        private readonly float $timeoutSeconds,
    ) {}

    public function send(Notification $notification): ProviderResponse
    {
        if (blank($this->url)) {
            throw new PermanentProviderException('Provider URL is not configured (NOTIFICATION_PROVIDER_URL).');
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->connectTimeout(min(2.0, $this->timeoutSeconds))
                ->acceptJson()
                ->withHeaders([
                    // Lets the provider de-duplicate if we ever deliver the same notification twice.
                    'Idempotency-Key' => $notification->id,
                ])
                ->post($this->url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            throw new RetryableProviderException('Provider unreachable: '.$e->getMessage(), previous: $e);
        }

        return $this->interpret($response);
    }

    private function interpret(Response $response): ProviderResponse
    {
        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            throw new RetryableProviderException(
                "Provider responded with HTTP {$status}",
                $status,
                $this->retryAfter($response),
            );
        }

        if (! $response->successful()) {
            throw new PermanentProviderException("Provider rejected the message with HTTP {$status}", $status);
        }

        $messageId = $response->json('messageId');

        if (! is_string($messageId) || $messageId === '') {
            // Accepted but unidentifiable: do not retry, it may already have been delivered.
            throw new PermanentProviderException("Provider returned HTTP {$status} without a messageId", $status);
        }

        return new ProviderResponse(
            messageId: $messageId,
            httpStatus: $status,
            status: $response->json('status'),
            timestamp: $response->json('timestamp'),
        );
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : null;
    }
}
