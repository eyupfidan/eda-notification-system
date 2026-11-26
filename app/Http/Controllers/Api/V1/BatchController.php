<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ValidateIdempotencyKey;
use App\Http\Requests\StoreBatchRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BatchController extends Controller implements HasMiddleware
{
    public function __construct(private readonly NotificationService $notifications) {}

    public static function middleware(): array
    {
        return [new Middleware(ValidateIdempotencyKey::class, only: ['store'])];
    }

    /**
     * Create a batch of notifications.
     *
     * Accepts up to 1000 notifications. The batch is stored atomically (all or nothing)
     * and every notification gets the same `batch_id`. Supports `Idempotency-Key`.
     */
    public function store(StoreBatchRequest $request): JsonResponse
    {
        $result = $this->notifications->createBatch(
            $request->validated('notifications'),
            $request->header(ValidateIdempotencyKey::HEADER),
        );

        return response()->json([
            'data' => [
                'batch_id' => $result->batchId,
                'total' => $result->notifications->count(),
                'notifications' => $result->notifications->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'channel' => $n->channel,
                    'recipient' => $n->recipient,
                    'priority' => $n->priority,
                    'status' => $n->status,
                ])->values(),
            ],
        ], $result->replayed ? 200 : 202)
            // See NotificationController::store() for why replays use Content-Location.
            ->header($result->replayed ? 'Content-Location' : 'Location', url("/api/v1/batches/{$result->batchId}"))
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    /**
     * Get batch status.
     *
     * Returns how many notifications of the batch are in each status.
     */
    public function show(string $batchId): JsonResponse
    {
        $summary = $this->notifications->batchSummary($batchId) ?? abort(404, 'Batch not found.');

        return response()->json(['data' => $summary]);
    }

    /**
     * Retry failed notifications of a batch.
     *
     * Re-queues every `failed` notification of the batch with a fresh attempt budget.
     */
    public function retry(string $batchId): JsonResponse
    {
        if ($this->notifications->batchSummary($batchId) === null) {
            abort(404, 'Batch not found.');
        }

        $retried = $this->notifications->retryBatch($batchId);

        return response()->json([
            'data' => [
                'retried' => $retried,
                ...$this->notifications->batchSummary($batchId),
            ],
        ], 202);
    }

    /**
     * Cancel a batch.
     *
     * Cancels every notification of the batch that has not started delivery yet.
     */
    public function cancel(string $batchId): JsonResponse
    {
        if ($this->notifications->batchSummary($batchId) === null) {
            abort(404, 'Batch not found.');
        }

        $cancelled = $this->notifications->cancelBatch($batchId);

        return response()->json([
            'data' => [
                'cancelled' => $cancelled,
                ...$this->notifications->batchSummary($batchId),
            ],
        ]);
    }
}
