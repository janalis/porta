<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FindModalRows;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\RowSelection;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class FindModalRowsTest extends TestCase
{
    public function testOptionTagOnBracketsTheGlyph(): void
    {
        $tag = AnsiUtils::stripAnsiCodes(FindModalRows::optionTag('C', OptionTagState::On, Styles::dark()));
        $this->assertSame('[C]', $tag);
    }

    public function testOptionTagHoveredPadsTheGlyphOnBothSides(): void
    {
        $tag = AnsiUtils::stripAnsiCodes(FindModalRows::optionTag('C', OptionTagState::Hovered, Styles::dark()));
        $this->assertSame(' C ', $tag);
    }

    public function testOptionTagOffPadsTheGlyphOnBothSides(): void
    {
        $tag = AnsiUtils::stripAnsiCodes(FindModalRows::optionTag('C', OptionTagState::Off, Styles::dark()));
        $this->assertSame(' C ', $tag);
    }

    public function testFileHeaderCarriesThePathThenTheCount(): void
    {
        $header = AnsiUtils::stripAnsiCodes(FindModalRows::fileHeader('src/x.php', 3, 40, Styles::dark()));

        $this->assertSame(' src/x.php  (3)'.str_repeat(' ', times: 40 - 15), $header);
        $this->assertSame(40, AnsiUtils::visibleWidth($header));
    }

    public function testFileHeaderPadsToTheRequestedWidth(): void
    {
        // pad:true must actually pad — a naturally-short header still reaches width.
        $header = FindModalRows::fileHeader('a', 1, 20, Styles::dark());
        $this->assertSame(20, AnsiUtils::visibleWidth($header));
    }

    public function testMatchLineCarriesTheOneBasedLineNumberThenTheText(): void
    {
        $result = ['path' => 'x.php', 'line' => 4, 'text' => 'needle here'];

        $plain = AnsiUtils::stripAnsiCodes(FindModalRows::match($result, RowSelection::Unselected, 40, Styles::dark()));

        // line is 0-based internally; the rendered label is 1-based.
        $this->assertSame('    5  needle here'.str_repeat(' ', times: 40 - 18), $plain);
    }

    public function testMatchHighlightsRangesAtThePrefixWidthOffset(): void
    {
        $result = ['path' => 'x.php', 'line' => 0, 'text' => 'needle here', 'ranges' => [['start' => 0, 'length' => 6]]];

        $out = FindModalRows::match($result, RowSelection::Unselected, 40, Styles::dark());

        // prefixWidth = 4 (indent) + 1 (label "1") + 2 (gutter) = 7: the highlight
        // must start exactly there — one column early or late shifts the tint
        // onto the gutter or off the front of "needle".
        $this->assertStringNotContainsString('48;5;58', AnsiUtils::sliceByColumn($out, 6, 1, strict: true), 'column 6 (the gutter) is not tinted');
        $this->assertStringContainsString('48;5;58', AnsiUtils::sliceByColumn($out, 7, 1, strict: true), 'column 7 ("n" of needle) is tinted');
    }
}
