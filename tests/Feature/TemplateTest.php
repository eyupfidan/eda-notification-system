<?php

namespace Tests\Feature;

use App\Events\NotificationsAccepted;
use App\Models\Notification;
use App\Models\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([NotificationsAccepted::class]);
    }

    // ------------------------------------------------------------------ CRUD

    public function test_it_creates_a_template_and_lists_its_variables(): void
    {
        $this->postJson('/api/v1/templates', [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Hi {{ name }}, order {{order.id}} is on its way. {{ name }}!',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'order-shipped')
            ->assertJsonPath('data.variables', ['name', 'order.id']);
    }

    public function test_template_validation(): void
    {
        Template::factory()->create(['name' => 'taken']);

        $this->postJson('/api/v1/templates', ['name' => 'taken', 'channel' => 'fax', 'content' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name', 'channel', 'content']]]);

        $this->postJson('/api/v1/templates', ['name' => 'Has Spaces', 'channel' => 'email', 'content' => 'x'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['name', 'subject']]]);
    }

    public function test_it_shows_updates_and_deletes_templates(): void
    {
        $template = Template::factory()->create(['name' => 'welcome']);

        $this->getJson('/api/v1/templates')->assertOk()->assertJsonPath('data.0.name', 'welcome');
        $this->getJson("/api/v1/templates/{$template->id}")->assertOk()->assertJsonPath('data.id', $template->id);

        $this->patchJson("/api/v1/templates/{$template->id}", ['content' => 'Hello {{ first_name }}'])
            ->assertOk()
            ->assertJsonPath('data.name', 'welcome')
            ->assertJsonPath('data.variables', ['first_name']);

        $this->deleteJson("/api/v1/templates/{$template->id}")->assertNoContent();
        $this->getJson("/api/v1/templates/{$template->id}")
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Template not found.');
    }

    public function test_preview_renders_or_reports_missing_variables(): void
    {
        $template = Template::factory()->email()->create();

        $this->postJson("/api/v1/templates/{$template->id}/preview", ['variables' => ['name' => 'Jane', 'company' => 'Acme']])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Welcome, Jane!')
            ->assertJsonPath('data.content', 'Hello Jane, thanks for joining Acme.');

        $this->postJson("/api/v1/templates/{$template->id}/preview", ['variables' => ['name' => 'Jane']])
            ->assertStatus(422)
            ->assertJsonPath('error.details.variables.0', 'Missing template variables: company.');
    }

    // ------------------------------------------------------- notifications

    public function test_a_notification_can_be_created_from_a_template(): void
    {
        $template = Template::factory()->create();

        $response = $this->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template_id' => $template->id,
            'variables' => ['name' => 'Jane', 'order' => ['id' => 'A-1042']],
        ])->assertStatus(202)
            ->assertJsonPath('data.content', 'Hi Jane, your order A-1042 has shipped.')
            ->assertJsonPath('data.template_id', $template->id)
            ->assertJsonPath('data.template_variables.order.id', 'A-1042');

        // The rendered text is stored: later template edits do not change sent messages.
        $template->update(['content' => 'Changed {{ name }}']);
        $this->assertSame('Hi Jane, your order A-1042 has shipped.', Notification::find($response->json('data.id'))->content);
    }

    public function test_email_templates_provide_the_subject(): void
    {
        $template = Template::factory()->email()->create();

        $this->postJson('/api/v1/notifications', [
            'channel' => 'email',
            'recipient' => 'jane@example.com',
            'template_id' => $template->id,
            'variables' => ['name' => 'Jane', 'company' => 'Acme'],
        ])->assertStatus(202)
            ->assertJsonPath('data.subject', 'Welcome, Jane!');
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidTemplateUsage(): array
    {
        return [
            'missing variables' => [['variables' => ['name' => 'Jane']], 'variables', 'Missing template variables: order.id.'],
            'nested value is not scalar' => [['variables' => ['name' => 'Jane', 'order' => ['id' => ['x']]]], 'variables', 'Missing template variables: order.id.'],
            'wrong channel' => [['channel' => 'push', 'recipient' => str_repeat('a', 32)], 'template_id', 'is for the sms channel'],
            'unknown template' => [['template_id' => '01a21962-0000-7000-8000-000000000000'], 'template_id', 'does not exist'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidTemplateUsage')]
    public function test_invalid_template_usage_is_rejected(array $overrides, string $field, string $message): void
    {
        $template = Template::factory()->create();

        $response = $this->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template_id' => $template->id,
            'variables' => ['name' => 'Jane', 'order' => ['id' => 'A-1']],
            ...$overrides,
        ])->assertStatus(422);

        $this->assertStringContainsString($message, $response->json("error.details.{$field}.0"));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_rendered_content_must_fit_the_channel_limit(): void
    {
        $template = Template::factory()->create(['content' => '{{ body }}']);

        $this->postJson('/api/v1/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template_id' => $template->id,
            'variables' => ['body' => str_repeat('a', 1601)],
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['content']]]);
    }

    public function test_content_or_template_is_required(): void
    {
        $this->postJson('/api/v1/notifications', ['channel' => 'sms', 'recipient' => '+905551234567'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['content']]]);
    }

    public function test_batch_items_can_use_templates_and_errors_point_at_the_item(): void
    {
        $template = Template::factory()->create();
        $item = fn (array $variables) => [
            'channel' => 'sms', 'recipient' => '+905551234567', 'template_id' => $template->id, 'variables' => $variables,
        ];

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            $item(['name' => 'A', 'order' => ['id' => '1']]),
            $item(['name' => 'B']),
        ]])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['notifications.1.variables']]]);

        $this->postJson('/api/v1/notifications/batch', ['notifications' => [
            $item(['name' => 'A', 'order' => ['id' => '1']]),
            $item(['name' => 'B', 'order' => ['id' => '2']]),
            ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Plain message'],
        ]])->assertStatus(202);

        $this->assertSame(
            ['Hi A, your order 1 has shipped.', 'Hi B, your order 2 has shipped.', 'Plain message'],
            Notification::orderBy('content')->pluck('content')->all(),
        );
    }

    public function test_deleting_a_template_keeps_notifications_created_from_it(): void
    {
        $template = Template::factory()->create();
        $id = $this->postJson('/api/v1/notifications', [
            'channel' => 'sms', 'recipient' => '+905551234567', 'template_id' => $template->id,
            'variables' => ['name' => 'Jane', 'order' => ['id' => '9']],
        ])->json('data.id');

        $this->deleteJson("/api/v1/templates/{$template->id}")->assertNoContent();

        $notification = Notification::find($id);
        $this->assertNull($notification->template_id);
        $this->assertSame('Hi Jane, your order 9 has shipped.', $notification->content);
    }

    public function test_unknown_template_ids_return_404(): void
    {
        $this->postJson('/api/v1/templates/'.Str::uuid7().'/preview')->assertNotFound();
    }
}
