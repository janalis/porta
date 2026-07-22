<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use Porta\I18n\BaseDirection;
use Porta\I18n\Bidi;
use Porta\I18n\PaneReorder;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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
