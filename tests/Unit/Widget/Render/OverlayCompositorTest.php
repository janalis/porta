<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\SgrReads;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class OverlayCompositorTest extends TestCase
{
    public function testCompositesPopupAndKeepsTheLineExactlyTheTerminalWidth(): void
    {
        $line = \str_repeat(' ', times: 20);
        $popup = "\x1b[7m[popup]\x1b[0m"; // 7 visible columns

        $out = OverlayCompositor::into($line, 5, 7, 20, $popup);

        $this->assertSame(20, AnsiUtils::visibleWidth($out), 'the row stays exactly the terminal width');
        $this->assertStringContainsString('[popup]', AnsiUtils::stripAnsiCodes($out));
    }

    /**
     * 中 at columns 5-6 of the row straddles the popup's right edge (col 6).
     * Its visible half is a blank, and `gh` stays at columns 7-8 — a vendor
     * slice from column 6 dropped the glyph and slid the tail one column left.
     */
    public function testATailGlyphHalfCoveredByThePopupDoesNotShiftTheTail(): void
    {
        $out = OverlayCompositor::into('abcde中gh', 2, 4, 10, '[pp]');

        $this->assertSame('ab[pp] gh ', AnsiUtils::stripAnsiCodes($out));
    }

    /** The same glyph straddling the popup's LEFT edge: the head keeps its width too. */
    public function testAHeadGlyphHalfCoveredByThePopupDoesNotShiftThePopup(): void
    {
        $out = OverlayCompositor::into('a中defgh', 2, 3, 9, '[p]');

        $this->assertSame('a [p]fgh ', AnsiUtils::stripAnsiCodes($out));
    }

    public function testClippedPopupBackgroundDoesNotBleedIntoTheTail(): void
    {
        // A popup slice whose own trailing reset was clipped off (as happens when
        // an overlay spills across a widget boundary): the background must not
        // bleed across the tail. Regression: a menu spilling into a blank editor
        // pane painted its box background across the whole pane.
        $line = \str_repeat(' ', times: 40); // blank pane (no styling of its own)
        $clippedPopup = "\x1b[48;5;236mBOX"; // opens a background, never resets

        $out = OverlayCompositor::into($line, 4, 3, 40, $clippedPopup);

        // The background active over the tail (a blank column past the popup) must
        // be the default, not the popup's — i.e. a reset separates them.
        $this->assertSame('default', SgrReads::backgroundAt($out, 20), 'the popup background must not bleed across the tail');
        $this->assertSame(40, AnsiUtils::visibleWidth($out));
    }

    /**
     * A marker sitting only in the POPUP (not the head) must still trigger
     * stripping the tail's re-emitted duplicate — the docblock is explicit
     * that "the head (or the popup itself)" both count.
     */
    public function testACaretMarkerInThePopupAloneStillStripsTheTailsDuplicate(): void
    {
        $marker = AnsiUtils::cursorMarker();
        // The line's own marker sits exactly at the tail's start column, so
        // slicing at that boundary re-emits a duplicate at the tail's front.
        $line = \str_repeat('.', times: 8).$marker.\str_repeat('.', times: 12);
        $popup = $marker.'XXX'; // the popup carries a marker of its own; the head does not

        $out = OverlayCompositor::into($line, 5, 3, 20, $popup);

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'only the popup\'s marker survives — the tail\'s duplicate is stripped');
    }

    /**
     * A marker genuinely inside the tail (not a re-emitted duplicate of one
     * in the head or popup) must be left alone — stripping is only correct
     * when the head/popup side has a marker of its own to be the duplicate of.
     */
    public function testACaretMarkerGenuinelyInsideTheTailSurvivesUntouched(): void
    {
        $marker = AnsiUtils::cursorMarker();
        // Well past the tail's start column — not a boundary re-emission, just
        // literally where the marker sits in the original line.
        $line = \str_repeat('.', times: 10).$marker.\str_repeat('.', times: 10);

        $out = OverlayCompositor::into($line, 2, 3, 20, 'XXX');

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'the tail\'s own marker is not a duplicate — it must not be stripped');
    }

    /** The column the hardware cursor lands on in $line, or null when no marker survived. */
    private static function caretColumn(string $line): ?int
    {
        $at = \strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);

        return false === $at ? null : AnsiUtils::visibleWidth(\substr($line, offset: 0, length: $at));
    }

    /**
     * The caret right where an overlay starts — typing after `$order->` with
     * the completion popup's shadow cast along that row. The head slice ends
     * at the overlay's column and drops a marker sitting exactly there, and the
     * tail re-emitted it after the overlay: the terminal cursor jumped from
     * column 54 to column 103 of the screen.
     */
    public function testACaretAtTheOverlaysFirstColumnStaysThere(): void
    {
        $line = '$order->'.AnsiUtils::cursorMarker().\str_repeat(' ', times: 12);

        $out = OverlayCompositor::into($line, 8, 5, 20, '#####');

        $this->assertSame(8, self::caretColumn($out), 'the caret keeps its column');
        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX));
        $this->assertSame(20, AnsiUtils::visibleWidth($out));
    }

    /** A caret the overlay covers is hidden by it — never moved past it. */
    public function testACaretTheOverlayCoversIsNotMovedPastIt(): void
    {
        $line = \str_repeat('.', times: 10).AnsiUtils::cursorMarker().\str_repeat('.', times: 10);

        $out = OverlayCompositor::into($line, 8, 5, 20, '#####');

        $this->assertNull(self::caretColumn($out), 'under the overlay, the caret is not shown at the overlay\'s far edge');
    }

    /** A caret just past the overlay is the tail's own, and stays — not a duplicate. */
    public function testACaretJustPastTheOverlayStays(): void
    {
        $line = \str_repeat('.', times: 13).AnsiUtils::cursorMarker().\str_repeat('.', times: 7);

        $out = OverlayCompositor::into($line, 8, 5, 20, '#####');

        $this->assertSame(13, self::caretColumn($out));
    }

    /** A popup with a caret of its own over the row's: the popup's is the one shown. */
    public function testThePopupsCaretWinsOverTheRowsAtItsFirstColumn(): void
    {
        $line = \str_repeat('.', times: 8).AnsiUtils::cursorMarker().\str_repeat('.', times: 12);

        $out = OverlayCompositor::into($line, 8, 5, 20, '##'.AnsiUtils::cursorMarker().'###');

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX));
        $this->assertSame(10, self::caretColumn($out), 'inside the popup, where it put it');
    }

    /** A caret before the overlay is the head's, once. */
    public function testACaretBeforeTheOverlayStaysOnce(): void
    {
        $line = \str_repeat('.', times: 3).AnsiUtils::cursorMarker().\str_repeat('.', times: 17);

        $out = OverlayCompositor::into($line, 8, 5, 20, '#####');

        $this->assertSame(3, self::caretColumn($out));
        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX));
    }

    /** The tail's blank for a half-covered glyph is painted in the row's own background. */
    public function testATailGlyphsBlankKeepsTheRowBackground(): void
    {
        $out = OverlayCompositor::into("\e[44mabcde中gh\e[0m", 2, 4, 10, '[pp]');

        $tail = \substr($out, (int) \strpos($out, needle: '[pp]') + 4);
        $this->assertMatchesRegularExpression('/^(?:\e\[[0-9;:]*m)*?\e\[44m gh/', $tail, 'the background opens before the blank');
    }

    public function testAnOverlayOverAccentedTextIsNotCutShort(): void
    {
        // The symptom, under symfony/tui before d0adb8b: a popup spliced over a
        // row with a decomposed accent lost a column per mark and was padded
        // blank to make up the width.
        $row = "\x1b[32mcafe\u{0301}\x1b[0m au lait, cre\u{0300}me bru\u{0302}le\u{0301}e".\str_repeat(' ', times: 10);
        $columns = AnsiUtils::visibleWidth($row);

        $composed = OverlayCompositor::into($row, 12, 6, $columns, '[MENU]');

        $this->assertSame($columns, AnsiUtils::visibleWidth($composed));
        $plain = AnsiUtils::stripAnsiCodes($composed);
        $this->assertSame(12, AnsiUtils::visibleWidth(\substr($plain, offset: 0, length: (int) \strpos($plain, needle: '[MENU]'))), 'the popup starts at its own column');
        // Everything before the popup survives: the old vendor dropped the `t`
        // and padded a blank in its place, which kept the popup's column
        // honest and hid the loss.
        $this->assertStringContainsString("cafe\u{0301} au lait[MENU]", $plain);
    }
}
