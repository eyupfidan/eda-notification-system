<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NotificationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNotificationRequest extends FormRequest
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
        return $this->notificationRules();
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->resolvePayloads(
            $validator,
            fn (array $data) => [$data],
            fn (Validator $v) => [$v->validated()],
            fn () => '',
        )];
    }

    /**
     * The final notification payload (template rendered).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->resolvedPayloads[0];
    }
}
