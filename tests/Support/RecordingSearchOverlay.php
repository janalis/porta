<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Search\Everywhere\SearchOverlay;
use Porta\Search\Everywhere\SearchRow;

/** Keeps what the controller opened the search with, the rows it last set, and how often it closed. */
final class RecordingSearchOverlay implements SearchOverlay
{
    public ?\Closure $onQuery = null;
    public ?\Closure $onPick = null;
    public ?\Closure $onClose = null;
    public ?string $title = null;
    public ?string $emptyMessage = null;
    /** @var list<SearchRow> */
    public array $rows = [];
    public int $closes = 0;

    public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose, string $emptyMessage = ''): void
    {
        $this->title = $title;
        $this->onQuery = $onQuery;
        $this->onPick = $onPick;
        $this->onClose = $onClose;
        $this->emptyMessage = $emptyMessage;
    }

    public function setSearchRows(array $rows): void
    {
        $this->rows = $rows;
    }

    public function closeSearch(): void
    {
        ++$this->closes;
    }
}
