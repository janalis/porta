<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;

/**
 * The git view's left column as one resizable unit: the four stacked widgets
 * each render their own width, so a drag on the git separator fans the new
 * width out to all of them (see {@see LayoutController}).
 */
final readonly class GitSidebar
{
    public function __construct(
        private GitTabsWidget $tabs,
        private ChangesListWidget $changes,
        private CommitBoxWidget $commitBox,
        private HistoryListWidget $history,
    ) {}

    public function setWidth(int $width): void
    {
        $this->tabs->setWidth($width);
        $this->changes->setWidth($width);
        $this->commitBox->setWidth($width);
        $this->history->setWidth($width);
    }

    public function width(): int
    {
        return $this->changes->getWidth();
    }
}
