<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Screenshots;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\Screenshots\ScreenSvg;
use Porta\Ui\Graphics\KittyPlaceholders;

/**
 * A forced frame drawn as the docs' SVG: each cell where it was written, in
 * the colours and attributes it was written with.
 */
final class ScreenSvgTest extends TestCase
{
    /** A forced frame: clear, home, the rows joined by CRLF. */
    private static function svg(string ...$rows): string
    {
        return ScreenSvg::fromFrame("\x1b[2J\x1b[H".\implode("\r\n", $rows), 20, \max(1, \count($rows)));
    }

    /** @return list<array{string, string}> each `<text>`'s attributes and content */
    private static function texts(string $svg): array
    {
        $texts = [];
        \preg_match_all('~<text ([^>]*)>([^<]*)</text>~', $svg, $texts, \PREG_SET_ORDER);

        return \array_map(static fn (array $text): array => [$text[1], $text[2]], $texts);
    }

    public function testATextRunCarriesItsColourAndIsPinnedToItsColumns(): void
    {
        $texts = self::texts(self::svg("ab\x1b[38;5;196mcd\x1b[39m"));

        $this->assertSame(['ab', 'cd'], \array_column($texts, 1), 'a colour change starts a new run');
        $this->assertStringContainsString('fill="#ff0000"', $texts[1][0], 'the 256-colour cube');
        $this->assertStringContainsString('x="28.8"', $texts[1][0], 'column 2: the padding plus two cells');
        $this->assertStringContainsString('textLength="16.8"', $texts[1][0], 'two cells wide, whatever the font');
    }

    public function testTrueColourGreysAndTheSystemColoursResolve(): void
    {
        $svg = self::svg("\x1b[38;2;1;2;3ma\x1b[38;5;232mb\x1b[91mc\x1b[38;5;3md");

        foreach (['#010203', '#080808', '#ff0000', '#cdcd00'] as $colour) {
            $this->assertStringContainsString('fill="'.$colour.'"', $svg);
        }
    }

    public function testABackgroundIsOneRectPerRun(): void
    {
        $svg = self::svg("\x1b[48;5;31mabc\x1b[49m d");

        $this->assertSame(1, \substr_count($svg, needle: 'fill="#0087af"'), 'one rect for the three cells');
        $this->assertStringContainsString('width="25.2" height="18" fill="#0087af"', $svg);
    }

    public function testAttributesAreDrawnAndResetIndependently(): void
    {
        $texts = self::texts(self::svg("\x1b[1;3;4ma\x1b[22mb\x1b[23;24;2mc\x1b[0md"));

        $this->assertStringContainsString('font-weight="bold" font-style="italic" text-decoration="underline"', $texts[0][0]);
        $this->assertStringNotContainsString('bold', $texts[1][0], '22 ends the bold');
        $this->assertStringContainsString('opacity="0.6"', $texts[2][0], 'dim');
        $this->assertStringNotContainsString('italic', $texts[2][0]);
        $this->assertSame(' fill="#d0d0d0"', \strstr($texts[3][0], needle: ' fill='), '0 resets everything');
    }

    public function testInverseSwapsTheColours(): void
    {
        $svg = self::svg("\x1b[7;38;5;196ma\x1b[27mb");

        $this->assertStringContainsString('height="18" fill="#ff0000"', $svg, 'the foreground fills the cell');
        $this->assertStringContainsString('fill="#1c1c1c">a</text>', $svg, 'the glyph takes the background');
    }

    public function testMarkupIsEscaped(): void
    {
        $this->assertSame(['&lt;a href=&quot;x&quot;&gt; &amp;'], \array_column(self::texts(self::svg('<a href="x"> &')), 1));
    }

    public function testWideSpacingSplitsARunButOneSpaceDoesNot(): void
    {
        $texts = self::texts(self::svg('one two   three'));

        $this->assertSame(['one two', 'three'], \array_column($texts, 1));
        $this->assertStringContainsString('x="96"', $texts[1][0], 'the phrase keeps its column (10)');
    }

    public function testAWideGlyphTakesTwoColumns(): void
    {
        $texts = self::texts(self::svg('界   x'));

        $this->assertStringContainsString('textLength="16.8"', $texts[0][0]);
        $this->assertStringContainsString('x="54"', $texts[1][0], 'x sits at column 5');
    }

    public function testBlocksAndBoxDrawingAreShapes(): void
    {
        $svg = self::svg("\x1b[38;5;196m▀│╭━═░");

        $this->assertSame([], self::texts($svg), 'nothing is left to a font');
        $this->assertStringContainsString('width="8.4" height="9" fill="#ff0000"', $svg, 'the upper half block');
        $this->assertStringContainsString('fill-opacity="0.25"', $svg, 'a light shade');
        $this->assertStringContainsString('stroke="#ff0000" stroke-width="1.2"', $svg);
        $this->assertStringContainsString('stroke-width="2.4"', $svg, 'the heavy line');
        $this->assertStringContainsString('Q', $svg, 'the rounded corner curves');
    }

    public function testAPrivateUseGlyphIsDrawnAsItsStandardStandIn(): void
    {
        $this->assertSame(['⎇ main'], \array_column(self::texts(self::svg("\u{E0A0} main")), 1));
    }

    public function testAShownCursorIsDrawnWhereTheWriterParkedIt(): void
    {
        $svg = self::svg('a', "b\x1b[1A\x1b[4G\x1b[5 q\x1b[?25h");

        $this->assertStringContainsString('<rect x="37.2" y="40" width="2" height="18"', $svg, 'row 0, column 3');
        $this->assertStringNotContainsString('width="2"', self::svg('a', "b\x1b[1A\x1b[4G\x1b[?25l"), 'a hidden cursor is not drawn');
    }

    public function testAPlacedPictureIsDrawnOverItsPlaceholderCells(): void
    {
        $png = PngFixture::bytes(2, 2);
        $sent = "\x1b_Ga=t,f=100,t=d,i=7,q=2,m=0;".\base64_encode($png)."\x1b\\\x1b_Ga=p,U=1,i=7,p=1,c=2,r=1,q=2\x1b\\";
        $row = 'ab'.KittyPlaceholders::expand(KittyPlaceholders::row(7, 0, 0, 2));

        $svg = ScreenSvg::fromFrame("\x1b[H".$row, 10, 1, '', $sent);

        $this->assertSame(['ab'], \array_column(self::texts($svg), 1), 'a placeholder is not text');
        $this->assertStringContainsString('<image clip-path="url(#picture-7)" x="28.8" y="40" width="16.8" height="18"', $svg);
        $this->assertStringContainsString(\base64_encode($png), $svg);
    }

    public function testAPlaceholderWithNothingSentDrawsNothing(): void
    {
        $svg = ScreenSvg::fromFrame("\x1b[H".KittyPlaceholders::expand(KittyPlaceholders::row(7, 0, 0, 2)), 10, 1);

        $this->assertStringNotContainsString('<image', $svg);
    }

    public function testTheWindowCarriesItsTitle(): void
    {
        $svg = ScreenSvg::fromFrame("\x1b[Hx", 10, 1, 'acme — <Porta>');

        $this->assertStringContainsString('>acme — &lt;Porta&gt;</text>', $svg);
        $this->assertNotFalse(\simplexml_load_string($svg));
    }

    public function testADifferentialRepaintIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScreenSvg::fromFrame("\x1b[2Aabc", 10, 1);
    }
}
