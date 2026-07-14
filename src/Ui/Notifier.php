<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * The single status-bar notification path: flash a transient message and request
 * a repaint. Replaces the `statusBar->flash(...)` + `tui->requestRender()` pair
 * that controllers and services across the app hand-rolled (three of them behind
 * byte-identical private `flash()` helpers). Every flash is also appended to the
 * {@see NotificationLog}, so expired messages stay reviewable.
 */
final readonly class Notifier
{
    public function __construct(
        private StatusBarWidget $statusBar,
        private Tui $tui,
        private ?NotificationLog $log = null,
    ) {}

    /** Flash $message on the status bar for $seconds and repaint. */
    public function flash(string $message, float $seconds = 2.5): void
    {
        $this->log?->append($message);
        $this->statusBar->flash($message, $seconds);
        $this->tui->requestRender();
    }
}
