<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\ClosureHighlightProvider;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CodeHighlighterFallbackTest extends TestCase
{
    public function testParserExceptionFallsBackToPlainLines(): void
    {
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static function (): string {
            throw new \RuntimeException('parser exploded');
        }));
        $highlighter->setFile('x.php');

        $this->assertSame(['<?php', 'echo 1;'], $highlighter->highlight(['<?php', 'echo 1;'], 1));
    }

    public function testLineCountMismatchFallsBackToPlainLines(): void
    {
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => 'one line no matter what'));
        $highlighter->setFile('x.php');

        $this->assertSame(['a', 'b'], $highlighter->highlight(['a', 'b'], 1));
    }

    /**
     * The real fault this guard was written for. `tempest/highlight` emits a
     * SPLIT escape on some attribute lines — `ESC [`, then a whole other
     * sequence, then a bare `39m`. The orphaned `39m` is not a colour: it is
     * visible TEXT, five columns of it, so the row renders as
     * `#[Autowire(service39m: …`, over-runs its width and drags every
     * column-placed decoration out of position.
     */
    public function testASplitEscapeIsPutBackTogetherAndKeepsItsColours(): void
    {
        // The inserted sequence is dropped, restoring the ESC [39m it split.
        // Rendering plain would be safe but would cost the line its colours,
        // which is what the reporter actually noticed.
        $source = "#[Autowire(service: 'x')]";
        $split = "#[Autowire(\x1b[38;5;117mservice\x1b[\x1b[38;5;117m39m\x1b[39m: 'x')]";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $split));
        $highlighter->setFile('x.php');

        $row = $highlighter->highlight([$source], 1)[0];

        $this->assertSame($source, AnsiUtils::stripAnsiCodes((string) $row), 'the visible text is exactly the source');
        $this->assertStringContainsString("\x1b[38;5;117m", (string) $row, 'and it kept its colours');
        $this->assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*\x1b/', (string) $row, 'with no split escape left');
    }

    public function testARepairThatWouldCHANGETheTextIsRefused(): void
    {
        // The repair is a guess about a third-party bug's shape. A guess that
        // paints the wrong thing is worse than one that declines, so it is
        // checked against the source and the line falls back to plain.
        $source = 'abc';
        $wrong = "a\x1b[\x1b[32m39mXYZ";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $wrong));
        $highlighter->setFile('x.php');

        $this->assertSame([$source], $highlighter->highlight([$source], 1));
    }

    public function testAMalformedEscapeWithNoREPAIRABLEShapeIsRenderedPlain(): void
    {
        // ESC [ running straight into another ESC with no trailing parameters
        // is broken in a way nothing can put back together.
        $source = 'abc';
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => "a\x1b[\x1b[32mbc"));
        $highlighter->setFile('x.php');

        $this->assertSame([$source], $highlighter->highlight([$source], 1));
    }

    public function testONLYTheOffendingLineLosesItsColours(): void
    {
        // Lines are independently renderable here, so a backend that breaks one
        // costs its own colours and nothing else's.
        $good = 'echo 1;';
        $bad = 'echo 2;';
        // The bad line's damage is unrepairable: nothing follows the inserted
        // sequence that could be the parameters it swallowed.
        $markup = "\x1b[32m{$good}\x1b[39m\n\x1b[\x1b[32m{$bad}";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $markup));
        $highlighter->setFile('x.php');

        $rows = $highlighter->highlight([$good, $bad], 1);

        $this->assertStringContainsString("\x1b[32m", (string) $rows[0], 'the sound line keeps its colours');
        $this->assertSame($bad, $rows[1], 'the broken one is plain, not garbled');
    }

    public function testMarkupThatCHANGESTheTextIsRefusedHoweverWellFormed(): void
    {
        // Tempest's Base language deletes `{+ … +}` from every language it
        // ships. The escapes are perfectly well formed; the TEXT is four
        // characters short, which in an editor is the worse fault of the two.
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => "\x1b[32mx\x1b[39m"));
        $highlighter->setFile('x.php');

        $this->assertSame(['{+ x +}'], $highlighter->highlight(['{+ x +}'], 1));
    }

    public function testABrokenLinesCodesAreNotCarriedIntoTheNextLine(): void
    {
        // Its codes are the thing being distrusted; carrying them onward would
        // spread the fault instead of containing it.
        $markup = "\x1b[\x1b[32mfirst\nsecond";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $markup));
        $highlighter->setFile('x.php');

        $rows = $highlighter->highlight(['first', 'second'], 1);

        $this->assertSame(['first', 'second'], $rows);
    }
}
