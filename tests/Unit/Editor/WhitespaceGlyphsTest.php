<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\WhitespaceGlyphs;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Leading and trailing whitespace made visible, one display column for one.
 */
final class WhitespaceGlyphsTest extends TestCase
{
    public function testIndentSpacesBecomeDots(): void
    {
        $out = WhitespaceGlyphs::apply('    $x = 1;', '    $x = 1;');

        $this->assertSame('····$x = 1;', $out, 'the indent is marked and the inner spaces are not');
    }

    public function testATABIsAnArrowAndThenBLANK(): void
    {
        // The highlighted line has already had the tab expanded to four
        // spaces, so the only place the tab still exists is the raw line. An
        // arrow and then nothing is what a graphical editor draws — and it is
        // what keeps a tab legible when an indent guide takes the arrow's cell,
        // because four spaces there would still be dots.
        $out = WhitespaceGlyphs::apply('        $x = 1;', "\t\t\$x = 1;");

        $this->assertSame('→   →   $x = 1;', $out);
    }

    public function testTabsAndSpacesMIXEDInOneIndent(): void
    {
        // The case the feature exists for: this reads as eight columns of
        // indent either way, and only the markers say which is which.
        $out = WhitespaceGlyphs::apply('        $x = 1;', "\t    \$x = 1;");

        $this->assertSame('→   ····$x = 1;', $out, 'a tab, then four spaces — and they look different');
    }

    public function testATabStartingOffAStopCoversOnlyWhatIsLeftOfIt(): void
    {
        // Two spaces, a tab, then two more spaces. With four-column stops the
        // tab covers columns 2 and 3 only, so the spaces after it are dots at
        // 4 and 5.
        //
        // The trailing spaces are what make this discriminating: a tab that
        // ends the indent can be given far too many padding columns without
        // anything noticing, because nothing reads past the indent.
        $out = WhitespaceGlyphs::apply('      $x;', "  \t  \$x;");

        $this->assertSame('··→ ··$x;', $out, 'dots, arrow, blank, then dots again');
    }

    public function testWhitespaceAFTERTheIndentIsNeverReadAsATab(): void
    {
        // A tab used to align a trailing comment sits outside the indent. The
        // scan stops at the indent, so its columns cannot be mistaken for
        // indent columns and mark the wrong cell.
        $out = WhitespaceGlyphs::apply('  $x;      ', "  \$x;\t\t");

        $this->assertSame('··$x;······', $out, 'the indent is dotted; the rest is trailing, not tabs');
    }

    public function testTRAILINGSpacesAreMarkedToo(): void
    {
        // The other question worth answering: is there junk after the
        // semicolon. Inner spaces stay bare so the line is still readable.
        $out = WhitespaceGlyphs::apply('  $x = 1;   ', '  $x = 1;   ');

        $this->assertSame('··$x = 1;···', $out);
    }

    public function testALineOfNOTHINGBUTSpacesIsAllIndent(): void
    {
        // No non-space character ever arrives, so the run is the indent and
        // the trailing pass has nothing left to do.
        $this->assertSame('····', WhitespaceGlyphs::apply('    ', '    '));
    }

    public function testALineWithNoWhitespaceIsUntouched(): void
    {
        $this->assertSame('$x=1;', WhitespaceGlyphs::apply('$x=1;', '$x=1;'));
        $this->assertSame('', WhitespaceGlyphs::apply('', ''));
    }

    public function testESCAPESSurviveVerbatimAndTheWIDTHIsUnchanged(): void
    {
        // The invariant everything downstream depends on: the column slice,
        // the spans and the row-width assertions all read this line back.
        $highlighted = "    \x1b[38;5;176m\$x\x1b[39m = 1;  ";
        $out = WhitespaceGlyphs::apply($highlighted, '    $x = 1;  ');

        $this->assertStringContainsString("\x1b[38;5;176m", $out, 'the colour is intact');
        $this->assertSame(AnsiUtils::visibleWidth($highlighted), AnsiUtils::visibleWidth($out));
        $this->assertSame(
            '····$x = 1;··',
            AnsiUtils::stripAnsiCodes($out),
            'and the marked text is what a bare line would have been',
        );
    }

    public function testAnEscapeINSIDETheIndentDoesNotShiftTheTabColumns(): void
    {
        // A backend that colours indentation puts escapes between the spaces.
        // They carry no column, so the tab at column 4 has to still be found.
        $out = WhitespaceGlyphs::apply("    \x1b[39m    \$x;", "    \t\$x;");

        $this->assertSame('····→   $x;', AnsiUtils::stripAnsiCodes($out));
    }

    public function testTheGlyphsAreOneColumnWide(): void
    {
        // Glyphs promises this for the whole vocabulary; these two are drawn
        // inside the code text, where breaking it would garble the row.
        $this->assertSame(1, AnsiUtils::visibleWidth(Glyphs::WHITESPACE_SPACE));
        $this->assertSame(1, AnsiUtils::visibleWidth(Glyphs::WHITESPACE_TAB));
    }
}
