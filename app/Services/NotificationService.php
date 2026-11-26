<?php

namespace App\Services;

use App\Enums\Priority;
use App\Enums\Status;
use App\Events\NotificationsAccepted;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /** Rows per INSERT statement when storing a batch. */
    private const INSERT_CHUNK = 500;

    /**
     * Fields that must match when an Idempotency-Key is replayed.
     */
    private const FINGERPRINT_FIELDS = ['channel', 'recipient', 'subject', 'content', 'priority', 'scheduled_at'];

    /**
     * @param  array<string, mixed>  $data  validated payload
     *
     * @throws IdempotencyConflict
     */
    public function create(array $data, ?string $idempotencyKey = null): CreationResult
    {
        if ($idempotencyKey !== null && $existing = $this->findByIdempotencyKey($idempotencyKey)) {
            return $this->replay($existing, $data, $idempotencyKey);
        }

        try {
            $notification = DB::transaction(function () use ($data, $idempotencyKey) {
                $attributes = $this->attributes($data);

                $notification = Notification::create([
                    ...$attributes,
                    'status' => $this->initialStatus($attributes),
                    'idempotency_key' => $idempotencyKey,
                    'correlation_id' => Context::get('correlation_id'),
                ]);

                NotificationsAccepted::dispatch([$notification->id]);

                return $notification;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race: return its result.
            if ($idempotencyKey === null || ! $existing = $this->findByIdempotencyKey($idempotencyKey)) {
                throw $e;
            }

            return $this->replay($existing, $data, $idempotencyKey);
        }

        return new CreationResult($notification->refresh(), replayed: false);
    }

    /**
     * Stores up to `batch_max` notifications with a few multi-row INSERTs inside one
     * transaction: either the whole batch is accepted or nothing is.
     *
     * @param  list<array<string, mixed>>  $items  validated payloads
     *
     * @throws IdempotencyConflict
     */
    public function createBatch(array $items, ?string $idempotencyKey = null): BatchCreationResult
    {
        if ($idempotencyKey !== null && $first = $this->findByIdempotencyKey($this->batchItemKey($idempotencyKey, 0))) {
            return $this->replayBatch($first->batch_id, $items, $idempotencyKey);
        }

        $batchId = (string) Str::uuid7();
        $now = now();
        $correlationId = Context::get('correlation_id');

        $rows = array_map(fn (array $item, int $index) => [
            'id' => (string) Str::uuid7(),
            'batch_id' => $batchId,
            'idempotency_key' => $idempotencyKey !== null ? $this->batchItemKey($idempotencyKey, $index) : null,
            'correlation_id' => $correlationId,
            ...$this->attributes($item),
            'metadata' => isset($item['metadata']) ? json_encode($item['metadata']) : null,
            'status' => $this->initialStatus($this->attributes($item))->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $items, array_keys($items));

        try {
            DB::transaction(function () use ($rows, $batchId) {
                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    Notification::query()->insert($chunk);
                }

                NotificationsAccepted::dispatch(array_column($rows, 'id'), $batchId);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey === null || ! $first = $this->findByIdempotencyKey($this->batchItemKey($idempotencyKey, 0))) {
                throw $e;
            }

            return $this->replayBatch($first->batch_id, $items, $idempotencyKey);
        }

        $notifications = collect($rows)->map(fn (array $row) => (new Notification)->forceFill([
            ...$row,
            'metadata' => $row['metadata'] !== null ? json_decode($row['metadata'], true) : null,
        ]));

        return new BatchCreationResult($batchId, $notifications, replayed: false);
    }

    /**
     * Cancels a notification that has not been handed to the provider yet.
     * Cancelling an already-cancelled notification is a no-op (idempotent).
     *
     * @throws InvalidStatusTransition when processing already started or it is final
     */
    public function cancel(Notification $notification): Notification
    {
        if ($notification->status === Status::Cancelled) {
            return $notification;
        }

        if ($notification->transitionIfStatus(Status::cancellable(), Status::Cancelled)) {
            return $notification;
        }

        // Lost the race (e.g. a worker just picked it up): report the real current state.
        $notification->refresh();

        if ($notification->status === Status::Cancelled) {
            return $notification;
        }

        throw InvalidStatusTransition::between($notification->status, Status::Cancelled);
    }

    /**
     * Manually re-queues a failed notification with a fresh attempt budget.
     *
     * @throws InvalidStatusTransition when the notification is not failed
     */
    public function retry(Notification $notification): Notification
    {
        if ($this->dispatcher->retryFailed([$notification->id]) === 1) {
            return $notification->refresh();
        }

        $notification->refresh();

        throw InvalidStatusTransition::between($notification->status, Status::Queued);
    }

    /**
     * Re-queues every failed notification of the batch.
     *
     * @return int number of notifications re-queued
     */
    public function retryBatch(string $batchId): int
    {
        return $this->dispatcher->retryFailedInBatch($batchId);
    }

    /**
     * Cancels every notification of the batch that is still cancellable.
     *
     * @return int number of notifications cancelled by this call
     */
    public function cancelBatch(string $batchId): int
    {
        $now = now();

        return Notification::query()
            ->where('batch_id', $batchId)
            ->whereIn('status', array_map(fn (Status $s) => $s->value, Status::cancellable()))
            ->update([
                'status' => Status::Cancelled->value,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @return array{batch_id: string, total: int, statuses: array<string, int>}|null
     */
    public function batchSummary(string $batchId): ?array
    {
        $counts = Notification::query()
            ->where('batch_id', $batchId)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if ($counts->isEmpty()) {
            return null;
        }

        $statuses = collect(Status::cases())
            ->mapWithKeys(fn (Status $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])
            ->all();

        return [
            'batch_id' => $batchId,
            'total' => array_sum($statuses),
            'statuses' => $statuses,
        ];
    }

    /**
     * Cursor pagination keeps deep pages fast on large tables (no OFFSET scans, no COUNT(*)).
     *
     * @param  array<string, mixed>  $filters  validated query parameters
     * @return CursorPaginator<int, Notification>
     */
    public function list(array $filters): CursorPaginator
    {
        $perPage = (int) ($filters['per_page'] ?? config('notifications.per_page_default'));

        return Notification::query()
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $this->endOfRange($v)))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'channel' => $data['channel'],
            'recipient' => $data['recipient'],
            'subject' => $data['subject'] ?? null,
            'content' => $data['content'],
            'metadata' => $data['metadata'] ?? null,
            'priority' => $data['priority'] ?? Priority::Normal->value,
            'scheduled_at' => $this->scheduledAt($data['scheduled_at'] ?? null),
        ];
    }

    /**
     * Normalised to the application time zone (UTC): the database layer formats dates
     * without their offset, so "15:00+03:00" must become "12:00" before it is stored.
     */
    private function scheduledAt(?string $value): ?Carbon
    {
        return $value !== null ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    /**
     * Only a time in the future schedules the notification; a past or current time means
     * "send now" (the requested time is still stored, which keeps idempotent replays stable).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function initialStatus(array $attributes): Status
    {
        return $attributes['scheduled_at']?->isFuture() ? Status::Scheduled : Status::Pending;
    }

    private function findByIdempotencyKey(string $key): ?Notification
    {
        return Notification::query()->where('idempotency_key', $key)->first();
    }

    private function batchItemKey(string $key, int $index): string
    {
        return "{$key}#{$index}";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replay(Notification $existing, array $data, string $key): CreationResult
    {
        if ($existing->batch_id !== null || ! $this->samePayload($existing, $data)) {
            throw IdempotencyConflict::payloadMismatch($key);
        }

        return new CreationResult($existing, replayed: true);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replayBatch(string $batchId, array $items, string $key): BatchCreationResult
    {
        /** @var Collection<string, Notification> $existing */
        $existing = Notification::query()->where('batch_id', $batchId)->get()->keyBy('idempotency_key');

        if ($existing->count() !== count($items)) {
            throw IdempotencyConflict::payloadMismatch($key);
        }

        $ordered = collect($items)->map(function (array $item, int $index) use ($existing, $key) {
            $notification = $existing->get($this->batchItemKey($key, $index));

            if ($notification === null || ! $this->samePayload($notification, $item)) {
                throw IdempotencyConflict::payloadMismatch($key);
            }

            return $notification;
        });

        return new BatchCreationResult($batchId, $ordered, replayed: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function samePayload(Notification $notification, array $data): bool
    {
        $incoming = $this->attributes($data);

        foreach (self::FINGERPRINT_FIELDS as $field) {
            [$stored, $new] = [$notification->{$field}, $incoming[$field]];
            $stored = $stored instanceof \BackedEnum ? $stored->value : $stored;

            if ($stored instanceof \DateTimeInterface || $new instanceof \DateTimeInterface) {
                [$stored, $new] = [$stored?->getTimestamp(), $new?->getTimestamp()];
            }

            if ($stored !== $new) {
                return false;
            }
        }

        return true;
    }

    private function endOfRange(string $value): Carbon
    {
        // A date without a time ("2026-11-26") means "until the end of that day".
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? Carbon::parse($value)->endOfDay()
            : Carbon::parse($value);
    }
}
