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
        $this->assertGreaterThanOrEqual(2, \substr_count($out, needle: "\x1b[0m"), 'a reset per run');
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

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'exactly one hardware cursor');
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

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'the caret is this run\'s own');
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

        $this->assertSame(1, \substr_count($out, AnsiUtils::CURSOR_MARKER_PREFIX), 'the caret is still on screen');
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

    /**
     * A floating surface hides what is under it and moves nothing else.
     *
     * This is the defect the Hebrew settings screen was reported for five
     * times. A toast over the right of the screen used to OWN its columns
     * during segmentation, which truncated the pane beneath it; reordering the
     * shortened span then re-right-aligned the pane's whole row, sliding every
     * label about 28 columns left. Measured on the real settings screen, the
     * blast radius went from "columns 127..224 of four rows" to the toast's own
     * box.
     */
    /**
     * A floating surface must not MOVE the pane underneath it.
     *
     * The defect the Hebrew settings screen was reported for five times: a
     * toast used to own its columns during segmentation, which truncated the
     * pane beneath it, and reordering the shortened span re-right-aligned the
     * pane's whole row — every label slid about 28 columns left. Measured on
     * the real settings screen, the blast radius went from "columns 127..224 of
     * four rows" down to the toast's own box.
     *
     * With the surfaces on a layer of their own the claim is the strong one:
     * nothing outside the stamped box changes. It used to allow a second span,
     * where the pane's hidden cells mirrored to, and that span was the hole.
     */
    public function testAFloatingRegionDoesNotMoveThePaneUnderneath(): void
    {
        $reorder = new PaneReorder();
        $line = 'abc '."\u{05E9}\u{05DC}\u{05D5}\u{05DD}".' def '."\u{05E2}\u{05D1}\u{05E8}\u{05D9}\u{05EA}".' ghi';
        $width = AnsiUtils::visibleWidth($line);
        $pane = [['start' => 0, 'width' => $width, 'ltr' => false]];
        $region = ['start' => $width - 6, 'width' => 6, 'ltr' => true];

        $surfaces = \str_repeat(' ', $width - 6).'ZZZZZZ';

        $without = AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $pane));
        $with = AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $pane, [$region], $surfaces));

        $allowed = [];
        for ($c = $region['start']; $c < ($region['start'] + $region['width']); ++$c) {
            $allowed[$width - 1 - $c] = true; // only where the box is stamped: nothing is hidden and nothing moves
        }
        for ($c = 0; $c < $width; ++$c) {
            if (\array_key_exists($c, $allowed)) {
                continue;
            }
            $this->assertSame(
                AnsiUtils::sliceByColumn($without, $c, length: 1),
                AnsiUtils::sliceByColumn($with, $c, length: 1),
                \sprintf('column %d moved', $c),
            );
        }
        $this->assertSame($width, AnsiUtils::visibleWidth($with), 'the row still measures its width');
    }

    public function testAFloatingRegionShowsItsOwnContentAtItsOwnColumns(): void
    {
        // The surface comes from its own row, once, at the mirror of its place.
        $reorder = new PaneReorder();
        $line = \str_repeat(' ', times: 20);
        $surfaces = \str_repeat(' ', times: 10).'[TOAST]'.\str_repeat(' ', times: 3);
        $pane = [['start' => 0, 'width' => 20, 'ltr' => false]];

        $with = AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $pane, [['start' => 10, 'width' => 7, 'ltr' => true]], $surfaces));

        // width 20, box at logical 10 wide 7 -> mirrored start 20-10-7 = 3.
        $this->assertSame('[TOAST]', AnsiUtils::sliceByColumn($with, startCol: 3, length: 7), 'the surface is where it belongs');
        $this->assertSame(1, \substr_count($with, needle: '[TOAST]'), 'and there is exactly one of it');
    }

    /**
     * The whole two-pass shape, pinned on twelve Hebrew letters.
     *
     * Hebrew reverses cleanly, so the expected row can be worked out by hand
     * and every step is constrained: mirror the pane over its FULL span, then
     * stamp the surface from its own row at the mirror of its logical columns.
     * A region in the middle, because one at either end lets the arithmetic be
     * wrong in ways that still look right.
     */
    public function testThePaneMirrorsWholeAndTheSurfaceIsStampedOverIt(): void
    {
        $reorder = new PaneReorder();
        //             0123456789ab
        $line = "\u{05D0}\u{05D1}\u{05D2}\u{05D3}\u{05D4}\u{05D5}\u{05D6}\u{05D7}\u{05D8}\u{05D9}\u{05DA}\u{05DB}";
        $surfaces = '    WXYZ    ';
        $pane = [['start' => 0, 'width' => 12, 'ltr' => false]];

        $out = AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $pane, [['start' => 4, 'width' => 4, 'ltr' => true]], $surfaces));

        $this->assertSame(
            "\u{05DB}\u{05DA}\u{05D9}\u{05D8}" // the tail, mirrored to the front
            .'WXYZ' // the surface at 12 - 4 - 4, over the pane's own middle
            ."\u{05D3}\u{05D2}\u{05D1}\u{05D0}", // the head, mirrored to the back
            $out,
        );
    }

    /**
     * A verbatim pane keeps its text where a mirrored box logically sits.
     *
     * The bug the floating layer exists for: the box used to be composited into
     * the pane at its LOGICAL columns and blanked there before the reorder. A
     * verbatim pane (the editor) does not move, so the blank stayed where it
     * was while the box was stamped at the far end of the row. An open File
     * menu wiped the left third of the editor in Hebrew.
     */
    public function testAVerbatimPaneKeepsItsTextWhereAMirroredBoxLogicallySits(): void
    {
        $reorder = new PaneReorder();
        $pane = [['start' => 0, 'width' => 10, 'ltr' => true]];

        $out = AnsiUtils::stripAnsiCodes($reorder->reorderRow('abcdefghij', $pane, [['start' => 0, 'width' => 4, 'ltr' => true]], '[AB]      '));

        $this->assertSame('abcdef[AB]', $out, 'the box at the far end, and the text it logically sat on still there');
    }

    public function testASurfaceRunningPastTheRowEndIsClamped(): void
    {
        // A surface wider than what is left of the row: the slices either side
        // must not be asked for a negative length.
        $reorder = new PaneReorder();
        $line = "\u{05D0}\u{05D1}\u{05D2}\u{05D3}";
        $pane = [['start' => 0, 'width' => 4, 'ltr' => false]];

        $out = AnsiUtils::stripAnsiCodes($reorder->reorderRow($line, $pane, [['start' => 2, 'width' => 9, 'ltr' => true]], '  XY'));

        // The surface claims nine columns but only two exist past its start, so
        // it is clamped to those. What must never happen is the row coming back
        // SHORTER than its pane.
        $this->assertSame(4, AnsiUtils::visibleWidth($out), 'the row still measures its width');
        $this->assertSame('XY', AnsiUtils::sliceByColumn($out, startCol: 0, length: 2), 'only the two cells that exist are stamped, at the mirror of their start');
    }

    public function testTheRowIsFrozenWithASingleOverride(): void
    {
        // For a terminal that runs UAX#9 itself. Porta has already laid the row
        // out, so the only requirement is that the terminal not lay it out
        // again. One override at column zero does it: an override runs to the
        // end of the paragraph, and a row IS a paragraph.
        $reorder = new PaneReorder();
        $line = ' '."\u{05E9}\u{05DC}\u{05D5}\u{05DD}".'  ab ';

        $out = $reorder->freezeRow($line);

        $this->assertStringStartsWith(Bidi::LTR_OVERRIDE, AnsiUtils::stripAnsiCodes($out));
        $this->assertSame(1, \substr_count($out, needle: Bidi::LTR_OVERRIDE), 'one is enough, and no pop is needed');
        $this->assertSame(AnsiUtils::visibleWidth($line), AnsiUtils::visibleWidth(AnsiUtils::stripAnsiCodes($out)), 'the row keeps its width');
    }

    public function testTheFreezeCostsTheRowNoColumn(): void
    {
        // The override is a format character: no column measured, none drawn.
        // It goes in front, and the padding it once had to replace stays.
        $reorder = new PaneReorder();

        $out = AnsiUtils::stripAnsiCodes($reorder->freezeRow(' abc'));

        $this->assertSame(Bidi::LTR_OVERRIDE.' abc', $out);
        $this->assertSame(4, AnsiUtils::visibleWidth($out));
    }

    public function testARowThatDoesNotStartWithPaddingIsFrozenAllTheSame(): void
    {
        // Needing no cell to spend, the freeze needs no padding either.
        $reorder = new PaneReorder();

        $this->assertSame(Bidi::LTR_OVERRIDE.'abcd', AnsiUtils::stripAnsiCodes($reorder->freezeRow('abcd')));
    }

    public function testTheFreezeIgnoresPaneGeometryEntirely(): void
    {
        // The earlier version spent a pair per PANE and ran after the floating
        // surfaces had been stamped on, so it wrote override characters into a
        // dropdown's own cells and shifted its border a column on alternate
        // rows -- measured at columns 176, 199 and 200 of the reported screen.
        // A row-level freeze cannot do that: it touches column zero and nothing
        // else, whatever is on the row.
        $reorder = new PaneReorder();
        $line = ' '.\str_repeat('x', times: 8).'╭───╮';

        $out = AnsiUtils::stripAnsiCodes($reorder->freezeRow($line));

        $this->assertSame(Bidi::LTR_OVERRIDE.$line, $out, 'everything after column zero is untouched');
    }

    public function testAFloatingBoxIsMirroredWithTheFrame(): void
    {
        // A floating surface is laid out in the same LOGICAL space as the
        // widgets, so a mirrored frame has to mirror its box too. Leaving it at
        // its logical columns is what put an app-menu dropdown at the opposite
        // end of the screen from the title it belongs to: `File` is logically
        // first, so its dropdown sat at column 0 while the mirrored menu bar
        // drew `קובץ` at the far right. Reported, reproduced at 226 columns
        // (region 0+38 against a title at ~218), and fixed here.
        $reorder = new PaneReorder();
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];

        $out = AnsiUtils::stripAnsiCodes($reorder->reorderRow(\str_repeat(' ', times: 10), $pane, [['start' => 0, 'width' => 4, 'ltr' => true]], '[AB]      '));

        $this->assertSame('[AB]', AnsiUtils::sliceByColumn($out, startCol: 6, length: 4), 'the box mirrors to the far end');
        $this->assertSame(10, AnsiUtils::visibleWidth($out), 'and the row keeps its width');
    }

    /**
     * A verbatim box moves as one block, so its narrower rows keep their
     * place in it. Placed by their own spans, Find in Files' title row (which
     * casts no side shadow) sat a column right of its body, and its band
     * started under the box's edge instead of one cell in.
     */
    public function testAVerbatimBoxsNarrowerRowsKeepTheirPlaceInTheBlock(): void
    {
        $reorder = new PaneReorder();
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $blank = \str_repeat(' ', times: 10);
        $placed = static fn (array $region, string $surfaces): int => (int) \mb_strpos(AnsiUtils::stripAnsiCodes($reorder->reorderRow($blank, $pane, [$region], $surfaces)), needle: 'X');

        // Box [2, 7): the body row is the whole box, stamped at 10 - 2 - 5 = 3.
        $this->assertSame(3, $placed(['start' => 2, 'width' => 5, 'ltr' => true], surfaces: '  XXXXX   '));
        $this->assertSame(3, $placed(['start' => 2, 'width' => 4, 'ltr' => true, 'box' => [2, 5]], surfaces: '  XXXX    '), 'the title row, with the body');
        $this->assertSame(4, $placed(['start' => 3, 'width' => 4, 'ltr' => true, 'box' => [2, 5]], surfaces: '   XXXX   '), 'the band, one cell in');
        $this->assertSame(4, $placed(['start' => 2, 'width' => 4, 'ltr' => false, 'box' => [2, 5]], surfaces: '  XXXX    '), 'a mirrored row, at the mirror of its own span');
        // A box running past the row end moves as the part of it that exists.
        $this->assertSame(1, $placed(['start' => 7, 'width' => 3, 'ltr' => true, 'box' => [6, 6]], surfaces: '       XXX'));
        $this->assertSame(0, $placed(['start' => 5, 'width' => 4, 'ltr' => true, 'box' => [5, 5]], surfaces: '     XXXX '), 'flush with the row start');
        $this->assertSame(
            4,
            $reorder->logicalColumn($blank, visualCol: 5, runs: $pane, regions: [['start' => 3, 'width' => 4, 'ltr' => true, 'box' => [2, 5]]], surfaces: '   XXXX   '),
            'a click follows it',
        );
    }

    public function testThePaneRowCarriesNoCopyOfTheBox(): void
    {
        // The pane pass never sees the surface, so it cannot carry a second
        // copy of the box somewhere else in the row -- which is what blanking
        // the surface's columns out of the pane row used to be for.
        $reorder = new PaneReorder();
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];

        $out = AnsiUtils::stripAnsiCodes($reorder->reorderRow(\str_repeat(' ', times: 10), $pane, [['start' => 0, 'width' => 4, 'ltr' => true]], '[AB]      '));

        $this->assertSame(1, \substr_count($out, needle: '[AB]'), 'exactly one copy of the box');
    }

    public function testASurfaceStartingPastTheRowIsNotStampedAtAll(): void
    {
        // Nothing of it exists in this row, so there is nothing to take a slice
        // from. Stamping a zero-width box would still rebuild the row around an
        // empty middle, and any slip in that arithmetic changes its width.
        $reorder = new PaneReorder();
        $line = "\u{05D0}\u{05D1}\u{05D2}\u{05D3}";
        $pane = [['start' => 0, 'width' => 4, 'ltr' => false]];

        $withBox = $reorder->reorderRow($line, $pane, [['start' => 10, 'width' => 3, 'ltr' => true]]);
        $without = $reorder->reorderRow($line, $pane);

        $this->assertSame(AnsiUtils::stripAnsiCodes($without), AnsiUtils::stripAnsiCodes($withBox), 'the row is untouched');
        $this->assertSame(4, AnsiUtils::visibleWidth(AnsiUtils::stripAnsiCodes($withBox)));
    }

    public function testAClickInsideAMirroredBoxMapsThroughTheBox(): void
    {
        // Reported as "click not working": the box moves with the frame, so the
        // inverse mapping has to follow it. Mapping the visual column through
        // the PANE underneath a mirrored dropdown lands somewhere else entirely
        // and the menu item is never hit.
        $reorder = new PaneReorder();
        $line = '[AB]'.\str_repeat(' ', times: 6);
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $region = [['start' => 0, 'width' => 4, 'ltr' => true]];

        // The box is stamped at columns 6..9; column 7 is its second cell.
        $this->assertSame(1, $reorder->logicalColumn($line, visualCol: 7, runs: $pane, regions: $region));
        $this->assertSame(0, $reorder->logicalColumn($line, visualCol: 6, runs: $pane, regions: $region));
    }

    /**
     * A whole row mapped at once answers every column as a click on it does —
     * the frame passes ask for every cell, and a column at a time cost a side
     * tab strip 2.4 s a frame.
     */
    public function testARowMappedAtOnceAnswersEachColumnAsAClickDoes(): void
    {
        $reorder = new PaneReorder();
        $hebrew = "\u{05D0}\u{05D1}\u{05D2}\u{05D3}";
        $rtl = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $cases = [
            'two panes, one each way' => ["\x1b[2mab\x1b[0m".self::SHALOM.'cd'.self::SHALOM.'xy', [['start' => 0, 'width' => 4, 'ltr' => true], ['start' => 4, 'width' => 12, 'ltr' => false]], [], ''],
            'wide glyphs' => ['日本'.self::SHALOM.'語', [['start' => 0, 'width' => 10, 'ltr' => false]], [], ''],
            'a box over the pane' => ['[AB]'.\str_repeat(' ', times: 6), $rtl, [['start' => 0, 'width' => 4, 'ltr' => true]], ''],
            'two boxes, the later on top' => [\str_repeat('x', times: 10), $rtl, [['start' => 0, 'width' => 4, 'ltr' => true], ['start' => 2, 'width' => 4, 'ltr' => true]], ''],
            'a Hebrew box' => ['ab'.\str_repeat(' ', times: 8), $rtl, [['start' => 0, 'width' => 4, 'ltr' => false]], $hebrew.\str_repeat(' ', times: 6)],
            'two right-to-left panes of one width' => [self::SHALOM.'abcd', [['start' => 0, 'width' => 4, 'ltr' => false], ['start' => 4, 'width' => 4, 'ltr' => false]], [], ''],
            'runs short of the row' => [self::SHALOM.'abcdef', [['start' => 0, 'width' => 4, 'ltr' => false]], [], ''],
        ];

        foreach ($cases as $name => [$line, $runs, $regions, $surfaces]) {
            $each = \array_map(
                static fn (int $col): int => $reorder->logicalColumn($line, $col, $runs, $regions, $surfaces),
                \range(start: 0, end: AnsiUtils::visibleWidth($line) - 1),
            );
            $this->assertSame($each, $reorder->logicalColumns($line, $runs, $regions, $surfaces), $name);
        }
        $this->assertNotSame(
            \range(start: 0, end: 9),
            $reorder->logicalColumns('ab'.\str_repeat(' ', times: 8), $rtl, [['start' => 0, 'width' => 4, 'ltr' => false]], $hebrew.\str_repeat(' ', times: 6)),
            'the premise: a row that is mirrored',
        );
    }

    public function testAClickOutsideTheBoxStillMapsThroughThePane(): void
    {
        $reorder = new PaneReorder();
        $line = '[AB]'.\str_repeat(' ', times: 6);
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $region = [['start' => 0, 'width' => 4, 'ltr' => true]];

        $outside = $reorder->logicalColumn($line, visualCol: 0, runs: $pane, regions: $region);

        $this->assertSame($reorder->logicalColumn($line, visualCol: 0, runs: $pane), $outside, 'the pane still answers for its own cells');
    }

    public function testTheTopmostBoxOwnsACellTwoBoxesShare(): void
    {
        // Surfaces paint in order, so the LAST one drawn owns the click.
        $reorder = new PaneReorder();
        $line = \str_repeat('x', times: 10);
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $regions = [
            ['start' => 0, 'width' => 4, 'ltr' => true], // mirrors to 6..9
            ['start' => 2, 'width' => 4, 'ltr' => true], // mirrors to 4..7, painted later
        ];

        $this->assertSame(5, $reorder->logicalColumn($line, visualCol: 7, runs: $pane, regions: $regions));
    }

    public function testAClickIgnoresABoxThatIsNotInThisRow(): void
    {
        // A surface starting past the row owns no cell here, so the pane must
        // still answer — otherwise a stale box would swallow every click.
        $reorder = new PaneReorder();
        $line = \str_repeat('x', times: 10);
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];

        $this->assertSame(
            $reorder->logicalColumn($line, visualCol: 3, runs: $pane),
            $reorder->logicalColumn($line, visualCol: 3, runs: $pane, regions: [['start' => 40, 'width' => 4, 'ltr' => true]]),
        );
    }

    public function testAClickInAMirroredBoxOfHebrewMapsThroughItsOwnReorder(): void
    {
        // A box whose own contents were mirrored has to invert that mirror too,
        // not just its position: the menu items inside a dropdown are Hebrew.
        // Its contents are read from the surfaces' row, not the pane's.
        $reorder = new PaneReorder();
        $surfaces = "\u{05D0}\u{05D1}\u{05D2}\u{05D3}".\str_repeat(' ', times: 6);
        $line = 'ab'.\str_repeat(' ', times: 8);
        $pane = [['start' => 0, 'width' => 10, 'ltr' => false]];
        $region = [['start' => 0, 'width' => 4, 'ltr' => false]];

        // The box is stamped at 6..9 and its four Hebrew cells are reversed
        // inside it, so its leftmost visual cell is the LAST logical one.
        $this->assertSame(3, $reorder->logicalColumn($line, visualCol: 6, runs: $pane, regions: $region, surfaces: $surfaces));
        $this->assertSame(0, $reorder->logicalColumn($line, visualCol: 9, runs: $pane, regions: $region, surfaces: $surfaces));
    }

    public function testTheWidgetsOwnDirectionMarksAreSpentNotPassedOn(): void
    {
        // Those marks steer PORTA's reorder, which has already run by this
        // point; a terminal running UAX#9 would steer with them a second time.
        // They take no column, so dropping them is what Bidi::serialize() does
        // on the other path.
        $reorder = new PaneReorder();
        $line = ' ab'.Bidi::RTL_MARK.Bidi::LTR_MARK."\u{061C}".'cd ';
        $out = AnsiUtils::stripAnsiCodes($reorder->freezeRow($line));

        $this->assertSame(Bidi::LTR_OVERRIDE.' abcd ', $out, 'every mark dropped; the override in front');
        $this->assertSame(AnsiUtils::visibleWidth($line), AnsiUtils::visibleWidth($out), 'and the row keeps its width');
    }

    public function testARowWithAGutterIsFrozenInFrontOfIt(): void
    {
        // The help viewer renders prose against a left-hand border, so column
        // zero is never padding. That once sent the override to the first
        // padding cell instead; costing no column, it now goes in front.
        $reorder = new PaneReorder();
        $line = '│ '."\u{05E9}\u{05DC}\u{05D5}\u{05DD}".' ';

        $out = AnsiUtils::stripAnsiCodes($reorder->freezeRow($line));

        $this->assertSame(Bidi::LTR_OVERRIDE.$line, $out);
        $this->assertSame(AnsiUtils::visibleWidth($line), AnsiUtils::visibleWidth($out), 'the row keeps its width');
    }

    public function testARowThatOpensWithRightToLeftTextIsFrozenToo(): void
    {
        // Once the known gap: the text came before any padding the override
        // could replace, and an override after it would not govern it.
        $reorder = new PaneReorder();
        $line = "\u{05E9}\u{05DC}".' ab';

        $this->assertSame(Bidi::LTR_OVERRIDE.$line, AnsiUtils::stripAnsiCodes($reorder->freezeRow($line)));
    }
}
