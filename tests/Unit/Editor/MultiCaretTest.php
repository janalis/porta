<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\Document;
use Porta\Editor\MultiCaret;

/**
 * Direct construction (no widget) tests for the boundary and side-effect
 * behavior that {@see \Porta\Tests\Unit\Widget\CodeEditorMultiCaretTest} does
 * not reach: the alive() filter's exact `>=`/`&&`/`===` edges, the redraw
 * closure being invoked exactly where expected, and the dedupe safety net.
 */
final class MultiCaretTest extends TestCase
{
    /** @var list<string> closure invocation log, in call order */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    private function invalidate(): \Closure
    {
        return function (): void {
            $this->calls[] = 'invalidate';
        };
    }

    private function noop(): \Closure
    {
        return static function (): void {};
    }

    // --- alive() filter boundaries (line >= lineCount) ------------------------

    public function testAliveDropsACaretExactlyAtTheLineCountBoundary(): void
    {
        $doc = new Document("aaa\nbbb\n"); // 3 lines (0, 1, and the trailing empty)
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        // The caret's own line is exactly at the (invalid) line count; its
        // anchor is on a perfectly valid line. Only the `line` clause can drop it.
        $mc->setExtras([new Caret(line: 3, col: 0, anchorLine: 0, anchorCol: 0)]);
        $this->assertSame([], $mc->carets(), 'a caret whose line equals the line count is out of range');

        // Symmetric case: the anchor is the one exactly at the boundary.
        $mc->setExtras([new Caret(line: 0, col: 0, anchorLine: 3, anchorCol: 0)]);
        $this->assertSame([], $mc->carets(), 'a caret whose anchor line equals the line count is out of range too');
    }

    // --- alive() filter: reuse vs. reconstruct (col === / anchorCol ===) ------

    public function testAliveReturnsTheSameCaretInstanceWhenNoClampingIsNeeded(): void
    {
        $doc = new Document("abc\n");
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        $caret = Caret::point(0, 1); // well within "abc"; no clamping needed
        $mc->setExtras([$caret]);

        $this->assertSame($caret, $mc->carets()[0], 'a caret needing no clamping is returned as the same instance');
    }

    public function testAliveClampsAnOutOfRangeColumnEvenWhenTheAnchorNeedsNoClamping(): void
    {
        $doc = new Document("ab\n"); // line 0 is 2 bytes long
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        // col is way past the end of the line; anchorCol is already in range.
        $mc->setExtras([new Caret(line: 0, col: 10, anchorLine: 0, anchorCol: 0)]);

        $this->assertSame(2, $mc->carets()[0]->col, 'the out-of-range column is clamped regardless of the anchor');
    }

    // --- setExtras() side effect -----------------------------------------------

    public function testSetExtrasRequestsARedraw(): void
    {
        $doc = new Document("a\n");
        $mc = new MultiCaret($doc, $this->invalidate(), $this->noop());

        $mc->setExtras([Caret::point(0, 0)]);

        $this->assertContains('invalidate', $this->calls);
    }

    // --- addVertically(): bounds check + its side effects -----------------------

    public function testAddVerticallyPastTheLastLineIsANoOpThatDoesNotRedraw(): void
    {
        $doc = new Document("a\nb\n"); // lines 0, 1, and the trailing empty line 2
        $doc->setCursor($doc->getLineCount() - 1, 0); // sit on the actual last line
        $mc = new MultiCaret($doc, $this->invalidate(), $this->noop());

        $mc->addVertically(1); // one past the last line

        $this->assertSame([], $this->calls, 'moving past the last line must not request a redraw');
        $this->assertFalse($mc->hasMultiple());
    }

    public function testAddVerticallySucceedingRequestsExactlyOneRedraw(): void
    {
        $doc = new Document("a\nb\n");
        $mc = new MultiCaret($doc, $this->invalidate(), $this->noop());

        $mc->addVertically(1); // line 0 -> line 1, a valid move

        $this->assertSame(['invalidate'], $this->calls);
    }

    public function testAddVerticallyRoundTripDedupesTheReturnedCaret(): void
    {
        $doc = new Document("aaa\nbbb\nccc\n");
        $doc->setCursor(1, 0);
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        $mc->addVertically(-1); // primary -> line 0, extra left at line 1
        $mc->addVertically(1); // primary -> line 1 again: collides with that extra

        $this->assertCount(1, $mc->carets(), 'the round trip must not leave a duplicate caret behind');
    }

    // --- addNextOccurrence(): forward-only scan + its side effects --------------

    public function testAddNextOccurrenceSkipsTheOccurrenceAtTheCursorItself(): void
    {
        $doc = new Document("foo foo foo\n");
        $doc->setCursor(0, 0); // sitting exactly at the first occurrence's start
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        $mc->addNextOccurrence();

        $this->assertSame(0, $doc->cursorLine);
        $this->assertSame(7, $doc->cursorCol, 'the occurrence at the cursor is not itself "next"; the scan advances past it');
    }

    public function testAddNextOccurrenceRequestsExactlyOneRedraw(): void
    {
        $doc = new Document("foo foo\n");
        $mc = new MultiCaret($doc, $this->invalidate(), $this->noop());

        $mc->addNextOccurrence();

        $this->assertSame(['invalidate'], $this->calls);
    }

    public function testAddNextOccurrenceDedupesAgainstAPreexistingCaretAtTheOldPrimarySpot(): void
    {
        $doc = new Document("foo foo foo\n");
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        // An extra already sits exactly where the (old) primary is about to be
        // pushed to; growing the set must not leave that position doubled up.
        $mc->setExtras([Caret::point(0, 0)]);

        $mc->addNextOccurrence();

        $this->assertCount(1, $mc->carets());
    }

    // --- selectRanges (the find bar's Select All Occurrences) -----------------

    public function testSelectRangesPutsThePrimaryOnTheDocumentAndTheRestAsExtras(): void
    {
        $doc = new Document("foo x foo\nfoo\n");
        $mc = new MultiCaret($doc, $this->invalidate(), $this->noop());
        $mc->setExtras([Caret::point(1, 0)]); // replaced wholesale, not added to
        $this->calls = [];

        $mc->selectRanges([new Caret(0, 3, 0, 0), new Caret(0, 9, 0, 6), new Caret(1, 3, 1, 0)], primary: 1);

        $this->assertSame(['startLine' => 0, 'startCol' => 6, 'endLine' => 0, 'endCol' => 9], $doc->getSelectionRange(), 'the chosen one is the Document\'s own selection');
        $this->assertSame([0, 9], [$doc->cursorLine, $doc->cursorCol], 'its caret at the match end');
        $this->assertEquals([new Caret(0, 3, 0, 0), new Caret(1, 3, 1, 0)], $mc->carets(), 'the others, in order, keeping their selections');
        $this->assertSame(['invalidate'], $this->calls, 'one redraw');
    }

    public function testSelectRangesDropsAnExtraOnThePrimarysSpot(): void
    {
        $doc = new Document("foo foo\n");
        $mc = new MultiCaret($doc, $this->noop(), $this->noop());

        $mc->selectRanges([new Caret(0, 3, 0, 0), new Caret(0, 7, 0, 4), new Caret(0, 3, 0, 0)], primary: 0);

        $this->assertEquals([new Caret(0, 7, 0, 4)], $mc->carets(), 'the primary is not doubled as an extra');
    }
}
