<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\DiagnosticsChanged;
use Porta\Search\ProblemRows;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Keeps the live dock list tools fresh: when their source data changes while
 * the results panel serves that tool, the rows are rebuilt in place. Guarded
 * on isOpen() (not isVisible()) so a concealed tab stays fresh and the dock
 * strip's count updates too. Only setResults + a repaint — never open(),
 * reveal() or a focus move, so a background refresh cannot yank the user
 * around; the known trade-off is that setResults resets the list selection
 * to the first row.
 */
final class DockLiveRefreshListener
{
    public function __construct(
        private readonly BottomDock $dock,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly Tui $tui,
        private readonly ProblemRows $problemRows,
    ) {}

    #[AsEventListener]
    public function onDiagnosticsChanged(DiagnosticsChanged $event): void
    {
        $this->refresh(ResultsPanelMode::Problems, $this->problemRows->build(...));
    }

    /** @param callable(): list<array{path: string, line: int, text: string}> $rows */
    private function refresh(ResultsPanelMode $when, callable $rows): void
    {
        if (!$this->resultsPanel->isOpen() || $when !== $this->dock->mode()) {
            return;
        }
        $this->resultsPanel->setResults($rows());
        $this->tui->requestRender();
    }
}
