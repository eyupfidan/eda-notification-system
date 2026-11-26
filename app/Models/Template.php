<?php

namespace App\Models;

use App\Enums\Channel;
use App\Services\Templates\TemplateRenderer;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable message with `{{ variable }}` placeholders.
 *
 * @property string $id
 * @property string $name
 * @property Channel $channel
 * @property string|null $subject
 * @property string $content
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'channel', 'subject', 'content'];

    protected function casts(): array
    {
        return ['channel' => Channel::class];
    }

    /**
     * Placeholders used in the subject and content, e.g. ["name", "order.id"].
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return app(TemplateRenderer::class)->variables($this->subject ?? '', $this->content);
    }
}
