<?php

declare(strict_types=1);

namespace Porta\Event;

/**
 * Show a settings page by its nav id, optionally landing on one row.
 *
 * The form's `2` key and the keybindings list's `1` key both mean this — two
 * widgets advertising a shortcut for "take me to the other page", which used to
 * be two closures naming the same method with different constants.
 *
 * $row is a row KEY, and it is carried alongside the section rather than
 * instead of it because row keys are not unique across pages: `indentStyle`
 * exists on every one of the thirteen language pages. An event naming the key
 * alone would land on whichever page happened to be showing, which is worse
 * than not moving at all — it looks like it worked.
 */
final readonly class SettingsSectionRequested
{
    public function __construct(
        public string $section,
        public ?string $row = null,
    ) {}
}
