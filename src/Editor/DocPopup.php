<?php

declare(strict_types=1);

namespace Porta\Editor;

use Symfony\Component\Tui\Input\Keybindings;

/**
 * A symbol's documentation, read in place: the text the server sent for the
 * symbol at the caret, in a read-only popup anchored under it — a browser's
 * tooltip, where the list overlay it replaced was a picker with nothing to
 * pick. It scrolls (↑/↓, PgUp/PgDn, the wheel) within what the renderer
 * reports it can, and any other key closes it and then does what it does.
 */
final class DocPopup
{
    /** How far the text is scrolled, in wrapped rows. */
    public int $scroll = 0;

    /**
     * The popup as last drawn — [top, col, width, height] in pane cells — and
     * how far it can scroll; null until it has been drawn.
     *
     * @var array{rect: array{int, int, int, int}, maxScroll: int}|null
     */
    public ?array $drawn = null;

    public function __construct(
        public readonly string $text,
    ) {}

    /**
     * A key while the popup is up: true when it was the popup's (a scroll, or
     * Esc, which closes it), false when it closes the popup and goes on to do
     * its own thing.
     */
    public function handleKey(string $keys, Keybindings $kb): bool
    {
        $page = \max(1, ($this->drawn['rect'][3] ?? 3) - 2);
        $delta = match (true) {
            $kb->matches($keys, 'cursor_up') => -1,
            $kb->matches($keys, 'cursor_down') => 1,
            $kb->matches($keys, 'page_up') => -$page,
            $kb->matches($keys, 'page_down') => $page,
            default => 0,
        };
        if (0 === $delta) {
            return false;
        }
        $this->scrollBy($delta);

        return true;
    }

    public function scrollBy(int $delta): void
    {
        $this->scroll = \max(0, \min($this->drawn['maxScroll'] ?? 0, $this->scroll + $delta));
    }

    /** Whether a pane cell is on the popup, frame included. */
    public function covers(int $relRow, int $relCol): bool
    {
        if (null === $this->drawn) {
            return false;
        }
        [$top, $col, $width, $height] = $this->drawn['rect'];

        return $relRow >= $top && $relRow < ($top + $height) && $relCol >= $col && $relCol < ($col + $width);
    }
}
