<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Rules\ContentWithinChannelLimit;
use App\Rules\ValidRecipient;
use Illuminate\Validation\Rule;

trait NotificationRules
{
    /**
     * Rules for one notification payload. `$prefix` is '' for a single request
     * and 'notifications.*.' for batch items.
     *
     * @return array<string, mixed>
     */
    protected function notificationRules(string $prefix = ''): array
    {
        return [
            "{$prefix}channel" => ['required', 'string', Rule::enum(Channel::class)],
            "{$prefix}recipient" => ['required', 'string', 'max:320', new ValidRecipient],
            "{$prefix}subject" => ["required_if:{$prefix}channel,email", 'nullable', 'string', 'max:255'],
            "{$prefix}content" => ['required', 'string', new ContentWithinChannelLimit],
            "{$prefix}priority" => ['sometimes', 'string', Rule::enum(Priority::class)],
            "{$prefix}metadata" => ['sometimes', 'nullable', 'array', 'max:50'],
        ];
    }
}
