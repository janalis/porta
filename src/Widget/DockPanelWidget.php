<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * A panel that can own the bottom dock's slot: the terminal, the list tools and
 * the debugger. Adds to {@see OverlayWidget}'s open/conceal lifecycle the four
 * things {@see \Porta\Workbench\Dock\BottomDock}, {@see DockTabsWidget} and the mouse
 * router need from whichever panel is currently showing.
 *
 * It exists as a class rather than an interface because the callers need an
 * `AbstractWidget`: the router hands the panel to `Tui::setFocus()` and to the
 * hit-tester, and the layout compares it against the focused widget. Naming the
 * shared type here is what lets `BottomDock::visiblePanel()` stop returning a
 * three-way union — which every caller had to either re-spell or downcast.
 */
abstract class DockPanelWidget extends OverlayWidget
{
    /** This panel's right-aligned text in the dock tab strip. */
    abstract public function statusText(): string;

    /** How many body rows the dock has allotted this panel. */
    abstract public function setVisibleRows(int $rows): void;

    /**
     * How many it has NOW — the same number {@see setVisibleRows()} takes.
     *
     * The pair exists because the renderer's rect cannot answer it reliably:
     * the rect reports the same figure, but only as of the last FRAME, so a
     * caller that resizes twice before one lands reads the pre-resize height
     * twice, and a caller that resizes before anything has rendered reads
     * nothing at all. Anything that has to reproduce a height exactly — a
     * nudge, a maximise and its restore — reads it here.
     */
    abstract public function visibleRows(): int;

    /**
     * A wheel notch over the panel body, in rows; negative scrolls up.
     *
     * Not `scrollBy()` from {@see Support\WheelScrollable}: the terminal's
     * scrollback runs the other way (wheel up goes INTO history), so the two
     * meanings must not share a name.
     */
    abstract public function wheelBy(int $rows): void;

    /** A click on body row $row, 0-based within the panel. */
    abstract public function clickRow(int $row): void;
}
