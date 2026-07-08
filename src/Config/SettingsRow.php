<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * One row of the settings form: a labelled setting the {@see \Porta\Widget\Settings\SettingsFormWidget}
 * renders and actuates. Replaces the string-keyed array the settings controllers
 * previously handed the widget, making the producer→widget contract explicit.
 *
 * `kind` drives how the value renders and cycles: 'bool' (checkbox), 'choice'
 * (cycle through `options`), 'text' (inline editor, with an optional `hint` when
 * empty), or a stepped integer (bounded by `min`/`max`).
 */
final readonly class SettingsRow
{
    /** @param list<string>|null $options the values a 'choice' row cycles through */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public bool|int|string $value,
        public bool $restart,
        public ?array $options = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?string $hint = null,
    ) {}
}
