<?php

namespace App\Services\Templates;

/**
 * Replaces `{{ variable }}` placeholders. Dot notation reaches nested values
 * (`{{ order.id }}`). Every placeholder must be provided: a message is never sent
 * with a raw "{{ name }}" or a silently empty gap in it.
 */
class TemplateRenderer
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)\s*\}\}/';

    /**
     * @return list<string>
     */
    public function variables(string ...$texts): array
    {
        $names = [];

        foreach ($texts as $text) {
            preg_match_all(self::PATTERN, $text, $matches);
            $names = [...$names, ...$matches[1]];
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws MissingTemplateVariables
     */
    public function render(string $text, array $variables): string
    {
        $missing = array_values(array_filter(
            $this->variables($text),
            fn (string $name) => ! is_scalar(data_get($variables, $name)),
        ));

        if ($missing !== []) {
            throw new MissingTemplateVariables($missing);
        }

        return preg_replace_callback(
            self::PATTERN,
            fn (array $m) => $this->stringify(data_get($variables, $m[1])),
            $text,
        );
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
