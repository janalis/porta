<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * How many screen rows each document line occupies when soft wrap is on, and
 * the arithmetic between a line and its segments.
 *
 * Pure over a {@see Document} and a content width — no state of its own beyond
 * a per-width memo, so a width change or an edit is answered by dropping it
 * rather than by invalidating anything.
 *
 * **Wrapping is measured on the DOCUMENT line's display width**, not on the
 * decorated one. Inlay hints and inline diagnostics ride in the same row and
 * would otherwise change where the text breaks as they arrive and go — the
 * text would reflow because a hint appeared, which is the opposite of what a
 * reader wants. A trailing decoration clips at the row edge as it does when a
 * line is too long; an inlay hint is drawn only where the row has free cells
 * for it ({@see InlayLine}), so it can never push code off its row.
 *
 * A wrapped SEGMENT is rendered by the same primitive horizontal scroll uses:
 * `renderContentLine()` slices a decorated line at a display column, so
 * segment N is that slice at `N * width`. Wrap and hScroll are one knob read
 * two ways, which is why they are mutually exclusive.
 */
final class WrapLayout
{
    /** @var array<int, int> line => rows it occupies, for {@see $width} */
    private array $rows = [];

    private int $width = 0;

    private int $version = -1;

    public function __construct(
        private readonly Document $document,
    ) {}

    /**
     * Rows this line needs at the given content width — at least one, and one
     * more for every full width of text past the first.
     *
     * A width of zero or less means there is nothing to wrap into (a pane
     * squeezed to nothing), so every line is one row and the caller renders
     * whatever fits.
     */
    public function rowsFor(int $line, int $width): int
    {
        if ($width <= 0) {
            return 1;
        }
        $this->refresh($width);

        // A line no wider than $width (an empty one too) is one row.
        return $this->rows[$line] ??= \max(1, (int) \ceil($this->document->lineDisplayWidth($line) / $width));
    }

    /**
     * The display column a segment starts at — the offset `renderContentLine()`
     * slices from, exactly where it would otherwise be handed `$hScroll`.
     */
    public static function offsetOf(int $segment, int $width): int
    {
        return \max(0, $segment) * \max(0, $width);
    }

    /**
     * Which segment of its line a display column falls in — the inverse, for
     * putting the caret on the right row.
     */
    public static function segmentOf(int $displayCol, int $width): int
    {
        return $width <= 0 ? 0 : \intdiv(\max(0, $displayCol), $width);
    }

    /** Drop the memo when the width changed or the document moved on. */
    private function refresh(int $width): void
    {
        $version = $this->document->version;
        if ($width === $this->width && $version === $this->version) {
            return;
        }
        $this->rows = [];
        $this->width = $width;
        $this->version = $version;
    }
}
