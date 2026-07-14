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
 * empty), 'action' (a button — `value` is its caption, actuating fires onChange
 * with it), or a stepped integer (bounded by `min`/`max`).
 *
 * A 'choice' row cycles and persists the `options` values, but renders each via
 * `optionLabels` when present — e.g. the language picker stores `en` but shows
 * `English`.
 */
final readonly class SettingsRow
{
    /**
     * @param list<string>|null         $options      the values a 'choice' row cycles through
     * @param array<string, string>|null $optionLabels display label per option value (else the value itself)
     */
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
        public ?array $optionLabels = null,
    ) {}
}
