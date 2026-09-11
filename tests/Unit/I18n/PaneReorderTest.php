<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\PaneReorder;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class PaneReorderTest extends TestCase
{
    private const string SHALOM = "\u{05E9}\u{05DC}\u{05D5}\u{05DD}"; // 4 columns

    public function testReordersEachPaneWithinItsOwnColumns(): void
    {
        // [ Hebrew chrome (0..3, RTL) | LTR code (4..15) ]
        $line = self::SHALOM.'<?php echo;';
        $line = AnsiUtils::truncateToWidth($line, 16, pad: true);
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => false],
            ['start' => 4, 'width' => 12, 'ltr' => true],
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);
        $plain = AnsiUtils::stripAnsiCodes($out);

        // Code stays verbatim (present unchanged → not mirrored/reordered);
        // Hebrew is reversed within its own span.
        $this->assertStringContainsString('<?php echo;', $plain, 'code pane untouched, not mirrored');
        $this->assertStringContainsString("\u{05DD}\u{05D5}\u{05DC}\u{05E9}", $plain, 'chrome reversed to visual order');
        $this->assertSame(16, AnsiUtils::visibleWidth($out), 'width preserved');
    }

    /** A wide character straddling a run's column boundary must not spill into the next pane. */
    public function testAWideCharacterStraddlingARunBoundaryIsDroppedNotOverflowed(): void
    {
        $line = "ab\u{4E2D}cd"; // 'a','b' (cols 0,1), a 2-column CJK char (cols 2,3), 'c','d' (cols 4,5)
        $runs = [
            ['start' => 0, 'width' => 2, 'ltr' => true],
            ['start' => 2, 'width' => 1, 'ltr' => true], // too narrow for the wide char to fit
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);

        $this->assertSame(2, AnsiUtils::visibleWidth($out), 'the straddling wide char is dropped, not overflowed into extra columns');
    }

    public function testLogicalColumnAtTheExactRunBoundarySelectsTheNextRun(): void
    {
        // visualCol 4 is the first column PAST run 1's [0,4) span — it must
        // select run 2, not fall into run 1 by an off-by-one on the boundary.
        $line = 'abcd'.self::SHALOM;
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => true],
            ['start' => 4, 'width' => 4, 'ltr' => false],
        ];

        $this->assertSame(7, new PaneReorder()->logicalColumn($line, 4, $runs), 'run 2 (RTL) claims the boundary column, not run 1');
    }

    public function testLogicalColumnOnAnLtrRunIsIdentityEvenForRtlScriptContent(): void
    {
        // A run declared ltr:true must map identically regardless of its
        // actual text — the flag is the pane's layout contract, not a
        // property the content needs to satisfy.
        $runs = [['start' => 0, 'width' => 4, 'ltr' => true]];

        $this->assertSame(2, new PaneReorder()->logicalColumn(self::SHALOM, 2, $runs), 'ltr run: identity, no bidi reorder applied');
    }

    public function testResetAfterEveryRunStopsBackgroundBleedAcrossPanes(): void
    {
        // An LTR run that opens a background and never closes it, then a chrome run.
        $line = "\x1b[48;5;235mBG  ".self::SHALOM;
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => true], // "BG  " with an open background
            ['start' => 4, 'width' => 4, 'ltr' => false],
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);

        // Each run is closed with a reset, so the open background can't paint the
        // next pane — the exact cross-pane bleed this fix removes.
        $this->assertGreaterThanOrEqual(2, substr_count($out, needle: "\x1b[0m"), 'a reset per run');
        $this->assertStringEndsWith("\x1b[0m", $out, 'the row ends reset');
    }

    public function testLogicalColumnInvertsPerPane(): void
    {
        $line = self::SHALOM.'abcd'; // RTL [0..3], LTR [4..7]
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => false],
            ['start' => 4, 'width' => 4, 'ltr' => true],
        ];
        $reorder = new PaneReorder();

        // RTL pane: visual 0 ↔ logical 3 (reversed within [0,4)).
        $this->assertSame(3, $reorder->logicalColumn($line, 0, $runs));
        $this->assertSame(0, $reorder->logicalColumn($line, 3, $runs));
        // LTR pane: identity.
        $this->assertSame(5, $reorder->logicalColumn($line, 5, $runs));
        // A column past all runs is identity.
        $this->assertSame(99, $reorder->logicalColumn($line, 99, $runs));
    }

    /**
     * Documents the Welcome screen's RTL decision: the pane is deliberately not
     * LtrPane, so a step line — margin, `[ ]` marker, translated title,
     * `[action]` — mirrors as a whole. The marker ends at the right edge, the
     * brackets pair up mirrored, and the width invariant holds.
     */
    public function testAWelcomeStepLineMirrorsAsAWhole(): void
    {
        $line = AnsiUtils::truncateToWidth('  [ ]  '.self::SHALOM.'  ['.self::SHALOM.']', 24, pad: true);
        $runs = [['start' => 0, 'width' => 24, 'ltr' => false]];

        $out = new PaneReorder()->reorderRow($line, $runs);
        $plain = AnsiUtils::stripAnsiCodes($out);

        $this->assertSame('     [םולש]  םולש  [ ]  ', $plain, 'padding leads, the marker sits at the right edge');
        $this->assertSame(24, AnsiUtils::visibleWidth($out), 'width preserved');
    }

    /**
     * One caret marker goes in, one comes out.
     *
     * `sliceByColumn(strict: true)` re-emits the escape state active at a
     * slice's start, and it counts the zero-width APC caret marker as state —
     * so every run to the RIGHT of the caret opened with a copy of it. The
     * ScreenWriter strips only the first, which left a raw `\x1b_pi:c;1` on the
     * terminal every frame, once per pane past the caret. Worse, the surviving
     * copy loses its `\x07` terminator and swallows the text after it.
     *
     * Only the reorder path re-slices a composed row, so LTR never sees this.
     * {@see \Porta\Overlay\Region\SpanSlicer} carries the same guard for the
     * same vendored behaviour.
     */
    public function testTheCaretMarkerIsNotCopiedIntoEveryPaneToItsRight(): void
    {
        $line = 'ab'.AnsiUtils::cursorMarker(CursorShape::Bar).'cdEFGH';
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => false],
            ['start' => 4, 'width' => 4, 'ltr' => true],
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);

        $this->assertSame(1, substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'exactly one hardware cursor');
    }

    /**
     * The caret's column is measured in COLUMNS, not bytes.
     *
     * A row carrying a wide character — a CJK filename, an emoji — puts the
     * caret at a column the byte offset does not agree with. Measuring from
     * anywhere but the start of the line drifts by the difference, and the
     * caret is then judged to belong to the pane before its own and stripped.
     *
     * The ASCII cases either side of this one cannot see it: there a stray
     * escape byte counts as exactly the one column a dropped character would
     * have, and the two errors cancel.
     */
    public function testTheCaretColumnIsMeasuredFromTheStartOfTheRow(): void
    {
        $line = "\u{65E5}ab".AnsiUtils::cursorMarker(CursorShape::Bar).'EFGH';
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => true],
            ['start' => 4, 'width' => 4, 'ltr' => true],
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);

        $this->assertSame(1, substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'the caret is this run\'s own');
    }

    /** The pane that actually HOLDS the caret keeps it — the floor. */
    public function testTheCaretMarkerSurvivesInTheRunThatOwnsIt(): void
    {
        $line = 'abcd'.AnsiUtils::cursorMarker(CursorShape::Bar).'EFGH';
        $runs = [
            ['start' => 0, 'width' => 4, 'ltr' => true],
            ['start' => 4, 'width' => 4, 'ltr' => true],
        ];

        $out = new PaneReorder()->reorderRow($line, $runs);

        $this->assertSame(1, substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'the caret is still on screen');
    }

    public function testLogicalColumnMatchesBidiWithinTheRtlSlice(): void
    {
        $line = 'xx'.self::SHALOM; // LTR [0,2), RTL [2,6)
        $runs = [
            ['start' => 0, 'width' => 2, 'ltr' => true],
            ['start' => 2, 'width' => 4, 'ltr' => false],
        ];
        $slice = AnsiUtils::sliceByColumn($line, 2, 4, strict: true);

        $this->assertSame(
            2 + Bidi::visualToLogicalColumn($slice, 1, BaseDirection::Rtl),
            new PaneReorder()->logicalColumn($line, 3, $runs),
        );
    }
}
