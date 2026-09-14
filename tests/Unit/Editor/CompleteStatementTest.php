<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\LineOperations;
use Porta\Editor\SelectionMode;
use Porta\Editor\StatementSyntax;
use Porta\Widget\CodeEditorWidget;

/**
 * `Ctrl+Shift+Enter` — close the brackets, end the statement or open the block,
 * and move on.
 *
 * Almost every test puts the caret MID-LINE, because the verb ignores the
 * column and one written with the caret at the end would pass against a much
 * dumber implementation.
 */
final class CompleteStatementTest extends TestCase
{
    private static function php(): StatementSyntax
    {
        $php = StatementSyntax::forGrammar('php');
        Assert::assertInstanceOf(StatementSyntax::class, $php, 'php is in the table');

        return $php;
    }

    // --- closing what the line opened ---------------------------------------

    public function testUnclosedBracketsAreClosedInTheOrderTheyWereOpened(): void
    {
        // A stack, not a count: `([` needs `])`, and only a stack knows which
        // way round.
        $this->assertSame('])', LineOperations::missingClosers('foo([a'));
        $this->assertSame(')', LineOperations::missingClosers('foo(bar(1), 2'));
        $this->assertSame('', LineOperations::missingClosers('foo(bar)'));
    }

    /**
     * A closer with no opener pops nothing. The line is already wrong in a way
     * this verb cannot fix, and inventing an opener for it would make it worse.
     */
    public function testAStrayCloserDoesNotInventAnOpener(): void
    {
        $this->assertSame('', LineOperations::missingClosers('a)b'));
        $this->assertSame(')', LineOperations::missingClosers('a)b(c'));
    }

    public function testItClosesBracketsAndTerminatesInOneKeystroke(): void
    {
        $doc = new Document("    \$x = foo(1, bar(2\n");
        $doc->setCursor(0, 10); // mid-line

        $doc->completeStatement(self::php());

        $this->assertSame('    $x = foo(1, bar(2));', $doc->getLine(0));
        $this->assertSame(1, $doc->cursorLine);
        $this->assertSame(4, $doc->cursorCol, 'a fresh line at the same indent');
    }

    // --- terminating -------------------------------------------------------

    public function testAStatementThatAlreadyEndsInOneDoesNotGetASecond(): void
    {
        $doc = new Document("    return 1;\n");
        $doc->setCursor(0, 6);

        $doc->completeStatement(self::php());

        $this->assertSame('    return 1;', $doc->getLine(0));
        $this->assertSame('    ', $doc->getLine(1), 'still moves on, which is the other half of the verb');
    }

    /**
     * A line ending in an OPERATOR is a statement that continues, and
     * terminating it there cuts it in half.
     *
     * `foo(` is deliberately not in this list, and writing it there was the
     * first draft's mistake: its bracket closes to `foo()`, which is a whole
     * statement and does want its semicolon. The rule is about what the line
     * ends with AFTER the closers land, not before.
     */
    public function testALineEndingInAnOperatorIsNotTerminated(): void
    {
        foreach (['    $a = 1 +', '    $a = $b &&', '    $x =', '    $a = $b .'] as $text) {
            $doc = new Document($text."\n");
            $doc->setCursor(0, 5);
            $doc->completeStatement(self::php());
            $this->assertStringEndsNotWith(';', \rtrim($doc->getLine(0)), $text);
        }
    }

    /** And the converse, which is the same rule seen from the other side. */
    public function testAnUnclosedCallIsAWholeStatementOnceItIsClosed(): void
    {
        $doc = new Document("    foo(\n");
        $doc->setCursor(0, 4);

        $doc->completeStatement(self::php());

        $this->assertSame('    foo();', $doc->getLine(0));
    }

    public function testTheClosersLandBeforeTheTerminatorAndNotAfterIt(): void
    {
        $doc = new Document("    \$a = compact('x'\n");
        $doc->setCursor(0, 4);

        $doc->completeStatement(self::php());

        $this->assertSame("    \$a = compact('x');", $doc->getLine(0), 'not `;)`');
    }

    // --- opening a block ---------------------------------------------------

    public function testABlockKeywordOpensABlockInsteadOfTerminating(): void
    {
        $doc = new Document("    if (\$x > 1\n");
        $doc->setCursor(0, 7);

        $doc->completeStatement(self::php());

        $this->assertSame(['    if ($x > 1) {', '        ', '    }', ''], $doc->lines);
        $this->assertSame(1, $doc->cursorLine);
        $this->assertSame(8, $doc->cursorCol, 'inside the block');
    }

    /**
     * Python's block IS the indent, so there is a line to open under the header
     * and nothing to put below it — and the colon binds to the condition where
     * a brace is spaced off it.
     */
    public function testALanguageWhoseBlockHasNoCloserGetsNoClosingLine(): void
    {
        $python = StatementSyntax::forGrammar('python');
        $this->assertInstanceOf(StatementSyntax::class, $python, 'python is in the table');

        $doc = new Document("    if x > 1\n");
        $doc->setCursor(0, 6);

        $doc->completeStatement($python);

        $this->assertSame(['    if x > 1:', '        ', ''], $doc->lines);
    }

    /**
     * Go writes no semicolons — the compiler inserts them and gofmt takes them
     * away — but it still braces. The two halves of the table are independent,
     * and a language exercising exactly one of them is the case that proves it.
     */
    public function testALanguageWithBracesAndNoTerminatorGetsOnlyTheBraces(): void
    {
        $go = StatementSyntax::forGrammar('go');
        $this->assertInstanceOf(StatementSyntax::class, $go, 'go is in the table');

        $doc = new Document("    y := f(1\n");
        $doc->setCursor(0, 4);
        $doc->completeStatement($go);
        $this->assertSame('    y := f(1)', $doc->getLine(0), 'closed, not terminated');

        $doc = new Document("    if x > 1\n");
        $doc->setCursor(0, 4);
        $doc->completeStatement($go);
        $this->assertSame('    if x > 1 {', $doc->getLine(0));
    }

    // --- the wire ----------------------------------------------------------

    public function testTheChordReachesTheVerbAndDoesNothingInALanguageWithNoStatements(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('demo.php', "    return 1\n");
        $editor->getDocument()->setCursor(0, 6);

        $editor->handleInput("\x1b[13;6u"); // ctrl+shift+enter
        $this->assertSame('    return 1;', $editor->getDocument()->getLine(0));

        // The negative control, and it has to be a language the table says
        // `null` for rather than one merely absent from it.
        $editor->openFile('data.json', "  {\"a\": 1\n");
        $editor->getDocument()->setCursor(0, 4);
        $editor->handleInput("\x1b[13;6u");
        $this->assertSame(['  {"a": 1', ''], $editor->getDocument()->lines, 'JSON has no statement to complete');
    }

    public function testASelectionIsCollapsedAndNotDeleted(): void
    {
        $doc = new Document("    return 1\n");
        $doc->setCursor(0, 4);
        $doc->beginSelect();
        $doc->setCursor(0, 10, SelectionMode::Keep);

        $doc->completeStatement(self::php());

        $this->assertSame('    return 1;', $doc->getLine(0), 'the selected text survives');
        $this->assertFalse($doc->hasSelection());
    }

    public function testOneKeypressIsOneUndoStep(): void
    {
        $doc = new Document("    if (\$x\n");
        $doc->setCursor(0, 4);

        $doc->completeStatement(self::php());
        $this->assertSame(['    if ($x) {', '        ', '    }', ''], $doc->lines);

        $this->assertTrue($doc->undo());
        $this->assertSame(['    if ($x', ''], $doc->lines, 'the brace, the two lines and the closer come back together');
    }

    /**
     * Trailing whitespace is not part of the statement.
     *
     * Without the leading `rtrim` the terminator lands after it —
     * `return 1   ;` — which is invisible on screen and shows up in the diff.
     * The same rule as the block-comment verb's trailing cell, and the same
     * kind of test: one no assertion about the VISIBLE text would catch.
     */
    public function testTrailingWhitespaceIsTrimmedBeforeTheTerminator(): void
    {
        $doc = new Document("    return 1   \n");
        $doc->setCursor(0, 6);

        $doc->completeStatement(self::php());

        $this->assertSame('    return 1;', $doc->getLine(0));
    }

    /**
     * And the same rule stated where it is implemented, because
     * {@see LineOperations::wantsTerminator()} is public and the caller's own
     * trim is not its contract: a line ending in an operator and then a space
     * still continues.
     */
    public function testWantsTerminatorLooksPastTrailingWhitespace(): void
    {
        $this->assertFalse(LineOperations::wantsTerminator('$a = 1 +   ', ';'), 'an operator, then spaces');
        $this->assertFalse(LineOperations::wantsTerminator('return 1;  ', ';'), 'already terminated, then spaces');
        $this->assertTrue(LineOperations::wantsTerminator('return 1  ', ';'));
        $this->assertFalse(LineOperations::wantsTerminator('   ', ';'), 'a blank line is not a statement');
    }

    /**
     * A line that already has its block does not get a second one.
     *
     * Pressing the key twice was all it took: `if ($x) {` became
     * `if ($x) { {`, and Python's `if x:` became `if x::`. The keyword test
     * says "this line OPENS a block"; it took a second rule to say "and has not
     * already", which is the mirror of the one the terminator has had all along.
     *
     * The one-line form counts as having its block too — `if ($x) { run(); }`
     * ends in the CLOSER, and a brace after it would be nonsense.
     */
    public function testALineThatAlreadyHasItsBlockGainsNothing(): void
    {
        $python = StatementSyntax::forGrammar('python');
        $this->assertInstanceOf(StatementSyntax::class, $python, 'python is in the table');

        foreach ([
            ['    if ($x) {', self::php()],
            ['    if ($x) {}', self::php()],
            ['    if ($x) { run(); }', self::php()],
            ['    if x:', $python],
        ] as [$text, $syntax]) {
            $doc = new Document($text."\n");
            $doc->setCursor(0, 5);

            $doc->completeStatement($syntax);

            $this->assertSame($text, $doc->getLine(0), $text);
            $this->assertSame('    ', $doc->getLine(1), 'and it still moves on: '.$text);
        }
    }

    /**
     * The two ways a line can already have its brace and not look like it.
     *
     * Asserted on {@see LineOperations::wantsBlockOpener()} directly, because
     * the caller trims before asking and so cannot reach either: a line with
     * TRAILING whitespace still ends in its brace, and a brace written tight
     * against the condition (`if($x){`) still is one — the table stores the
     * opener as ` {`, space included, so an untrimmed comparison misses it and
     * writes a second.
     */
    public function testABraceCountsHoweverItIsSpaced(): void
    {
        $this->assertFalse(LineOperations::wantsBlockOpener('if ($x) {   ', ' {', '}'), 'trailing whitespace');
        $this->assertFalse(LineOperations::wantsBlockOpener('if($x){', ' {', '}'), 'no space before it');
        $this->assertFalse(LineOperations::wantsBlockOpener('   ', ' {', '}'), 'a blank line opens nothing');

        $this->assertTrue(LineOperations::wantsBlockOpener('if ($x)', ' {', '}'), 'and a line with no brace still wants one');
    }

    /**
     * A line that closes one block and opens the next already has its brace.
     * `} else {` must not gain a second one — the keyword is not the head of
     * the line, and that is exactly what the anchored match is for.
     */
    public function testAnElseThatAlreadyCarriesItsBraceGainsNothing(): void
    {
        $doc = new Document("    } else {\n");
        $doc->setCursor(0, 6);

        $doc->completeStatement(self::php());

        $this->assertSame('    } else {', $doc->getLine(0), 'unchanged');
        $this->assertSame('    ', $doc->getLine(1), 'and it still moves on');
    }

    /**
     * On a blank line INSIDE an indented block, the caret keeps its place.
     *
     * `rtrim()` finds the statement and leaves nothing on a whitespace-only
     * line, so taking the indent from the trimmed text dropped the caret to
     * column 0 — where `Enter` and `Shift+Enter` both stay put. The indent
     * comes from the original line and the statement from the trimmed one,
     * because on this input the two disagree.
     *
     * Found by running every verb this session added against a handful of
     * degenerate documents and reading the output; nothing else was looking.
     */
    public function testABlankIndentedLineKeepsTheCaretWhereItWas(): void
    {
        foreach (['    ', "\t\t"] as $indent) {
            $doc = new Document($indent."\n");
            $doc->setCursor(0, \strlen($indent));

            $doc->completeStatement(self::php());

            $this->assertSame('', $doc->getLine(0), 'the blank line loses its trailing whitespace');
            $this->assertSame($indent, $doc->getLine(1), 'and the caret line keeps the indent');
            $this->assertSame(\strlen($indent), $doc->cursorCol);
        }
    }

    /**
     * The limit, written down as a test rather than only as a docblock: the
     * bracket count does not know about strings, so a paren inside one is
     * counted. A parser would not; Porta has none here, and the mitigation is
     * that this is an explicit keystroke and one undo step.
     */
    public function testAParenInsideAStringIsCountedAndThatIsKnown(): void
    {
        $doc = new Document("    echo \"a(b\"\n");
        $doc->setCursor(0, 4);

        $doc->completeStatement(self::php());

        $this->assertSame('    echo "a(b");', $doc->getLine(0), 'the documented limit, not a surprise');
    }
}
