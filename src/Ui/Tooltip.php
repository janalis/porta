<?php

declare(strict_types=1);

namespace Porta\Ui;

/**
 * What the element under the pointer says about itself: its words, and the
 * box it occupies — a span of columns over one row or a few (a boxed toggle
 * is three) — which the card's arrow points at.
 *
 * A widget answers in its own coordinates and {@see moved()} carries the
 * answer to the screen. The key is the element's identity, not its text: the
 * dwell before a card shows is keyed on it, because a pointer is never quite
 * still and a timer restarted on every jitter never fires.
 */
final readonly class Tooltip
{
    /**
     * @param string $key      the element's identity, stable across frames (`find:regex`, `tab:3`)
     * @param string $text     translated words, one line per `\n`
     * @param int    $row      the element's first row
     * @param int    $colStart the span's first column
     * @param int    $colEnd   one past its last column
     * @param int    $rows     how many rows the element spans
     */
    public function __construct(
        public string $key,
        public string $text,
        public int $row,
        public int $colStart,
        public int $colEnd,
        public int $rows = 1,
    ) {}

    /** The same tooltip, $rows down and $cols across — a widget's answer placed on the screen. */
    public function moved(int $rows, int $cols): self
    {
        return new self($this->key, $this->text, $this->row + $rows, $this->colStart + $cols, $this->colEnd + $cols, $this->rows);
    }
}
