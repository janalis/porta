<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\ViewerTabHandler;

/** Records every hook invocation for assertions. */
final class RecordingViewerTabHandler implements ViewerTabHandler
{
    /** @var list<string> */
    public array $events = [];

    public function cycleBuffer(int $offset): void
    {
        $this->events[] = "cycle:{$offset}";
    }

    public function closeBuffer(): void
    {
        $this->events[] = 'close';
    }

    public function saveAll(): void
    {
        $this->events[] = 'save_all';
    }

    public function viewChanged(): void
    {
        $this->events[] = 'view';
    }

    public function revealSource(int $line, int $byteCol): void
    {
        $this->events[] = 'reveal:'.$line.':'.$byteCol;
    }
}
