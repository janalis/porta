<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * One row of the keybindings list: a rebindable action, its display label, and
 * its current primary binding token (empty when unbound). Replaces the
 * string-keyed array the {@see SettingsKeymapController} handed the
 * {@see \Porta\Widget\Settings\KeybindingsListWidget}.
 */
final readonly class KeybindingRow
{
    public function __construct(
        public string $action,
        public string $label,
        public string $binding,
        /**
         * Whether a config file binds this action — so the row is showing a
         * choice rather than the shipped default, and has something to reset.
         *
         * Not the provenance this page turned down: that question is which of
         * three merged WIDGET keymaps a binding came from, and it stays
         * unanswered. This one is `keymap` naming the action in .porta.json or
         * not, which the merge already knows exactly.
         */
        public bool $overridden = false,
    ) {}
}
