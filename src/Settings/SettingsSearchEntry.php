<?php

declare(strict_types=1);

namespace Porta\Settings;

/** One searchable setting: where it lives, and how it reads on screen. */
final readonly class SettingsSearchEntry
{
    public function __construct(
        /** The nav id of the page holding it: 'general', 'plugin:git', 'lang:php'. */
        public string $section,
        /** The row key within that page — NOT unique on its own; `indentStyle` exists on thirteen pages. */
        public string $key,
        public string $label,
        /** The page's display name, shown beside the label so a hit says where it lives. */
        public string $page,
    ) {}
}
