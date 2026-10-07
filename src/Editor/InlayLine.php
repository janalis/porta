<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Where a line's inlay hints land on screen, and the arithmetic between the
 * screen and the document that they shift: the ONE mapping the renderer, the
 * caret, a click, a drag and a hover all read, so none of them can disagree
 * about which cell a character is drawn in.
 *
 * A hint is placed at the display column of its anchor (clamped to the end of
 * the line) and is drawn BEFORE the character there; the caret at that column
 * is drawn after it. Equal columns keep the order the renderer always used:
 * the later-listed hint lands left.
 *
 * Under soft wrap the text never reflows for a hint (wrapping is measured on
 * the document line, {@see WrapLayout}): segment k shows exactly the raw
 * columns `[k·w, (k+1)·w)`, and a segment's hints are placed only while they
 * fit in the cells its text leaves free. A hint that does not fit is not
 * drawn, so a hint can never push code off its row. Only the last segment of
 * a wrapped line has free cells; the full ones before it show none.
 */
final readonly class InlayLine
{
    /**
     * @param list<array{hint: InlayHint, displayCol: int}> $placed sorted by screen order
     */
    private function __construct(
        private array $placed,
    ) {}

    /** A line with nothing placed on it: every mapping is the identity. */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<InlayHint> $hints     the hints to show on this line
     * @param int|null        $wrapWidth the segment width under soft wrap; null when unwrapped
     */
    public static function place(array $hints, Document $doc, int $line, ?int $wrapWidth = null): self
    {
        if ([] === $hints) {
            return self::none();
        }
        $lineWidth = $doc->lineDisplayWidth($line);
        $ordered = [];
        foreach ($hints as $i => $hint) {
            $ordered[] = [\min($doc->displayColAt($line, $hint->col), $lineWidth), -$i, $hint];
        }
        \usort($ordered, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $wrapped = null !== $wrapWidth && $wrapWidth > 0;
        $lastSegment = $wrapped ? new WrapLayout($doc)->rowsFor($line, $wrapWidth) - 1 : 0;
        // Free cells per segment: only the last one is short of a full row.
        $slack = $wrapped ? [$lastSegment => $wrapWidth - ($lineWidth - WrapLayout::offsetOf($lastSegment, $wrapWidth))] : [];
        $placed = [];
        foreach ($ordered as [$displayCol, , $hint]) {
            if ($wrapped) {
                $segment = \min(WrapLayout::segmentOf($displayCol, $wrapWidth), $lastSegment);
                $free = $slack[$segment] ?? 0;
                if ($hint->width() > $free) {
                    continue;
                }
                $slack[$segment] = $free - $hint->width();
            }
            $placed[] = ['hint' => $hint, 'displayCol' => $displayCol];
        }

        return new self($placed);
    }

    /** @return list<array{hint: InlayHint, displayCol: int}> the drawn hints, left to right */
    public function placed(): array
    {
        return $this->placed;
    }

    /** The screen column a document display column is drawn at: after every hint at or before it. */
    public function screenCol(int $displayCol): int
    {
        $screen = $displayCol;
        foreach ($this->placed as $at) {
            if ($at['displayCol'] > $displayCol) {
                break;
            }
            $screen += $at['hint']->width();
        }

        return $screen;
    }

    /** The screen column wrapped segment $segment starts at: its raw offset plus the hints before it. */
    public function rowStart(int $segment, int $width): int
    {
        $offset = WrapLayout::offsetOf($segment, $width);
        $screen = $offset;
        foreach ($this->placed as $at) {
            if ($at['displayCol'] >= $offset) {
                break;
            }
            $screen += $at['hint']->width();
        }

        return $screen;
    }

    /**
     * The document display column a screen column shows, and the hint drawn
     * there if it is one. Anywhere on a hint — its margins or its label —
     * answers the hint's anchor, the character it sits before.
     *
     * @return array{displayCol: int, hint: InlayHint|null}
     */
    public function hit(int $screen): array
    {
        $shift = 0;
        foreach ($this->placed as $at) {
            $start = $at['displayCol'] + $shift;
            if ($screen < $start) {
                break;
            }
            $width = $at['hint']->width();
            if ($screen < ($start + $width)) {
                return ['displayCol' => $at['displayCol'], 'hint' => $at['hint']];
            }
            $shift += $width;
        }

        return ['displayCol' => \max(0, $screen - $shift), 'hint' => null];
    }
}
