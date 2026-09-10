<?php

declare(strict_types=1);

namespace Porta\Tree;

/**
 * One file-tree drag, from the press that armed it to the release that spends
 * it.
 *
 * Mutable on purpose, the shape {@see \Porta\Layout\SidebarResizeState} uses:
 * there is no shared drag abstraction in this codebase — every other drag
 * hand-rolls its state as fields on {@see \Porta\Input\MouseRouter} — and a
 * readonly VO would mean rebuilding it on every motion event.
 *
 * $sources is fixed at the press because that is the only moment the answer is
 * unambiguous: the click that follows may collapse the selection to the row
 * under the pointer, and by the first drag event the set the user grabbed is
 * already gone.
 */
final class TreeDragState
{
    /** The directory the pointer is currently over, or null when it is not over one. */
    public ?string $target = null;

    /**
     * Whether the pointer has moved at all since the press.
     *
     * A press and release on one spot is a click, not a drop — the click has
     * already done its own work by then, and acting again would move a file
     * because someone selected it.
     */
    public bool $moved = false;

    /** @param list<string> $sources absolute paths, in tree order */
    public function __construct(
        public readonly array $sources,
    ) {}
}
