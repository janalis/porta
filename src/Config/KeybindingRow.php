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
    ) {}
}
