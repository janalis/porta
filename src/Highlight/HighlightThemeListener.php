<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Editor\BufferManager;
use Porta\Event\ThemeChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Drops every live highlight cache after a theme switch.
 *
 * This used to run inline in `LiveSettingsApplier::applyTheme()`, before the
 * event was dispatched at all. As a listener it is ordered with the rest of the
 * theme reaction and stays testable on its own.
 *
 * Priority -50 keeps it after the menu/layout rebuild (0) and ahead of the -100
 * chrome sweep, whose "runs last" position is a documented contract. **When a
 * plugin-facing theme hook is added** — {@see \Porta\Plugin\PluginEvents::EVENTS}
 * carries no `themeChanged` today, so no plugin can react to a switch — this
 * must move below the -100 plugin bridge, so a backend that bakes the theme's
 * escape codes rebuilds itself before core drops the caches. Until such a hook
 * exists there is nothing to order against, and the contract stands.
 */
final class HighlightThemeListener
{
    public function __construct(
        private readonly BufferManager $buffers,
    ) {}

    #[AsEventListener(priority: -50)]
    public function onThemeChanged(ThemeChanged $event): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $buffer->highlighter->refreshTheme();
        }
    }
}
