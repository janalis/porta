<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Event\LocaleChanged;
use Porta\Event\ThemeChanged;
use Porta\Layout\PaneLayout;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The live-setting reactions on baked/arranged state: the menu bar bakes
 * translated, colored labels (theme + locale both invalidate them) and a
 * locale switch can flip the writing direction (RTL mirrors the pane order).
 * Default priority 0 — these run before the -100 ChromeRefreshListener sweep,
 * so the sweep repaints the already-rebuilt state.
 */
final class MenuLayoutRefreshListener
{
    public function __construct(
        private readonly MenuRebuilder $menuRebuilder,
        private readonly PaneLayout $paneLayout,
    ) {}

    #[AsEventListener]
    public function onThemeChanged(ThemeChanged $event): void
    {
        $this->menuRebuilder->rebuild();
    }

    #[AsEventListener]
    public function onLocaleChanged(LocaleChanged $event): void
    {
        $this->menuRebuilder->rebuild();
        $this->paneLayout->applyDirection();
    }
}
