<?php

namespace App\Services\Templates;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Validation\Validator;

/**
 * Turns validated notification payloads into their final form: renders templates
 * (`template_id` + `variables` → subject/content) and applies the checks that need the
 * final text (email subject, per-channel content length). Problems are reported on the
 * validator under the offending item's keys, so batch errors point at e.g.
 * `notifications.3.variables`.
 */
class NotificationPayloadResolver
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  array<int, array<string, mixed>>  $items  keyed by item index
     * @param  callable(int): string  $prefix  error-key prefix for item i ('' or 'notifications.i.')
     * @return array<int, array<string, mixed>>
     */
    public function resolve(array $items, Validator $validator, callable $prefix): array
    {
        $templateIds = array_values(array_unique(array_filter(array_map(fn (array $item) => $item['template_id'] ?? null, $items))));
        $templates = $templateIds === [] ? collect() : Template::query()->whereIn('id', $templateIds)->get()->keyBy('id');

        foreach ($items as $i => $item) {
            $p = $prefix($i);
            $channel = Channel::from($item['channel']);

            if (! empty($item['template_id'])) {
                $template = $templates->get($item['template_id']);

                if ($template === null) {
                    $validator->errors()->add("{$p}template_id", 'The selected template does not exist.');

                    continue;
                }

                if ($template->channel !== $channel) {
                    $validator->errors()->add("{$p}template_id", "Template [{$template->name}] is for the {$template->channel->value} channel.");

                    continue;
                }

                try {
                    $variables = $item['variables'] ?? [];
                    $item['content'] = $this->renderer->render($template->content, $variables);
                    $item['subject'] = $template->subject !== null
                        ? $this->renderer->render($template->subject, $variables)
                        : ($item['subject'] ?? null);
                } catch (MissingTemplateVariables $e) {
                    $validator->errors()->add("{$p}variables", $e->getMessage());

                    continue;
                }

                $max = $channel->maxContentLength();
                if (mb_strlen($item['content']) > $max) {
                    $validator->errors()->add("{$p}content", "The rendered content may not be greater than {$max} characters for the {$channel->value} channel.");
                }
            }

            if ($channel === Channel::Email && blank($item['subject'] ?? null)) {
                $validator->errors()->add("{$p}subject", 'The subject field is required when channel is email.');
            }

            $items[$i] = $item;
        }

        return $items;
    }
}
