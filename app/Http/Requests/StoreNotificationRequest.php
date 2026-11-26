<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NotificationRules;
use Illuminate\Foundation\Http\FormRequest;

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
}
