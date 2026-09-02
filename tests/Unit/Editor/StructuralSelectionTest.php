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
        // No "\n    body\n" rung between them: the block's contents differ
        // from the line by a newline and some indentation only, and a rung
        // that changes nothing you can see is a press that did nothing.
        $this->assertSame("{\n    body\n}", $steps[2], 'whole block');
    }

    public function testNestedBrackets(): void
    {
        $text = 'a(b(c)d)e';
        // caret on inner "c". `b(c)` and `a(b(c)d)` are the CALL rungs — a
        // bracket's name belongs with its parens, and every language server
        // offers them; without them the ladder jumps from `(c)` straight past
        // the call that owns it.
        $this->assertSame(
            ['c', '(c)', 'b(c)', 'b(c)d', '(b(c)d)', 'a(b(c)d)', 'a(b(c)d)e'],
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

    public function testTiedSmallestCandidateKeepsTheFirstOneFound(): void
    {
        // The bracket pair's inner span [1,2] (the newline) and the single-char
        // line span [0,1] are both size 1 and both genuinely enclose the caret.
        // The bracket candidate is discovered first — it must win the tie, not
        // be silently replaced by the line span found afterward.
        $this->assertSame([1, 2], StructuralSelection::expand("(\n)", 1, 1));
    }

    /**
     * The argument you are standing in, before the whole argument list.
     * Measured against phpantom's own `selectionRange` ladder, which offers
     * exactly this rung; without it, `trim($name)` is unreachable and Ctrl+W
     * jumps from the word straight to every argument at once.
     */
    public function testASingleArgumentIsARungBeforeTheWholeArgumentList(): void
    {
        $text = 'sprintf(\'Hello, %s!\', trim($name))';

        $steps = $this->ladder($text, 24); // caret on "trim"

        $this->assertSame('trim', $steps[0]);
        $this->assertSame('trim($name)', $steps[1], 'the one argument, with no leading space off the comma');
        $this->assertSame('\'Hello, %s!\', trim($name)', $steps[2], 'then all of them');
        $this->assertSame('sprintf(\'Hello, %s!\', trim($name))', $steps[4], 'and the call that owns them');
    }

    /** A comma inside a nested call or a literal does not split an argument. */
    public function testCommasNestedInBracketsOrStringsDoNotSplit(): void
    {
        $text = 'f(a, g(b, c), d)';

        $this->assertSame('b, c', $this->ladder($text, 11)[1], 'the inner brackets hold their own argument list');
        $this->assertSame('g(b, c)', $this->ladder($text, 11)[3], 'and the whole inner call is ONE argument of the outer');
        $this->assertSame('a, g(b, c), d', $this->ladder($text, 11)[4], 'its comma never split the outer list');
        $this->assertSame("'x, y'", $this->ladder("f(a, 'x, y', b)", 7)[2], 'the comma inside the literal is not a separator either');
    }

    /**
     * A brace interior and the line inside it used to be two rungs differing
     * only by a newline and some indentation — a press of Ctrl+W that visibly
     * did nothing. Two of the eleven rungs on a plain method were that.
     */
    public function testABraceInteriorIsNotARungOfItsOwnWhenItAddsOnlyWhitespace(): void
    {
        $text = "if (x) {\n    body();\n}";

        $steps = $this->ladder($text, 13); // caret on "body"

        $this->assertNotContains("\n    body();\n", $steps, 'the whitespace-only rung is gone');
        $this->assertSame(['body', 'body();', '    body();', "{\n    body();\n}", "if (x) {\n    body();\n}"], $steps);
    }

    /** But a pair holding nothing but whitespace keeps its untrimmed interior. */
    public function testAPairOfNothingButWhitespaceStillHasAnInteriorRung(): void
    {
        $this->assertSame([1, 2], StructuralSelection::expand("(\n)", 1, 1), 'trimmed to empty, so the untrimmed interior wins');
    }

    /**
     * An escaped quote inside an argument's literal must not end it early: the
     * separator walk would then see the rest of the string as code and split
     * the argument list on a comma that is only text.
     */
    public function testAnEscapedQuoteInsideAnArgumentLiteralDoesNotEndIt(): void
    {
        $text = 'f(a, \'x\\\', y\', b)';

        $steps = $this->ladder($text, 15); // caret on the trailing "b"

        $this->assertSame('b', $steps[0]);
        $this->assertSame("a, 'x\\', y', b", $steps[1], 'three arguments were never four');
    }

    /** A literal that never closes must not run the separator walk off the end. */
    public function testAnUnterminatedLiteralInsideBracketsTerminatesTheWalk(): void
    {
        $text = "f(a, 'oops, b)";

        $this->assertSame('a', $this->ladder($text, 2)[0], 'the first argument is still reachable');
    }

    /**
     * A pair is "already swallowed" only when the range matches it on BOTH
     * ends. Matching one end is an ordinary non-enclosing pair that must
     * contribute nothing — with `||` there, `ab(cd)` is offered as a call rung
     * for a range that starts inside the parens, jumping the ladder past the
     * line it is standing on.
     */
    public function testAPairIsSwallowedOnlyWhenTheRangeMatchesBothOfItsEnds(): void
    {
        //                     zz ab(cd)
        // offsets             0123456789   range [6,9] = "cd)" — ends with the
        //                                  pair but does not start with it.
        $this->assertSame([0, 9], StructuralSelection::expand('zz ab(cd)', 6, 9), 'the line, not the call around the pair');
    }

    /**
     * A swallowed pair skips to the NEXT pair, it does not end the scan. The
     * inner pair here names nothing, so it contributes no call rung — and if
     * that `continue` were a `break`, the enclosing pair would never be looked
     * at and the ladder would jump straight to the whole line.
     */
    public function testASwallowedPairSkipsToTheNextOneRatherThanEndingTheScan(): void
    {
        //                     f((x)y)
        // offsets             0123456    range [2,5] = "(x)", swallowing the inner pair
        $this->assertSame([2, 6], StructuralSelection::expand('f((x)y)', 2, 5), 'the enclosing pair still contributes its interior');
    }
}
