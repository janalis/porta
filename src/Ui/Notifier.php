<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The single status-bar notification path: flash a transient message and request
 * a repaint. Replaces the `statusBar->flash(...)` + `tui->requestRender()` pair
 * that controllers and services across the app hand-rolled (three of them behind
 * byte-identical private `flash()` helpers).
 */
final readonly class Notifier
{
    public function __construct(
        private StatusBarWidget $statusBar,
        private Tui $tui,
    ) {}

    /** Flash $message on the status bar for $seconds and repaint. */
    public function flash(string $message, float $seconds = 2.5): void
    {
        $this->statusBar->flash($message, $seconds);
        $this->tui->requestRender();
    }
}
