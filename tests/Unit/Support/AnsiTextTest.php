<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\CursorShape;

final class AnsiTextTest extends TestCase
{
    public function testCaretWindowKeepsShortTextIntactWithTheMarkerAtTheCaret(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $this->assertSame('abc'.$m, AnsiText::caretWindow('abc', 3, 10, $m), 'caret at end');
        $this->assertSame('a'.$m.'bc', AnsiText::caretWindow('abc', 1, 10, $m), 'caret mid-line');
    }

    public function testCaretWindowScrollsToTheTailWhenTheCaretIsAtTheEnd(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('0123456789', 10, 5, $m);
        $this->assertSame('6789'.$m, $out, 'shows the trailing window with the marker');
        $this->assertLessThanOrEqual(5, AnsiUtils::visibleWidth($out));
    }

    public function testCaretWindowBracketsAMidLineCaret(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('0123456789', 5, 5, $m);
        $this->assertSame('1234'.$m.'5', $out);
        $this->assertLessThanOrEqual(5, AnsiUtils::visibleWidth($out));
    }

    public function testCaretWindowHandlesWideCharacters(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        $out = AnsiText::caretWindow('あいうえお', 10, 3, $m); // 5 wide chars = 10 columns, caret at end
        $this->assertSame(1, substr_count($out, $m), 'exactly one marker');
        $this->assertLessThanOrEqual(3, AnsiUtils::visibleWidth($out));
    }

    public function testScrollLineToCaretClipsAndPadsAMarkerlessLine(): void
    {
        $out = AnsiText::scrollLineToCaret('a short line', 20);

        $this->assertSame(20, AnsiUtils::visibleWidth($out), 'padded to width');
        $this->assertStringStartsWith('a short line', AnsiUtils::stripAnsiCodes($out), 'clipped from the start');
    }

    public function testScrollLineToCaretScrollsAMidLineCaretIntoView(): void
    {
        $m = AnsiUtils::cursorMarker(CursorShape::Bar);
        // "Find: " + long query + marker at the caret, then trailing chrome.
        $line = 'Find: '.str_repeat('q', times: 40).$m.'  [0/0]';

        $out = AnsiText::scrollLineToCaret($line, 20);

        $this->assertSame(1, substr_count($out, $m), 'the caret survives');
        $this->assertSame(20, AnsiUtils::visibleWidth($out), 'clipped and padded to width');
        // The window scrolled past the label to keep the caret visible.
        $this->assertStringNotContainsString('Find:', AnsiUtils::stripAnsiCodes($out));
    }

    public function testScrollLineToCaretDiscoversAnyMarkerShape(): void
    {
        // The marker is found by its APC prefix, whatever the configured shape.
        $block = AnsiUtils::cursorMarker(CursorShape::Block);
        $out = AnsiText::scrollLineToCaret('ab'.$block.'cd', 10);

        $this->assertSame(1, substr_count($out, $block), 'a block marker survives untouched');
        $this->assertSame(10, AnsiUtils::visibleWidth($out));
    }

    public function testScrollLineToCaretTreatsAnUnterminatedMarkerPrefixAsPlainText(): void
    {
        $out = AnsiText::scrollLineToCaret('ab'.AnsiUtils::CURSOR_MARKER_PREFIX, 10);

        $this->assertSame(10, AnsiUtils::visibleWidth($out), 'falls back to a plain clip');
    }

    public function testPadsWithTrailingSpacesToReachTheWidth(): void
    {
        $this->assertSame('ab   ', AnsiText::padRight('ab', 5));
    }

    public function testIsANoOpWhenAlreadyAtLeastAsWide(): void
    {
        $this->assertSame('abcd', AnsiText::padRight('abcd', 4));
        $this->assertSame('abcdef', AnsiText::padRight('abcdef', 4));
    }

    public function testCountsVisibleWidthOnlyIgnoringSgrCodes(): void
    {
        // "ab" styled is two visible columns, so it pads to five.
        $this->assertSame("\x1b[31mab\x1b[0m   ", AnsiText::padRight("\x1b[31mab\x1b[0m", 5));
    }
}
