<?php

declare(strict_types=1);

namespace Porta\Widget\Support;

/**
 * The wheel IS the arrow keys, for the panes whose selection drives something
 * else. A notch feeds $delta up/down presses through the widget's own
 * handleInput, so every side effect the keyboard has fires identically: the
 * git changes list reloads its diff, the settings nav switches the shown
 * section, the tree previews.
 *
 * That indirection reads like a hack — the three mouse routers each hand-rolled
 * it, synthesising `\e[A`/`\e[B` in a loop — but it is the contract. A pane
 * here has no viewport to move independently of its selection; scrolling one
 * without the other would silently drop the effect the keyboard produces.
 *
 * Panes that DO have a real viewport (the editor, the diff, the help viewer,
 * the terminal) implement {@see WheelScrollable::scrollBy()} directly and move
 * their window instead.
 */
trait ArrowScrollTrait
{
    abstract public function handleInput(string $data): void;

    /** Scroll by $delta rows (negative = up) — as $delta arrow presses. */
    public function scrollBy(int $delta): void
    {
        $key = $delta < 0 ? "\x1b[A" : "\x1b[B";
        for ($i = 0; $i < \abs($delta); ++$i) {
            $this->handleInput($key);
        }
    }
}
