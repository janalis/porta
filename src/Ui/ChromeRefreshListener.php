<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\CursorShapeChanged;
use Porta\Event\LocaleChanged;
use Porta\Event\TabBarPositionChanged;
use Porta\Event\ThemeChanged;
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
        private readonly Tui $tui,
    ) {}

    #[AsEventListener(priority: -100)]
    public function onSettingApplied(ThemeChanged|LocaleChanged|CursorShapeChanged|TabBarPositionChanged $event): void
    {
        $this->chrome->invalidateAll();
        $this->tui->requestRender();
    }
}
