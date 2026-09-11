<?php

declare(strict_types=1);

namespace Porta\Workbench\Menu;

use Porta\Event\LocaleChanged;
use Porta\Event\ThemeChanged;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Layout\PaneLayout;
use Porta\Widget\ContextMenu;
use Porta\Widget\MenuBarWidget;
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
        private readonly Translator $translator,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
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
        // The menus lay their shortcut column out for the direction too, and
        // hold it as state because neither widget carries a Translator. This is
        // also the BOOT path, not only the live switch: Ide's startup calls
        // SettingsController::applyAll(), which applies the persisted locale
        // through the same setting and so dispatches this event.
        $direction = $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;
        $this->menuBar->setTextDirection($direction);
        $this->contextMenu->setTextDirection($direction);
    }
}
