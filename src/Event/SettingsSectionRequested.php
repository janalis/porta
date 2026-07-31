<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Show a settings page by its nav id.
 *
 * The form's `2` key and the keybindings list's `1` key both mean this — two
 * widgets advertising a shortcut for "take me to the other page", which used to
 * be two closures naming the same method with different constants.
 */
final readonly class SettingsSectionRequested
{
    public function __construct(
        public string $section,
    ) {}
}
