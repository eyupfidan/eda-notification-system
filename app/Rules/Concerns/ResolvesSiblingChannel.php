<?php

namespace App\Rules\Concerns;

use App\Enums\Channel;

/**
 * Lets a rule read the `channel` of the same notification, both for a single
 * payload (`recipient`) and for batch items (`notifications.3.recipient`).
 */
trait ResolvesSiblingChannel
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    protected function channelFor(string $attribute): ?Channel
    {
        $value = data_get($this->data, preg_replace('/[^.]+$/', 'channel', $attribute));

        return is_string($value) ? Channel::tryFrom($value) : null;
    }
}
