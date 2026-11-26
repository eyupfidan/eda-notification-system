<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NotificationRules;
use Illuminate\Foundation\Http\FormRequest;

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
}
