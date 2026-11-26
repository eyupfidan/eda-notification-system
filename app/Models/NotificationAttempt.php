<?php

namespace App\Models;

use App\Enums\AttemptOutcome;
use Database\Factories\NotificationAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery attempt against the external provider (audit trail + metrics source).
 *
 * @property int $id
 * @property string $notification_id
 * @property int $attempt
 * @property AttemptOutcome $outcome
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property Carbon $created_at
 */
class NotificationAttempt extends Model
{
    /** @use HasFactory<NotificationAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'notification_id',
        'attempt',
        'outcome',
        'http_status',
        'duration_ms',
        'provider_message_id',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => AttemptOutcome::class,
            'attempt' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
