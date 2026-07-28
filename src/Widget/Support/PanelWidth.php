<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

use Porta\Support\IntMath;

/**
 * Shared column-width state for the resizable side panels (file tree, git
 * sidebar tabs, settings nav). Each widget seeds its own default in its
 * constructor and declares its clamp bounds via {@see minWidth()}/
 * {@see maxWidth()} — the bounds of {@see \Porta\Layout\ResizableSidebar},
 * so the layout controller reads them off the pane instead of restating them;
 * it drives resizes through {@see setWidth()}.
 */
trait PanelWidth
{
    private int $width;

    /** Satisfied by AbstractWidget. */
    abstract public function invalidate(): void;

    /** Narrowest column this panel may be resized to. */
    public function minWidth(): int
    {
        return 24;
    }

    /** Widest column this panel may be resized to. */
    public function maxWidth(): int
    {
        return 200;
    }

    /** Resize the panel to $width columns (clamped to a sensible range). */
    public function setWidth(int $width): void
    {
        $width = IntMath::clamp($width, $this->minWidth(), $this->maxWidth());
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function width(): int
    {
        return $this->width;
    }
}
