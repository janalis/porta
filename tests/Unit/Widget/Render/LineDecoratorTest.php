<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Ui\Style;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class LineDecoratorTest extends TestCase
{
    public function testSpanOnPlainText(): void
    {
        $decorated = LineDecorator::apply('hello world', 6, 11, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m");

        $this->assertSame(11, AnsiUtils::visibleWidth($decorated));
        $this->assertStringContainsString("\x1b[48;5;24m".'world', $decorated);
        $this->assertSame('hello world', AnsiUtils::stripAnsiCodes($decorated));
    }

    public function testSpanKeepsWidthOnHighlightedLine(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('x.php');
        $line = $highlighter->highlight(["<?php echo 'hello world';"], 1)[0];
        $before = AnsiUtils::visibleWidth($line);

        $decorated = LineDecorator::apply($line, 6, 10, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m");

        $this->assertSame($before, AnsiUtils::visibleWidth($decorated));
        $this->assertStringContainsString("\x1b[48;5;24m", $decorated);
        $this->assertSame("<?php echo 'hello world';", AnsiUtils::stripAnsiCodes($decorated));
    }

    public function testSpanWithWideCharacters(): void
    {
        $decorated = LineDecorator::apply('日本語 test', 2, 7, "\x1b[48;5;24m", "\x1b[49m");

        $this->assertSame('日本語 test', AnsiUtils::stripAnsiCodes($decorated));
    }

    public function testEmptyAndOutOfRangeSpansAreNoOps(): void
    {
        $this->assertSame('abc', LineDecorator::apply('abc', 2, 2, "\x1b[4m", "\x1b[24m"));
        $this->assertSame('abc', LineDecorator::apply('abc', 5, 9, "\x1b[4m", "\x1b[24m"), 'span entirely past the line');
    }

    public function testSpanClampsToLineWidth(): void
    {
        $decorated = LineDecorator::apply('abc', 1, 99, LineDecorator::UNDERLINE_SGR, "\x1b[24m");

        $this->assertSame(3, AnsiUtils::visibleWidth($decorated));
        $this->assertStringContainsString("\x1b[4m", $decorated);
    }

    public function testApplyFullWidthPadsAndReappliesBackground(): void
    {
        $decorated = LineDecorator::applyFullWidth('ab'."\x1b[0m".'cd', 8, Style::POPUP_ROW_BG);

        $this->assertSame(8, AnsiUtils::visibleWidth($decorated));
        $this->assertStringStartsWith(Style::POPUP_ROW_BG, $decorated);
        $this->assertStringContainsString("\x1b[0m".Style::POPUP_ROW_BG, $decorated, 'background survives a reset inside the line');
        $this->assertStringEndsWith("\x1b[49m", $decorated);
    }
}
