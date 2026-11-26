<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ValidateIdempotencyKey;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

#[Group('Notifications', weight: 0)]
class NotificationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly NotificationService $notifications) {}

    public static function middleware(): array
    {
        return [new Middleware(ValidateIdempotencyKey::class, only: ['store'])];
    }

    /**
     * List notifications.
     *
     * Filter by status, channel, priority, batch and creation date range. Uses cursor
     * pagination: follow `links.next` / `meta.next_cursor` to page through results.
     */
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        return NotificationResource::collection($this->notifications->list($request->validated()));
    }

    /**
     * Create a notification.
     *
     * The notification is stored and queued for asynchronous delivery (202 Accepted).
     * Send an `Idempotency-Key` header to make retries safe: repeating the request with
     * the same key and payload returns the original notification (200,
     * `Idempotent-Replayed: true`) instead of creating a duplicate.
     */
    #[HeaderParameter('Idempotency-Key', 'Makes retries safe: the same key + payload returns the original result instead of creating duplicates. 1-200 printable ASCII characters.', required: false, example: 'order-1042-shipped')]
    #[HeaderParameter('X-Correlation-ID', 'Optional trace id, echoed back and attached to every log line of this request and its delivery.', required: false, example: 'checkout-7f3a')]
    #[Response(202, 'Accepted: stored and queued for delivery. Body is the notification resource.')]
    #[Response(200, 'Idempotent replay (`Idempotent-Replayed: true`): the notification created earlier with this key.')]
    #[Response(400, 'Malformed Idempotency-Key header.')]
    #[Response(422, 'Validation failed, or `idempotency_key_reused`: the key was used with a different payload.')]
    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $result = $this->notifications->create(
            $request->payload(),
            $request->header(ValidateIdempotencyKey::HEADER),
        );

        // Location only accompanies a newly accepted resource: PHP-FPM rewrites a 200 that
        // carries a Location header into a 302 redirect, so replays use Content-Location.
        return NotificationResource::make($result->notification)
            ->response()
            ->setStatusCode($result->replayed ? 200 : 202)
            ->header($result->replayed ? 'Content-Location' : 'Location', url("/api/v1/notifications/{$result->notification->id}"))
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    /**
     * Get a notification with its delivery attempts.
     */
    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification->load('deliveryAttempts'));
    }

    /**
     * Cancel a notification.
     *
     * Only notifications that are not yet being delivered (scheduled, pending, queued)
     * can be cancelled; otherwise 409 is returned. Cancelling twice is a no-op.
     */
    #[Response(409, 'The notification is already being delivered or reached a final state (`invalid_status_transition`).')]
    public function cancel(Notification $notification): NotificationResource
    {
        return NotificationResource::make($this->notifications->cancel($notification));
    }

    /**
     * Retry a failed notification.
     *
     * Re-queues a notification in `failed` status with a fresh attempt budget (202).
     * Any other status returns 409.
     */
    #[Response(409, 'Only notifications in `failed` status can be retried (`invalid_status_transition`).')]
    public function retry(Notification $notification): JsonResponse
    {
        return NotificationResource::make($this->notifications->retry($notification))
            ->response()
            ->setStatusCode(202);
    }
}
