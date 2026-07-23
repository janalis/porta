<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Toast\Toast;
use Porta\Ui\Toast\ToastSeverity;
use Porta\Widget\Render\PopupFrame;
use Porta\Widget\Render\ToastCard;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The toast card: the modal frame vocabulary in the severity's accent, the
 * message wrapped (never truncated mid-thought — the wrap cap ellipsizes),
 * and the exact-width invariant on every row.
 */
final class ToastCardTest extends TestCase
{
    private function card(string $message, ToastSeverity $severity = ToastSeverity::Info, int $inner = 20, bool $closeHovered = false): array
    {
        return ToastCard::render(new Toast(1, $message, $severity), $inner, Styles::dark(), $closeHovered);
    }

    public function testEveryRowIsExactlyTheBoxWidth(): void
    {
        foreach ([12, 20, 42] as $inner) {
            foreach ($this->card(str_repeat('wide message ', 10), inner: $inner) as $i => $row) {
                $this->assertSame($inner + PopupFrame::CHROME_COLS, AnsiUtils::visibleWidth($row), "inner {$inner}, row {$i}");
            }
        }
    }

    public function testTheCardCarriesTheSeverityGlyphTheCloseAndTheMessage(): void
    {
        $rows = array_map(AnsiUtils::stripAnsiCodes(...), $this->card('disk full', ToastSeverity::Error));

        $this->assertStringContainsString('✗', $rows[0], 'the severity glyph on the top border');
        $this->assertStringContainsString('×', $rows[0], 'the close affordance');
        $this->assertStringStartsWith('╭', $rows[0]);
        $this->assertStringContainsString('disk full', $rows[1]);
        $this->assertStringStartsWith('╰', $rows[\count($rows) - 1]);
    }

    public function testAWideCharMessageKeepsTheWidth(): void
    {
        foreach ($this->card('ようこそポルタへ、長いメッセージです') as $i => $row) {
            $this->assertSame(20 + PopupFrame::CHROME_COLS, AnsiUtils::visibleWidth($row), "row {$i}");
        }
    }

    public function testALongMessageWrapsAndCapsWithAnEllipsis(): void
    {
        $rows = $this->card(str_repeat('word ', 60));

        $this->assertCount(ToastCard::MAX_MESSAGE_ROWS + 2, $rows, 'the wrap cap plus the two border rows');
        $this->assertStringContainsString('…', AnsiUtils::stripAnsiCodes($rows[ToastCard::MAX_MESSAGE_ROWS]), 'the last message row ellipsizes');
    }

    public function testHoveringTheCloseRestylesIt(): void
    {
        $normal = $this->card('hi')[0];
        $hovered = $this->card('hi', closeHovered: true)[0];

        $this->assertNotSame($normal, $hovered, 'the × restyles under the pointer');
        $this->assertSame(AnsiUtils::stripAnsiCodes($normal), AnsiUtils::stripAnsiCodes($hovered), 'same text, different shade');
    }

    public function testEachSeverityWearsItsOwnAccent(): void
    {
        $tops = [];
        foreach ([ToastSeverity::Info, ToastSeverity::Success, ToastSeverity::Warn, ToastSeverity::Error] as $severity) {
            $tops[] = $this->card('m', $severity)[0];
        }

        $this->assertCount(4, array_unique($tops), 'four severities, four distinct borders');
    }
}
