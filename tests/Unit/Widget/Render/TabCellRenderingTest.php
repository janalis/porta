<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TabState;
use Porta\Widget\Render\TabStripRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class TabCellRenderingTest extends TestCase
{
    public function testMeasuresTheBareCellAndItsDivider(): void
    {
        $cell = TabStripRenderer::measureCell('one');

        $this->assertSame(' one × ', $cell['text']);
        $this->assertSame(8, $cell['width'], 'seven columns of cell plus the divider');
        $this->assertSame(5, $cell['closeOffset'], 'the × sits after " one "');
    }

    public function testAMarkerShiftsTheCloseZoneWithoutBreakingTheWidth(): void
    {
        $cell = TabStripRenderer::measureCell('one', Glyphs::DIRTY);

        $this->assertSame(' one ●× ', $cell['text']);
        $this->assertSame(9, $cell['width']);
        $this->assertSame(6, $cell['closeOffset'], 'the dirty ● pushes the × one column right');
    }

    public function testAWideLabelIsMeasuredInDisplayColumnsNotBytes(): void
    {
        // CJK glyphs are two columns wide; a byte count would under-measure the
        // cell and the strip would garble at exactly this tab.
        $cell = TabStripRenderer::measureCell('日本');

        $this->assertSame(mb_strwidth($cell['text']) + 1, $cell['width']);
        $this->assertSame(6, $cell['closeOffset'], '" 日本 " is six display columns');
    }

    /**
     * The invariant that keeps a strip from garbling: what the cell claims to
     * occupy is what it actually paints. A hit map built on a width that
     * disagrees with the line puts every zone after it off by the difference.
     */
    public function testTheRenderedCellIsExactlyAsWideAsItWasMeasured(): void
    {
        foreach (['one', '日本', 'a-very-long-buffer-name.php'] as $label) {
            foreach (TabState::cases() as $state) {
                $cell = TabStripRenderer::measureCell($label, Glyphs::DIRTY);
                $rendered = TabStripRenderer::renderCell($cell['text'], $cell['closeOffset'], Styles::dark(), $state);

                $this->assertSame($cell['width'], AnsiUtils::visibleWidth($rendered), $label);
            }
        }
    }

    public function testRendersTheDividerAndBothCloseShades(): void
    {
        $cell = TabStripRenderer::measureCell('one');

        $active = TabStripRenderer::renderCell($cell['text'], $cell['closeOffset'], Styles::dark(), TabState::Active);
        $this->assertStringEndsWith(Glyphs::SEPARATOR, AnsiUtils::stripAnsiCodes($active), 'the divider closes the cell');
        $this->assertStringContainsString("\x1b[38;5;252m", $active, 'the active tab takes the bright × shade');

        $inactive = TabStripRenderer::renderCell($cell['text'], $cell['closeOffset'], Styles::dark(), TabState::Plain);
        $this->assertStringContainsString("\x1b[38;5;240m", $inactive, 'an inactive tab takes the dim × shade');
    }

    public function testTheCloseGlyphNeverPunchesAHoleInTheTabBackground(): void
    {
        // The × is a foreground-only span: it must not emit a full reset, which
        // would drop the tab's background for the rest of the cell.
        $cell = TabStripRenderer::measureCell('one');
        $rendered = TabStripRenderer::renderCell($cell['text'], $cell['closeOffset'], Styles::dark(), TabState::Active);

        $body = substr($rendered, offset: 0, length: (int) strrpos($rendered, Glyphs::SEPARATOR));
        $this->assertStringNotContainsString("\x1b[0m", $body, 'no full reset inside the tab run');
        $this->assertSame(1, substr_count($body, needle: "\x1b[48;5;238m"), 'the background opens once and is never re-opened, so it never lapsed');
    }

    public function testACloseGlyphInTheLabelIsNotRestyledAsACloseButton(): void
    {
        // A file named a×b.php: the label's own × must stay plain, and only the
        // real close × at closeOffset gets the affordance shade.
        $cell = TabStripRenderer::measureCell('a×b.php');
        $rendered = TabStripRenderer::renderCell($cell['text'], $cell['closeOffset'], Styles::dark(), TabState::Active);

        $plain = AnsiUtils::stripAnsiCodes($rendered);
        $this->assertSame(' a×b.php × '.Glyphs::SEPARATOR, $plain, 'both × survive as plain text');
        // Exactly one × carries the bright close shade; the label × does not.
        $this->assertSame(1, substr_count($rendered, needle: "\x1b[38;5;252m×"), 'only the real close glyph is shaded');
        $this->assertSame($cell['width'], AnsiUtils::visibleWidth($rendered), 'the label × did not shift the width');
    }
}
