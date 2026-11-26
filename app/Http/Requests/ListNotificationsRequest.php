<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(Status::class)],
            'channel' => ['sometimes', 'string', Rule::enum(Channel::class)],
            'priority' => ['sometimes', 'string', Rule::enum(Priority::class)],
            'batch_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('notifications.per_page_max')],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
