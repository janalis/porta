<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Widget\Onboarding\WelcomeWidget;
use Symfony\Component\Tui\Tui;

/**
 * Mouse routing for the Welcome view's single pane. Owns that widget so
 * {@see MouseRouter} does not, mirroring {@see SettingsMouseRouter} — its pane
 * is live only while the Welcome view is swapped into the main area, so its hit
 * tests miss otherwise.
 *
 * Simpler than its peers: one pane, no sidebar, no separator and so no drag
 * state. A click only *selects* a step — running one installs software, and no
 * peer executes on a bare click either.
 */
final class WelcomeMouseRouter
{
    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly WelcomeWidget $welcome,
    ) {}

    /** Vertical wheel over the step list; returns whether it scrolled. */
    public function scrollVertical(MouseEvent $mouse): bool
    {
        if (null === $this->hit->hit($this->welcome, $mouse->row, $mouse->col)) {
            return false;
        }

        $this->welcome->scrollBy(WheelStep::rows($mouse));

        return true;
    }

    /** A left press on the step list (the rect exists only while the view is swapped in). */
    public function tryPaneClick(MouseEvent $mouse): bool
    {
        $rel = $this->hit->hit($this->welcome, $mouse->row, $mouse->col);
        if (null === $rel) {
            return false;
        }

        $this->tui->setFocus($this->welcome);
        $this->welcome->handleClick($rel['row']);
        $this->tui->requestRender();

        return true;
    }

    /** Tint the step under the pointer; a miss clears it. */
    public function hover(MouseEvent $mouse): void
    {
        if ($this->hit->hover($mouse->row, $mouse->col, $this->welcome)) {
            $this->tui->requestRender();
        }
    }
}
