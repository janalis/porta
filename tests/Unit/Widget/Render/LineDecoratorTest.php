<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

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

    /** endCol exactly at the line's width: the tail is empty, not a zero-length slice of it. */
    public function testSpanReachingExactlyTheLineEndHasNoTail(): void
    {
        $decorated = LineDecorator::apply('abc', 1, 3, "\x1b[4m", "\x1b[24m");

        $this->assertSame("a\x1b[4mbc\x1b[24m", $decorated);
    }

    /** $col exactly at the line's width appends, rather than trying to insert "at" a non-existent column. */
    public function testInsertAtExactlyTheLineEndAppends(): void
    {
        $this->assertSame('abcXYZ', LineDecorator::insertAt('abc', 3, 'XYZ'));
    }

    /** $col one past the end still appends (never throws walking off the line). */
    public function testInsertAtPastTheLineEndAppends(): void
    {
        $this->assertSame('abcXYZ', LineDecorator::insertAt('abc', 99, 'XYZ'));
    }

    public function testApplyFullWidthPadsAndReappliesBackground(): void
    {
        $decorated = LineDecorator::applyFullWidth('ab'."\x1b[0m".'cd', 8, new Style(background: 236));

        $this->assertSame(8, AnsiUtils::visibleWidth($decorated));
        $this->assertStringStartsWith("\x1b[48;5;236m", $decorated);
        $this->assertStringContainsString("\x1b[0m\x1b[48;5;236m", $decorated, 'background survives a reset inside the line');
        $this->assertStringEndsWith("\x1b[49m", $decorated);
    }

    /** The line is exactly $width already: no padding is appended. */
    public function testApplyFullWidthAddsNoPaddingWhenAlreadyExact(): void
    {
        $decorated = LineDecorator::applyFullWidth('abcd', 4, new Style(background: 236));

        $this->assertSame(4, AnsiUtils::visibleWidth(AnsiUtils::stripAnsiCodes($decorated)));
        $this->assertSame('abcd', AnsiUtils::stripAnsiCodes($decorated));
    }
}
