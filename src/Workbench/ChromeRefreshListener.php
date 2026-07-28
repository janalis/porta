<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
use Porta\Overlay\Presenter\WelcomeModalController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Sweeps every chrome render cache after a live setting change and schedules
 * a frame. Priority -100: the sweep must postdate the menu rebuild / RTL
 * re-mirror listeners so their re-baked output isn't cached stale.
 */
final class ChromeRefreshListener
{
    public function __construct(
        private readonly ChromeInvalidator $chrome,
        private readonly WelcomeModalController $welcomeModal,
        private readonly Tui $tui,
    ) {}

    #[AsEventListener(priority: -100)]
    public function onSettingApplied(ThemeChanged|LocaleChanged|CursorShapeChanged|TabBarPositionChanged $event): void
    {
        $this->chrome->invalidateAll();
        // The Welcome modal's frame and content are composited spans, not tree
        // widgets — the cache sweep cannot reach them, so re-compose directly
        // (a steps rebuild also re-translates its labels on a locale switch).
        if ($this->welcomeModal->isOpen()) {
            $this->welcomeModal->refresh();
        }
        $this->tui->requestRender();
    }
}
