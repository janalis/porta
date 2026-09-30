<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Widget\ViewerTabHandler;

/**
 * The five tab verbs a markdown page re-binds while the code editor is
 * detached, handed to the IDE's own tab handler — the same for the help
 * browser and the markdown preview, whose handlers differ only in where a
 * link leads.
 */
trait DelegatesTabVerbs
{
    abstract private function tabs(): ViewerTabHandler;

    #[\Override]
    public function cycleBuffer(int $offset): void
    {
        $this->tabs()->cycleBuffer($offset);
    }

    #[\Override]
    public function closeBuffer(): void
    {
        $this->tabs()->closeBuffer();
    }

    #[\Override]
    public function openGotoFile(): void
    {
        $this->tabs()->openGotoFile();
    }

    #[\Override]
    public function saveAll(): void
    {
        $this->tabs()->saveAll();
    }

    /**
     * A page scrolls rather than zooms, so a "view changed" ping would repaint
     * a status segment it does not contribute — but the pane contract is one
     * interface, and the navigator already knows how to refresh the bar.
     */
    #[\Override]
    public function viewChanged(): void
    {
        $this->tabs()->viewChanged();
    }
}
