<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Event\DebugStatusChanged;
use Porta\Event\LspStatusChanged;
use Porta\Event\ParticipantsChanged;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Repaints the status bar when a status source changes. The bar's label is
 * pulled from providers at render time and cached, so a source change only
 * needs to invalidate the cache and schedule a frame.
 */
final readonly class StatusBarRefreshListener
{
    public function __construct(
        private StatusBarWidget $statusBar,
        private Tui $tui,
    ) {}

    #[AsEventListener]
    public function onStatusChanged(DebugStatusChanged|LspStatusChanged|ParticipantsChanged $event): void
    {
        $this->statusBar->invalidate();
        $this->tui->requestRender();
    }
}
