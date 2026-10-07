<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayLine;

/**
 * The one mapping between a line's document columns and the screen columns
 * its inlay hints shift them to. Every boundary here is a `<` against a `<=`:
 * a hint is drawn BEFORE the character at its column, the caret at that column
 * after it, and a click anywhere on a hint answers that character.
 */
final class InlayLineTest extends TestCase
{
    public function testNothingPlacedIsTheIdentity(): void
    {
        $line = InlayLine::none();

        $this->assertSame([], $line->placed());
        $this->assertSame(7, $line->screenCol(7));
        $this->assertSame(9, $line->rowStart(1, 9));
        $this->assertSame(['displayCol' => 4, 'hint' => null], $line->hit(4));
        $this->assertSame(['displayCol' => 0, 'hint' => null], $line->hit(-3), 'a column left of the text is its start');
    }

    public function testPlacingNoHintsPlacesNothing(): void
    {
        $this->assertSame([], InlayLine::place([], new Document("abc\n"), 0)->placed());
    }

    public function testTheCaretIsDrawnAfterAHintAtItsColumnAndBeforeOneAfterIt(): void
    {
        // `n: ` (with its margin) before the 4 of f(42, $y).
        $line = InlayLine::place([new InlayHint(2, 'n:', padRight: true)], new Document("f(42, \$y);\n"), 0);

        $this->assertSame(1, $line->screenCol(1), 'before the hint: where it always was');
        $this->assertSame(5, $line->screenCol(2), 'AT the hint: after it');
        $this->assertSame(6, $line->screenCol(3), 'after it: shifted by its width');
    }

    public function testEveryCellOfAHintAnswersItsAnchor(): void
    {
        $hint = new InlayHint(2, 'n:', padLeft: true, padRight: true); // four cells: 2..5
        $line = InlayLine::place([$hint], new Document("f(42, \$y);\n"), 0);

        $this->assertSame(['displayCol' => 1, 'hint' => null], $line->hit(1), 'the cell before it is the text');
        foreach ([2, 3, 4, 5] as $screen) {
            $this->assertSame(['displayCol' => 2, 'hint' => $hint], $line->hit($screen), "cell {$screen} is the hint");
        }
        $this->assertSame(['displayCol' => 2, 'hint' => null], $line->hit(6), 'the cell after it is the character it sits before');
        $this->assertSame(['displayCol' => 3, 'hint' => null], $line->hit(7));
    }

    public function testHintsAtOneColumnKeepTheRenderersOrderAndShiftTogether(): void
    {
        $a = new InlayHint(2, 'A');
        $b = new InlayHint(2, 'BB');
        $line = InlayLine::place([$a, $b], new Document("abcd\n"), 0);

        $this->assertSame([$b, $a], \array_column($line->placed(), 'hint'), 'the later-listed hint lands left');
        $this->assertSame(5, $line->screenCol(2));
        $this->assertSame(['displayCol' => 2, 'hint' => $b], $line->hit(3));
        $this->assertSame(['displayCol' => 2, 'hint' => $a], $line->hit(4));
    }

    public function testAStaleColumnPastTheLineIsClampedToItsEnd(): void
    {
        $line = InlayLine::place([new InlayHint(40, 'x')], new Document("abc\n"), 0);

        $this->assertSame(3, $line->placed()[0]['displayCol']);
    }

    /** A wide glyph on each side: the hint lands between them, not inside one. */
    public function testWideGlyphsOnEitherSideOfAHint(): void
    {
        // あ is three bytes and two cells; the hint sits before the second one.
        $hint = new InlayHint(3, 'h');
        $line = InlayLine::place([$hint], new Document("ああ\n"), 0);

        $this->assertSame(2, $line->placed()[0]['displayCol']);
        $this->assertSame(['displayCol' => 2, 'hint' => $hint], $line->hit(2));
        $this->assertSame(['displayCol' => 2, 'hint' => null], $line->hit(3), 'the second glyph starts after the hint');
        $this->assertSame(5, $line->screenCol(4), 'the end of the line moved by one');
    }

    public function testARowStartsPastTheHintsBeforeItsOffsetButNotThoseAtIt(): void
    {
        $line = InlayLine::place([new InlayHint(1, 'ab'), new InlayHint(4, 'c')], new Document("xxxxxxxx\n"), 0);

        $this->assertSame(6, $line->rowStart(1, 4), 'the hint at 1 is before the offset 4');
        $this->assertSame(0, $line->rowStart(0, 4), 'segment 0 starts at 0');
    }

    /**
     * Wrapped, text never moves for a hint: a segment shows its own raw
     * columns, so only the cells its text leaves free can take hints — and
     * only the last segment of a line has any.
     */
    public function testWrappedOnlyHintsThatFitTheFreeCellsArePlaced(): void
    {
        $doc = new Document(\str_repeat('x', times: 10)."\n"); // rows of 4: 4 + 4 + 2, two cells free at the end

        $middle = new InlayHint(1, 'ab');
        $last = new InlayHint(9, 'cd');
        $this->assertSame([$last], \array_column(InlayLine::place([$middle, $last], $doc, 0, 4)->placed(), 'hint'), 'a full segment takes none');

        $this->assertSame([], InlayLine::place([new InlayHint(9, 'cde')], $doc, 0, 4)->placed(), 'one that does not fit is left out');

        $one = new InlayHint(8, 'a');
        $two = new InlayHint(9, 'b');
        $three = new InlayHint(10, 'c');
        $this->assertSame([$one, $two], \array_column(InlayLine::place([$one, $two, $three], $doc, 0, 4)->placed(), 'hint'), 'greedily, left to right, until the cells run out');
    }

    public function testAHintOnASegmentBoundaryOpensTheNextSegment(): void
    {
        $doc = new Document(\str_repeat('x', times: 10)."\n");
        $line = InlayLine::place([new InlayHint(8, 'ab')], $doc, 0, 4);

        $this->assertCount(1, $line->placed(), 'column 8 is the last segment, which has room');
        $this->assertSame(8, $line->rowStart(2, 4), 'and it is drawn on that row, not before it');
    }

    public function testAShortWrappedLineTakesHintsUpToTheRowWidth(): void
    {
        $doc = new Document("ab\n");

        $this->assertCount(1, InlayLine::place([new InlayHint(1, 'cd')], $doc, 0, 4)->placed(), 'exactly the two free cells');
        $this->assertSame([], InlayLine::place([new InlayHint(1, 'cde')], $doc, 0, 4)->placed(), 'one more would clip the text');
        $this->assertCount(1, InlayLine::place([new InlayHint(1, 'cde')], $doc, 0)->placed(), 'unwrapped, every hint is placed');
    }
}
