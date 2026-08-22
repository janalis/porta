<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\StructuralSelection;

final class StructuralSelectionTest extends TestCase
{
    /**
     * Repeatedly expand from a caret and collect the selected substrings, so a
     * whole hierarchy can be asserted in one readable list.
     *
     * @return list<string>
     */
    private function ladder(string $text, int $caret): array
    {
        $steps = [];
        $start = $caret;
        $end = $caret;
        // Guard against a runaway loop; real ladders are only a few steps.
        for ($i = 0; $i < 20; ++$i) {
            $next = StructuralSelection::expand($text, $start, $end);
            if (null === $next) {
                break;
            }
            [$start, $end] = $next;
            $steps[] = substr($text, $start, $end - $start);
        }

        return $steps;
    }

    public function testCaretExpandsToWordThenBracketThenLineThenDocument(): void
    {
        $text = "foo(bar)\nbaz";
        // caret inside "bar"
        $this->assertSame(
            ['bar', '(bar)', 'foo(bar)', "foo(bar)\nbaz"],
            $this->ladder($text, 5),
        );
    }

    public function testExpandFromCaretAtWordEnd(): void
    {
        $text = 'hello world';
        // caret right after "hello"
        $steps = $this->ladder($text, 5);
        $this->assertSame('hello', $steps[0]);
        $this->assertSame('hello world', $steps[1]);
    }

    public function testStringLiteralContentsThenQuotes(): void
    {
        $text = "echo 'hi there';";
        // caret inside the string, on "there"
        $this->assertSame(
            ['there', 'hi there', "'hi there'", "echo 'hi there';"],
            $this->ladder($text, 9),
        );
    }

    public function testBracketsInsideStringAreIgnored(): void
    {
        $text = "x = '(not a bracket'";
        // caret inside the string after the fake "("
        $steps = $this->ladder($text, 12);
        // The stray '(' is inside the string, so the first structural jump is
        // the string contents — never a bracket pair.
        $this->assertSame('(not a bracket', $steps[1]);
        $this->assertSame("'(not a bracket'", $steps[2]);
    }

    public function testMultiLineBlockExpandsLineBeforeBlock(): void
    {
        $text = "{\n    body\n}";
        // caret on "body" (offset 8, the 'd')
        $steps = $this->ladder($text, 8);
        $this->assertSame('body', $steps[0]);
        $this->assertSame('    body', $steps[1], 'line before enclosing block');
        $this->assertSame("\n    body\n", $steps[2], 'block contents');
        $this->assertSame("{\n    body\n}", $steps[3], 'whole block');
    }

    public function testNestedBrackets(): void
    {
        $text = 'a(b(c)d)e';
        // caret on inner "c"
        $this->assertSame(
            ['c', '(c)', 'b(c)d', '(b(c)d)', 'a(b(c)d)e'],
            $this->ladder($text, 4),
        );
    }

    public function testWholeDocumentReturnsNull(): void
    {
        $text = 'abc';
        $this->assertNull(StructuralSelection::expand($text, 0, 3));
    }

    public function testCaretOnNonWordFallsThroughToLine(): void
    {
        $text = '   +   ';
        // caret on the '+' which is not a word char and not bracketed
        $steps = $this->ladder($text, 3);
        $this->assertSame('   +   ', $steps[0], 'first expansion is the line');
        $this->assertNull(StructuralSelection::expand($text, 0, \strlen($text)));
    }

    public function testUnterminatedStringDoesNotHang(): void
    {
        $text = "a = 'oops";
        $steps = $this->ladder($text, 6);
        // No closing quote: string scan bails, so the caret expands to word/line.
        $this->assertSame('oops', $steps[0]);
        $this->assertSame("a = 'oops", $steps[1]);
    }

    public function testUnbalancedCloserIsIgnored(): void
    {
        $text = 'a)b(c)';
        // caret on "c" inside the real pair; the earlier ')' has no opener.
        $steps = $this->ladder($text, 4);
        $this->assertSame('c', $steps[0]);
        $this->assertSame('(c)', $steps[1]);
    }

    public function testOffsetsClampToTextBounds(): void
    {
        $text = 'hi';
        $next = StructuralSelection::expand($text, -5, 99);
        $this->assertNull($next, 'clamped range already spans the document');
    }

    public function testBracketsAfterAnEarlierStringLiteralAreStillScanned(): void
    {
        // Finishing a string literal must resume scanning, not stop the whole
        // pass: a bracket pair that comes later in the text must still be found.
        $this->assertSame([4, 7], StructuralSelection::expand("'x' (y)", 5, 6));
    }

    public function testEscapeSkipAdvancesByExactlyTwoBytes(): void
    {
        // Skipping one byte too many after a backslash escape would overshoot
        // the real closing quote entirely — text between the escape and a
        // second, unrelated quote right after it must not be swallowed.
        $this->assertSame([1, 4], StructuralSelection::expand("'+\\''+++", 2, 2));
    }

    public function testEmptyStringLiteralIsStillRecognizedAsAPair(): void
    {
        // The scan must start checking for the closing quote at the very next
        // byte: skipping it would miss a zero-length '' literal entirely.
        $this->assertSame([1, 3], StructuralSelection::expand("a''b", 2, 2));
    }

    public function testCaretRightBeforeAnOpeningBracketDoesNotTreatItAsEnclosing(): void
    {
        // The caret sits exactly AT the opening "(" (offset 1), not inside it.
        // A non-strict comparison would wrongly treat that bracket pair as
        // enclosing the caret and jump straight to its contents, skipping the
        // line-level step of the ladder.
        $this->assertSame([0, 7], StructuralSelection::expand(' (abc)y'."\n".'def', 1, 1));
    }

    public function testNegativeStartClampsToZeroNotNegativeOne(): void
    {
        // A start clamped to -1 instead of 0 would make later substr() calls
        // count from the end of the string, silently corrupting the range.
        $this->assertSame([0, 5], StructuralSelection::expand('hello', -3, 2));
    }

    public function testEscapedQuoteInsideStringIsSkipped(): void
    {
        $text = "s = 'a\\'b'";
        // caret on "b" after the escaped quote
        $steps = $this->ladder($text, 8);
        $this->assertSame("a\\'b", $steps[1], 'escaped quote kept inside the literal');
    }
}
