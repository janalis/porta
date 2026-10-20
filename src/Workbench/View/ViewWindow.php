<?php

declare(strict_types=1);

namespace Porta\Workbench\View;

use Porta\Container\AsIdeService;
use Porta\Container\LayoutSlot;
use Porta\Layout\ViewRegion;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The one view window: which view's region it holds, attached where the
 * frame never lays it out ({@see \Porta\Layout\WorkspaceLayout::$windowFrame}).
 * The presenter draws it; this only says what is in it.
 *
 * Every open counts: a view opening fresh, or another view taking the window
 * over, is a new window — back at its default size, centred — while the same
 * view showing again (git re-shows on a mode switch) keeps where the user put
 * it. {@see opened()} is how the presenter tells the two apart.
 */
#[AsIdeService]
final class ViewWindow
{
    private ?ViewRegion $region = null;

    private ?string $id = null;

    private int $opened = 0;

    public function __construct(
        #[LayoutSlot('window_frame')]
        private readonly ContainerWidget $frame,
    ) {}

    /** Hold view $id's $region; a different view (or none) before it makes a fresh window. */
    public function open(ViewRegion $region, string $id): void
    {
        if ($id !== $this->id) {
            ++$this->opened;
        }
        if ($region !== $this->region) {
            $this->frame->clear();
            // The header first: the frame stacks its children, and an empty
            // header renders no row at all.
            $this->frame->add($region->header());
            $this->frame->add($region->area());
        }
        $this->region = $region;
        $this->id = $id;
    }

    /** Empty the window: the region detaches, so its widgets' rects stop answering hit-tests. */
    public function close(): void
    {
        $this->frame->clear();
        $this->region = null;
        $this->id = null;
    }

    /** The region the window holds, or null while it is closed. */
    public function region(): ?ViewRegion
    {
        return $this->region;
    }

    /** The view the window holds, or null while it is closed. */
    public function openId(): ?string
    {
        return $this->id;
    }

    /** The container the region is attached to, which the presenter renders. */
    public function frame(): ContainerWidget
    {
        return $this->frame;
    }

    /** How many windows have opened: it moves when the window is a new one, not on a re-show. */
    public function opened(): int
    {
        return $this->opened;
    }
}
