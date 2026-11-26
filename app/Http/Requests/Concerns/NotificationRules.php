<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Rules\ContentWithinChannelLimit;
use App\Rules\ValidRecipient;
use App\Services\Templates\NotificationPayloadResolver;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait NotificationRules
{
    /** @var list<array<string, mixed>> */
    private array $resolvedPayloads = [];

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
            // Required for email; checked after a template may have provided it.
            "{$prefix}subject" => ['nullable', 'string', 'max:255'],
            "{$prefix}content" => ["required_without:{$prefix}template_id", 'string', new ContentWithinChannelLimit],
            "{$prefix}template_id" => ['sometimes', 'nullable', 'uuid'],
            "{$prefix}variables" => ['sometimes', 'nullable', 'array'],
            "{$prefix}priority" => ['sometimes', 'string', Rule::enum(Priority::class)],
            "{$prefix}metadata" => ['sometimes', 'nullable', 'array', 'max:50'],
            // Future time → stored as "scheduled" and sent then; past/now → sent right away.
            "{$prefix}scheduled_at" => ['sometimes', 'nullable', 'date', 'before_or_equal:+30 days'],
        ];
    }

    /**
     * Second validation pass on the final payloads (templates rendered, email subject, length).
     * It runs for every item that passed the first pass, so a batch reports all problems at once.
     *
     * @param  callable(array<string, mixed>): array<int, mixed>  $rawItems  items from the request data
     * @param  callable(Validator): list<array<string, mixed>>  $validatedItems
     * @param  callable(int): string  $prefix  error-key prefix of item i
     */
    protected function resolvePayloads(Validator $validator, callable $rawItems, callable $validatedItems, callable $prefix): void
    {
        $errorKeys = $validator->errors()->keys();
        $checkable = array_filter(
            $rawItems($validator->getData()),
            fn ($item, int $i) => is_array($item) && ! collect($errorKeys)->contains(
                fn (string $key) => $prefix($i) === '' || str_starts_with($key, $prefix($i)),
            ),
            ARRAY_FILTER_USE_BOTH,
        );

        $resolved = app(NotificationPayloadResolver::class)->resolve($checkable, $validator, $prefix);

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $this->resolvedPayloads = array_map(
            fn (array $item, int $i) => [
                ...$item,
                'content' => $resolved[$i]['content'] ?? $item['content'] ?? null,
                'subject' => $resolved[$i]['subject'] ?? $item['subject'] ?? null,
            ],
            $validated = $validatedItems($validator),
            array_keys($validated),
        );
    }
}
