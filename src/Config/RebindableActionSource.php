<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * The rebindable actions plugins contribute — a pane's keys, which are actions
 * rather than commands: they fire only while that pane holds the editor slot,
 * they never appear in the palette, and the pane's own
 * `getDefaultKeybindings()` supplies their defaults.
 *
 * Owned by the CONSUMING namespace (the {@see \Porta\Editor\ViewerProviderSource}
 * precedent), so `Porta\Config` never depends on `Porta\Plugin` even though
 * every row here comes from a plugin's `registerActions()` call.
 */
interface RebindableActionSource
{
    /** @return array<string, string> action id => untranslated label, in load order */
    public function rebindableActions(): array;
}
