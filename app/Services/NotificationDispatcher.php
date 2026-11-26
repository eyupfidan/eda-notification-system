<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves notifications into the "queued" state and pushes their delivery jobs.
 *
 * Every path uses one `UPDATE … RETURNING` statement, so only rows that were really
 * in the expected state are dispatched: concurrent cancels, duplicate events or two
 * overlapping recovery sweeps can never queue the same notification twice.
 */
class NotificationDispatcher
{
    private const CHUNK = 500;

    /** Max rows a single recovery sweep touches, to keep each run short. */
    private const SWEEP_LIMIT = 1000;

    /**
     * Freshly accepted notifications: pending → queued.
     *
     * @param  list<string>  $ids
     */
    public function queuePending(array $ids): int
    {
        $queued = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $queued += $this->queueWhere(
                'status = ? AND id IN ('.$this->placeholders($chunk).')',
                [Status::Pending->value, ...$chunk],
            );
        }

        return $queued;
    }

    /**
     * Manual retry of failed notifications: failed → queued with a fresh attempt budget.
     *
     * @param  list<string>  $ids
     */
    public function retryFailed(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->queueWhere(
            'status = ? AND id IN ('.$this->placeholders($ids).')',
            [Status::Failed->value, ...$ids],
            ...$this->freshAttemptBudget(),
        );
    }

    public function retryFailedInBatch(string $batchId): int
    {
        return $this->queueWhere(
            'status = ? AND batch_id = ?',
            [Status::Failed->value, $batchId],
            ...$this->freshAttemptBudget(),
        );
    }

    /**
     * Scheduled notifications whose time has come: scheduled → queued.
     * At most SWEEP_LIMIT per call; the command calls it until nothing is due.
     */
    public function queueDueScheduled(Carbon $now): int
    {
        return $this->queueWhere(...$this->staleSelection(Status::Scheduled, 'scheduled_at', $now->copy()->addSecond()));
    }

    /**
     * Accepted but never queued (e.g. Redis was unreachable when the event fired).
     */
    public function requeueStalePending(Carbon $olderThan): int
    {
        return $this->queueWhere(...$this->staleSelection(Status::Pending, 'created_at', $olderThan));
    }

    /**
     * A worker claimed the notification but died before finishing (crash, OOM, deploy).
     * The provider receives our notification id as Idempotency-Key, so a resend after an
     * unknown outcome can be de-duplicated on its side.
     */
    public function requeueStaleProcessing(Carbon $olderThan): int
    {
        return $this->queueWhere(...$this->staleSelection(Status::Processing, 'updated_at', $olderThan));
    }

    /**
     * Queued but its job vanished (e.g. Redis lost data). Re-pushing is harmless: a
     * duplicate job loses the atomic claim and exits without sending.
     */
    public function redispatchStaleQueued(Carbon $olderThan): int
    {
        return $this->queueWhere(...$this->staleSelection(Status::Queued, 'COALESCE(next_retry_at, queued_at)', $olderThan));
    }

    /**
     * @param  list<mixed>  $whereBindings
     * @param  list<mixed>  $extraBindings
     */
    private function queueWhere(string $where, array $whereBindings, string $extraSet = '', array $extraBindings = []): int
    {
        $now = now();

        $rows = DB::select(
            "UPDATE notifications
                SET status = ?, queued_at = ?, updated_at = ?, next_retry_at = NULL{$extraSet}
              WHERE {$where}
          RETURNING id, channel, priority",
            [Status::Queued->value, $now, $now, ...$extraBindings, ...$whereBindings],
        );

        foreach ($rows as $row) {
            dispatch(new SendNotificationJob($row->id, Channel::from($row->channel), Priority::from($row->priority)));
        }

        return count($rows);
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function staleSelection(Status $status, string $timestampColumn, Carbon $olderThan): array
    {
        // SKIP LOCKED: rows another sweep (or a worker) is updating right now are left alone.
        return [
            "id IN (SELECT id FROM notifications
                     WHERE status = ? AND {$timestampColumn} < ?
                     ORDER BY {$timestampColumn}
                     LIMIT ".self::SWEEP_LIMIT.'
                     FOR UPDATE SKIP LOCKED)',
            [$status->value, $olderThan],
        ];
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function freshAttemptBudget(): array
    {
        return [', attempt_limit = attempts + ?, failed_at = NULL', [(int) config('notifications.retry.max_attempts')]];
    }

    /**
     * @param  list<mixed>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
