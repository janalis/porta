<?php

declare(strict_types=1);

namespace Porta\Input;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Double/triple-click detection: counts consecutive presses landing in the same
 * cell within a short window, capped at a triple-click. Held by {@see MouseRouter}
 * and extracted so the routing logic stays free of this click-timing state (the
 * only mutable state the router carried that no routing method touched).
 *
 * The editor is the primary consumer — only text has word and line
 * granularity, so only the editor has that multiplicity to express — plus the
 * editor tab strip, where a double-click reveals the file in the sidebar tree
 * (a second, non-destructive act layered over the first click's tab switch).
 * Every LIST in the IDE still activates on the FIRST click: a search result, a
 * tree node and a go-to-file row exist to be jumped to, and select-without-
 * activate is already the keyboard's job (arrows, then Enter). Adopting
 * double-click there would put WINDOW_SECONDS of latency on the most common
 * action in the IDE and give every list a second idiom for one act. The one
 * pane where select-vs-activate genuinely differs — the git changes list,
 * whose selection loads a diff — solves it without multiplicity (see
 * a list widget's `hoverRow()`).
 */
final class ClickCounter
{
    private const float WINDOW_SECONDS = 0.4;

    private float $lastTime = 0.0;
    private int $lastRow = -1;
    private int $lastCol = -1;
    private int $count = 0;

    // Optional trailing (the StatusBarWidget clock idiom): the router news
    // this with the wall clock; tests hand in a MockClock and own the window.
    public function __construct(
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /** The click multiplicity (1, 2 or 3) for a press at ($row, $col) right now. */
    public function countAt(int $row, int $col): int
    {
        $now = (float) $this->clock->now()->format('U.u');
        $sameCell = ($now - $this->lastTime) < self::WINDOW_SECONDS && $row === $this->lastRow && $col === $this->lastCol;
        $this->count = $sameCell ? min($this->count + 1, 3) : 1;
        $this->lastTime = $now;
        $this->lastRow = $row;
        $this->lastCol = $col;

        return $this->count;
    }
}
