<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Event\DiagnosticsChanged;
use Porta\Event\LspLogAppended;
use Porta\Event\NotificationLogged;
use Porta\Lsp\LspLog;
use Porta\Search\ProblemRows;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps the live dock list tools fresh: when their source data changes, the
 * rows are rebuilt into the tool's {@see DockToolTab} — including while the
 * tab is inactive, so activating it always shows current data — and pushed
 * to the widget only when that tab is on display. Never open(), reveal() or
 * a focus move, so a background refresh cannot yank the user around; the
 * known trade-off is that a bound refresh resets the list selection to the
 * first row.
 */
final class DockLiveRefreshListener
{
    public function __construct(
        private readonly BottomDock $dock,
        private readonly DockToolTabs $tools,
        private readonly ProblemRows $problemRows,
        private readonly LspLog $lspLog,
        private readonly NotificationLog $notificationLog,
    ) {}

    #[AsEventListener]
    public function onDiagnosticsChanged(DiagnosticsChanged $event): void
    {
        $this->refresh(ResultsPanelMode::Problems, $this->problemRows->build(...));
    }

    #[AsEventListener]
    public function onLspLogAppended(LspLogAppended $event): void
    {
        $this->refresh(ResultsPanelMode::LspLog, fn (): array => LogRows::build($this->lspLog->entries()));
    }

    #[AsEventListener]
    public function onNotificationLogged(NotificationLogged $event): void
    {
        $this->refresh(ResultsPanelMode::Notifications, fn (): array => LogRows::build($this->notificationLog->entries()));
    }

    /** @param callable(): list<array{path: string, line: int, text: string}> $rows */
    private function refresh(ResultsPanelMode $when, callable $rows): void
    {
        $tab = $this->tools->findByMode($when); // constant-title tools: at most one tab
        if (null === $tab) {
            return;
        }
        $tab->rows = $rows();
        $this->dock->refreshIfBound($tab);
    }
}
