<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Tests\Support\ClosureHighlightProvider;

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
    public function testALineWithAMalformedEscapeIsRenderedPlain(): void
    {
        $source = "#[Autowire(service: 'x')]";
        $split = "#[Autowire(\x1b[38;5;117mservice\x1b[\x1b[38;5;117m39m\x1b[39m: 'x')]";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $split));
        $highlighter->setFile('x.php');

        $this->assertSame([$source], $highlighter->highlight([$source], 1));
    }

    public function testONLYTheOffendingLineLosesItsColours(): void
    {
        // Lines are independently renderable here, so a backend that breaks one
        // costs its own colours and nothing else's.
        $good = 'echo 1;';
        $bad = 'echo 2;';
        $markup = "\x1b[32m{$good}\x1b[39m\n\x1b[\x1b[32m39m{$bad}";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $markup));
        $highlighter->setFile('x.php');

        $rows = $highlighter->highlight([$good, $bad], 1);

        $this->assertStringContainsString("\x1b[32m", (string) $rows[0], 'the sound line keeps its colours');
        $this->assertSame($bad, $rows[1], 'the broken one is plain, not garbled');
    }

    public function testAMarkupTRANSFORMATIONIsNotTreatedAsAFault(): void
    {
        // The JSON backend strips diff markers on purpose, so markup is NOT
        // required to reduce to its input byte-for-byte. Only a broken ESCAPE
        // is a fault; changed text is the backend doing its job.
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => "\x1b[32mx\x1b[39m"));
        $highlighter->setFile('x.php');

        $this->assertSame(["\x1b[32mx\x1b[39m"], $highlighter->highlight(['{+ x +}'], 1));
    }

    public function testABrokenLinesCodesAreNotCarriedIntoTheNextLine(): void
    {
        // Its codes are the thing being distrusted; carrying them onward would
        // spread the fault instead of containing it.
        $markup = "\x1b[\x1b[32m39mfirst\nsecond";
        $highlighter = new CodeHighlighter(new ClosureHighlightProvider(static fn (): string => $markup));
        $highlighter->setFile('x.php');

        $rows = $highlighter->highlight(['first', 'second'], 1);

        $this->assertSame(['first', 'second'], $rows);
    }
}
