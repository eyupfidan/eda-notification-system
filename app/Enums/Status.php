<?php

namespace App\Enums;

/**
 * Notification lifecycle:
 *
 *   scheduled ──► pending ──► queued ──► processing ──► sent
 *       │            │           │            │
 *       │            │           │            ├──► queued   (retryable failure, backoff)
 *       │            │           │            └──► failed   (permanent failure / attempts exhausted)
 *       └────────────┴───────────┴──► cancelled
 *
 *   failed ──► queued  (manual retry)
 */
enum Status: string
{
    case Scheduled = 'scheduled';
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Pending, self::Queued, self::Cancelled],
            self::Pending => [self::Queued, self::Cancelled, self::Failed],
            self::Queued => [self::Processing, self::Cancelled],
            self::Processing => [self::Sent, self::Queued, self::Failed],
            self::Failed => [self::Queued],
            self::Sent, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * States from which a notification may still be cancelled (not yet handed to the provider).
     *
     * @return list<self>
     */
    public static function cancellable(): array
    {
        return [self::Scheduled, self::Pending, self::Queued];
    }

    public function isCancellable(): bool
    {
        return in_array($this, self::cancellable(), true);
    }

    public function isFinal(): bool
    {
        return $this === self::Sent || $this === self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
