<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NotificationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBatchRequest extends FormRequest
{
    use NotificationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = config('notifications.batch_max');

        return [
            'notifications' => ['required', 'array', 'list', 'min:1', "max:{$max}"],
            'notifications.*' => ['required', 'array'],
            ...$this->notificationRules('notifications.*.'),
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->resolvePayloads(
            $validator,
            fn (array $data) => is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
            fn (Validator $v) => $v->validated()['notifications'],
            fn (int $i) => "notifications.{$i}.",
        )];
    }

    /**
     * The final notification payloads (templates rendered), in request order.
     *
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return $this->resolvedPayloads;
    }
}
