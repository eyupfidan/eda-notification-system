<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Exceptions\InvalidStatusTransition;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $batch_id
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property Channel $channel
 * @property string $recipient
 * @property string|null $subject
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property Priority $priority
 * @property Status $status
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'batch_id',
        'idempotency_key',
        'correlation_id',
        'channel',
        'recipient',
        'subject',
        'content',
        'metadata',
        'priority',
        'status',
        'scheduled_at',
    ];

    protected $attributes = [
        'priority' => 'normal',
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'priority' => Priority::class,
            'status' => Status::class,
            'metadata' => 'array',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<NotificationAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class)->orderBy('attempt');
    }

    /**
     * Transition using the in-memory state. Use for single-writer flows.
     *
     * @param  array<string, mixed>  $attributes  extra columns to persist with the transition
     *
     * @throws InvalidStatusTransition
     */
    public function transitionTo(Status $to, array $attributes = []): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw InvalidStatusTransition::between($this->status, $to);
        }

        $this->forceFill([...$this->timestampsFor($to), ...$attributes, 'status' => $to])->save();
    }

    /**
     * Atomic compare-and-set transition: the row is only updated if its *current database*
     * status is one of $from. Returns false when another process (a second worker, a cancel
     * request) changed the status first. This is what prevents duplicate sends and
     * sending a notification that was cancelled in the meantime.
     *
     * @param  Status|list<Status>  $from
     * @param  array<string, mixed>  $attributes  extra columns to persist with the transition
     *
     * @throws InvalidStatusTransition
     */
    public function transitionIfStatus(Status|array $from, Status $to, array $attributes = []): bool
    {
        $from = is_array($from) ? $from : [$from];

        foreach ($from as $status) {
            if (! $status->canTransitionTo($to)) {
                throw InvalidStatusTransition::between($status, $to);
            }
        }

        $now = now();
        $values = [...$this->timestampsFor($to, $now), ...$attributes, 'status' => $to->value, 'updated_at' => $now];

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (Status $s) => $s->value, $from))
            ->update($values);

        if ($updated !== 1) {
            return false;
        }

        $this->forceFill([...$values, 'status' => $to])->syncOriginal();

        return true;
    }

    /**
     * @return array<string, Carbon>
     */
    private function timestampsFor(Status $status, ?Carbon $now = null): array
    {
        $now ??= now();

        return match ($status) {
            Status::Queued => ['queued_at' => $now],
            Status::Sent => ['sent_at' => $now],
            Status::Failed => ['failed_at' => $now],
            Status::Cancelled => ['cancelled_at' => $now],
            default => [],
        };
    }
}
