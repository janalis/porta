<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Container\ListenerPriority;
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
 * Priority -200 is load-bearing, now that {@see \Porta\Plugin\Runtime\PluginManager::PLUGIN_PRIORITY}
 * bridges `themeChanged` to plugins at -100: a backend that bakes the theme's
 * escape codes (the bundled tempest plugin does) rebuilds itself *before* core
 * drops the caches it feeds. Reversed, the next paint would use the stale
 * engine. This is why the -100 chrome sweep is no longer globally last — it is
 * still after every rebuild listener, which is what that contract is about.
 */
final class HighlightThemeListener
{
    public function __construct(
        private readonly BufferManager $buffers,
    ) {}

    #[AsEventListener(priority: ListenerPriority::CACHE_DROP)]
    public function onThemeChanged(ThemeChanged $event): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $buffer->highlighter->refreshTheme();
        }
    }
}
