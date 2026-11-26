<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTemplateRequest extends FormRequest
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
        /** @var Template|null $template */
        $template = $this->route('template');
        $updating = $template !== null;
        $channel = $this->input('channel', $template?->channel->value);

        return [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('templates')->ignore($template?->id)],
            'channel' => [$updating ? 'sometimes' : 'required', 'string', Rule::enum(Channel::class)],
            'subject' => [$channel === Channel::Email->value && ! $updating ? 'required' : 'nullable', 'string', 'max:255'],
            'content' => [$updating ? 'sometimes' : 'required', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'The name may only contain lowercase letters, numbers, dots, dashes and underscores.'];
    }
}
