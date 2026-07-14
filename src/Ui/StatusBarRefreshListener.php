<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\DebugStatusChanged;
use Porta\Event\LspStatusChanged;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Repaints the status bar when a status source changes. The bar's label is
 * pulled from providers at render time and cached, so a source change only
 * needs to invalidate the cache and schedule a frame.
 */
final class StatusBarRefreshListener
{
    public function __construct(
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
    ) {}

    #[AsEventListener]
    public function onStatusChanged(DebugStatusChanged|LspStatusChanged $event): void
    {
        $this->statusBar->invalidate();
        $this->tui->requestRender();
    }
}
