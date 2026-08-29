<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Config\ConfigScope;

/**
 * One row of the settings form: a labelled setting the {@see \Porta\Widget\Settings\SettingsFormWidget}
 * renders and actuates. Replaces the string-keyed array the settings controllers
 * previously handed the widget, making the producer→widget contract explicit.
 *
 * {@see SettingsRowKind} drives how the value renders and cycles: a checkbox, a
 * `options` cycle, an inline text editor (with an optional `hint` when empty), a
 * button (`value` is its caption, actuating fires onChange with it), or a stepped
 * integer (bounded by `min`/`max`).
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
        public SettingsRowKind $kind,
        public bool|int|string $value,
        public bool $restart,
        public ?array $options = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?string $hint = null,
        public ?array $optionLabels = null,
        public bool $danger = false,
        /** How far one keypress moves an int; 1 when unset. */
        public ?int $step = null,
        /**
         * The value this row has when the user has not chosen one; null when
         * the row has no default to differ from (a button, or session state).
         */
        public bool|int|string|null $default = null,
        /**
         * Which config file this row's value actually came from, and whether
         * that file's value hides one in the layer below it. Null origin means
         * neither file sets it — the value is a default, which needs no tag.
         */
        public ?ConfigScope $origin = null,
        public bool $shadowed = false,
        /**
         * Whether the detail clause may list the options this row is NOT on.
         *
         * True for an ordinary choice, where `< dark > · light/high-contrast`
         * tells you what the stepper can reach. False where the options are
         * DESTINATIONS rather than modes: naming the file you are not saving
         * to, after the one you are, reads as a second destination instead of
         * an alternative — and it is the first thing a narrow row truncates,
         * so it is noise even when it is understood.
         */
        public bool $listOptions = true,
    ) {}

    /**
     * Whether the row differs from its default VALUE — not whether its key is
     * present in `.porta.json`.
     *
     * Presence is the right question for a migration (has the new owner
     * spoken yet?) and the wrong one here: a file that says `"theme": "dark"`
     * when dark is the default would light a marker meaning nothing, and a
     * user asking "what have I changed on this page?" means changed from
     * stock. It also costs no I/O and works the same for a plugin row, whose
     * default lives on its descriptor.
     *
     * The default must be coerced through this row's kind exactly as the value
     * was, or an int 1 will not equal a bool true and every checkbox reads as
     * modified.
     */
    public function isModified(): bool
    {
        return null !== $this->default && $this->value !== $this->default;
    }
}
