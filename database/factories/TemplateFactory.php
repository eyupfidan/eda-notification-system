<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'channel' => Channel::Sms,
            'subject' => null,
            'content' => 'Hi {{ name }}, your order {{ order.id }} has shipped.',
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => Channel::Email,
            'subject' => 'Welcome, {{ name }}!',
            'content' => 'Hello {{ name }}, thanks for joining {{ company }}.',
        ]);
    }
}
