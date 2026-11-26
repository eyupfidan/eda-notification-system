<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Collection;

final readonly class BatchCreationResult
{
    /**
     * @param  Collection<int, Notification>  $notifications  in request order
     */
    public function __construct(
        public string $batchId,
        public Collection $notifications,
        public bool $replayed,
    ) {}
}
