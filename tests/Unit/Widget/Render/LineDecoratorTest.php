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

    public function testApplyAllWithNoSpansReturnsTheLineUntouched(): void
    {
        $this->assertSame('hello', LineDecorator::applyAll('hello', []));
    }

    public function testApplyAllWithOneSpanIsByteIdenticalToApply(): void
    {
        $spans = [[6, 11, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m"]];

        $this->assertSame(
            LineDecorator::apply('hello world', 6, 11, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m"),
            LineDecorator::applyAll('hello world', $spans),
        );

        $highlighter = new CodeHighlighter();
        $highlighter->setFile('x.php');
        $line = $highlighter->highlight(["<?php echo 'hello world';"], 1)[0];
        $this->assertSame(
            LineDecorator::apply($line, 3, 12, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m"),
            LineDecorator::applyAll($line, [[3, 12, "\x1b[48;5;24m", "\x1b[49m", "\x1b[48;5;24m"]]),
        );
    }

    public function testApplyAllClampsExactlyLikeApply(): void
    {
        // Negative start, end past width, and a zero-width span — each must
        // behave as the sequential apply() did.
        $this->assertSame(
            LineDecorator::apply('abcdef', -3, 2, "\x1b[7m", "\x1b[27m"),
            LineDecorator::applyAll('abcdef', [[-3, 2, "\x1b[7m", "\x1b[27m", null]]),
        );
        $this->assertSame(
            LineDecorator::apply('abcdef', 4, 99, "\x1b[7m", "\x1b[27m"),
            LineDecorator::applyAll('abcdef', [[4, 99, "\x1b[7m", "\x1b[27m", null]]),
        );
        $this->assertSame('abcdef', LineDecorator::applyAll('abcdef', [[3, 3, "\x1b[7m", "\x1b[27m", null]]), 'a zero-width span is dropped');
        $this->assertSame('abcdef', LineDecorator::applyAll('abcdef', [[5, 2, "\x1b[7m", "\x1b[27m", null]]), 'an inverted span is dropped');
    }

    public function testApplyAllOpensInApplicationOrderAtASharedStart(): void
    {
        $a = ['open' => "\x1b[41m", 'close' => "\x1b[49m"];
        $b = ['open' => "\x1b[7m", 'close' => "\x1b[27m"];

        $out = LineDecorator::applyAll('abcdef', [
            [1, 4, $a['open'], $a['close'], null],
            [1, 3, $b['open'], $b['close'], null],
        ]);

        // a | A B bc | close B, restore A | d | close A | ef
        $this->assertSame('a'.$a['open'].$b['open'].'bc'.$b['close'].$a['open'].'d'.$a['close'].'ef', $out);
    }

    public function testApplyAllClosesInReverseApplicationOrderAtASharedEnd(): void
    {
        $a = ['open' => "\x1b[41m", 'close' => "\x1b[49m"];
        $b = ['open' => "\x1b[7m", 'close' => "\x1b[27m"];

        $out = LineDecorator::applyAll('abcdef', [
            [0, 4, $a['open'], $a['close'], null],
            [2, 4, $b['open'], $b['close'], null],
        ]);

        $this->assertSame($a['open'].'ab'.$b['open'].'cd'.$b['close'].$a['close'].'ef', $out);
    }

    public function testApplyAllSortsBoundariesFromUnorderedSpans(): void
    {
        $a = ['open' => "\x1b[41m", 'close' => "\x1b[49m"];
        $b = ['open' => "\x1b[7m", 'close' => "\x1b[27m"];

        // B supplied first but starts later: the sweep must order by column.
        $out = LineDecorator::applyAll('abcdef', [
            [3, 5, $b['open'], $b['close'], null],
            [0, 2, $a['open'], $a['close'], null],
        ]);

        $this->assertSame($a['open'].'ab'.$a['close'].'c'.$b['open'].'de'.$b['close'].'f', $out);
    }

    public function testApplyAllRestoresAnOuterSpanAfterAnInnerClose(): void
    {
        // The sequential fold LOST the outer background here: the inner
        // close's \x1b[49m reset it and nothing re-opened it. The sweep
        // re-opens every still-active span after a close.
        $outer = ['open' => "\x1b[48;5;24m", 'close' => "\x1b[49m"];
        $inner = ['open' => "\x1b[48;5;100m", 'close' => "\x1b[49m"];

        $out = LineDecorator::applyAll('abcdef', [
            [0, 6, $outer['open'], $outer['close'], null],
            [2, 4, $inner['open'], $inner['close'], null],
        ]);

        $this->assertSame($outer['open'].'ab'.$inner['open'].'cd'.$inner['close'].$outer['open'].'ef'.$outer['close'], $out);
    }

    public function testApplyAllReappliesActiveBackgroundsAfterAnInteriorReset(): void
    {
        $line = 'ab'."\x1b[0m".'cd'; // a raw reset inside the span range
        $r1 = "\x1b[48;5;24m";
        $r2 = "\x1b[48;5;100m";

        $out = LineDecorator::applyAll($line, [
            [0, 4, $r1, "\x1b[49m", $r1],
            [0, 4, $r2, "\x1b[49m", $r2],
        ]);

        // Both active backgrounds re-append after the interior reset, in
        // application order (the later span's code lands last and wins) —
        // the expectation is built with the vendor helper itself so the
        // reapply placement stays the vendor's business.
        $middle = AnsiUtils::reapplyBackgroundAfterResets($line, $r1.$r2);
        $this->assertSame($r1.$r2.$middle."\x1b[49m\x1b[49m", $out);
    }

    public function testApplyAllKeepsSpansAfterADroppedOne(): void
    {
        // A degenerate span must be skipped, not abort the clamp loop.
        $out = LineDecorator::applyAll('abcdef', [
            [5, 2, "\x1b[41m", "\x1b[49m", null], // inverted: dropped
            [0, 2, "\x1b[7m",  "\x1b[27m", null], // must still apply
        ]);

        $this->assertSame("\x1b[7m".'ab'."\x1b[27m".'cdef', $out);
    }

    public function testApplyAllChainsBackgroundsInApplicationOrderNotOpenOrder(): void
    {
        // The lower-priority span OPENS LATER than the higher-priority one:
        // the bg chain must still follow application order (the later span's
        // code lands last and wins), not the order the spans became active.
        $line = 'abc'."\x1b[0m".'def'; // interior reset inside [3,6)
        $r1 = "\x1b[48;5;24m";
        $r2 = "\x1b[48;5;100m";

        $out = LineDecorator::applyAll($line, [
            [3, 6, $r1, "\x1b[49m", $r1], // application index 0, opens second
            [0, 6, $r2, "\x1b[49m", $r2], // application index 1, opens first
        ]);

        $segment = AnsiUtils::reapplyBackgroundAfterResets(AnsiUtils::sliceByColumn($line, 3, 3), $r1.$r2);
        $this->assertSame(
            $r2.AnsiUtils::sliceByColumn($line, 0, 3).$r1.$segment."\x1b[49m\x1b[49m",
            $out,
        );
    }

    public function testApplyAllGrowsLinearlyInSpanCount(): void
    {
        // The regression this method exists for: 12 same-word spans on one
        // prose line produced >120KB (and OOM at higher counts) through the
        // sequential fold. Linear growth stays in the low kilobytes.
        $line = str_repeat('the quick brown fox jumps over the lazy dog ', 3); // 135 cols
        $spans = [];
        for ($i = 0; $i < 12; ++$i) {
            $start = $i * 10;
            $spans[] = [$start, $start + 3, "\x1b[48;5;60m", "\x1b[49m", "\x1b[48;5;60m"];
        }

        $out = LineDecorator::applyAll($line, $spans);

        $this->assertLessThan(2_048, \strlen($out), 'span application must stay linear, never exponential');
        $this->assertSame($line, AnsiUtils::stripAnsiCodes($out), 'the visible text is untouched');
    }
}
