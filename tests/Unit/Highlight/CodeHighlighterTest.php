<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\PlainTerminalTheme;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Tests\Support\ClosureHighlightProvider;
use Porta\Tests\Support\Probe;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CodeHighlighterTest extends TestCase
{
    public function testHighlightsPhpAndPreservesLineStructure(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $this->assertSame('php', $highlighter->getLanguage());

        $lines = $highlighter->highlight(\explode("\n", string: "<?php\n/* multi\nline comment */\nreturn 'str';"), 1);

        $this->assertCount(4, $lines);
        $this->assertStringContainsString("\x1b[", \implode('', $lines));

        $plain = \array_map(AnsiUtils::stripAnsiCodes(...), $lines);
        $this->assertSame('/* multi', $plain[1]);
        $this->assertSame("return 'str';", $plain[3]);
    }

    public function testMonochromeEmitsNoSyntaxColor(): void
    {
        $highlighter = new CodeHighlighter(syntaxTheme: new PlainTerminalTheme());
        $highlighter->setFile('test.php');
        $lines = $highlighter->highlight(\explode("\n", string: "<?php\nreturn 'str';"), 1);

        $joined = \implode('', $lines);
        $this->assertStringNotContainsString("\x1b[38;5;", $joined, 'no syntax color in monochrome');
        $this->assertSame("return 'str';", AnsiUtils::stripAnsiCodes($lines[1]));
    }

    public function testMultiLineTokenColorCarriesAcrossLines(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $lines = $highlighter->highlight(\explode("\n", string: "<?php\n/* multi\nline comment */\nreturn 'str';"), 1);

        $commentGray = "\x1b[38;5;243m";
        $this->assertTrue(
            \str_contains($lines[1], $commentGray) || \str_contains($lines[2], $commentGray),
            'comment color re-opened on continuation lines',
        );
    }

    /**
     * Every returned line must be independently renderable: the reopened
     * prefix comes FIRST (not appended after the text), and a token still
     * open at line end gets an explicit reset so the color never bleeds past
     * the line boundary.
     */
    public function testEachLineIsIndependentlyRenderableAroundAMultiLineToken(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $lines = $highlighter->highlight(\explode("\n", string: "<?php\n/* multi\nline comment */\nreturn 1;"), 1);

        $commentGray = "\x1b[38;5;243m";
        // Line 1 opens the comment (nothing carried in) but leaves it open at
        // the end, so it needs a trailing reset, not a leading one.
        $this->assertStringEndsWith("\x1b[0m", $lines[1], 'a still-open token at line end gets an explicit reset');
        // Line 2 carries the comment IN from line 1: the reopened code must be
        // the prefix, not appended after the text.
        $this->assertStringStartsWith($commentGray, $lines[2], 'the reopened code comes before the line text, not after it');
    }

    public function testPhpDocBlocksRenderAsUniformComments(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $lines = $highlighter->highlight(\explode("\n", string: "<?php\n/**\n * @param string \$name the user name\n */\nfunction f(\$name) {}"), 1);

        $commentGray = "\x1b[38;5;243m";
        $typeYellow = "\x1b[38;5;222m"; // the sub-tokenizer's TYPE color
        $varSalmon = "\x1b[38;5;210m"; // the sub-tokenizer's VARIABLE color

        // The @param line stays a single gray comment: no per-token recoloring
        // that would drop the prose ("the user name") back to default white.
        $this->assertStringContainsString($commentGray, $lines[2], 'the docblock line is comment gray');
        $this->assertStringNotContainsString($typeYellow, $lines[2], 'the docblock type is not recolored');
        $this->assertStringNotContainsString($varSalmon, $lines[2], 'the docblock variable is not recolored');
        $this->assertStringNotContainsString("\x1b[39m the user name", $lines[2], 'the prose never reverts to default fg mid-comment');
    }

    public function testMemoizesOnVersion(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('a.php');

        $first = $highlighter->highlight(['<?php $a = 1;'], 7);
        $second = $highlighter->highlight(['<?php CHANGED — but same version, cache hit'], 7);

        $this->assertSame($first, $second);
    }

    public function testInvalidateBustsTheCacheSoTheSameVersionReParses(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('a.php');

        $first = $highlighter->highlight(['<?php $a = 1;'], 7);
        $highlighter->invalidate();
        $reparsed = $highlighter->highlight(['<?php $b = 2;'], 7);

        $this->assertNotSame($first, $reparsed, 'invalidate forces a fresh parse at the same version');
    }

    public function testRefreshThemeRecolorsAfterASwitch(): void
    {
        $theme = new SwitchableTerminalTheme(new DarkTerminalTheme());
        $highlighter = new CodeHighlighter(syntaxTheme: $theme);
        $highlighter->setFile('a.php');

        $dark = $highlighter->highlight(['<?php return 1;'], 1);
        $this->assertStringContainsString("\x1b[38;5;176m", $dark[0], 'dark keyword');

        $theme->switchTo(new LightTerminalTheme());
        $highlighter->refreshTheme();
        $light = $highlighter->highlight(['<?php return 1;'], 1);

        $this->assertStringContainsString("\x1b[38;5;91m", $light[0], 'light keyword after refreshTheme');
        $this->assertStringNotContainsString("\x1b[38;5;176m", $light[0], 'no dark keyword remains');
    }

    public function testUnknownExtensionReturnsPlainLines(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('binary.dat');

        $this->assertNull($highlighter->getLanguage());
        $this->assertSame(['plain text'], $highlighter->highlight(['plain text'], 1));
    }

    public function testSpecialFilenames(): void
    {
        $highlighter = new CodeHighlighter();

        $highlighter->setFile('Dockerfile');
        $this->assertSame('dockerfile', $highlighter->getLanguage());

        $highlighter->setFile('.env.local');
        $this->assertSame('dotenv', $highlighter->getLanguage());
    }

    public function testOversizedContentFallsBackToPlain(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('big.php');

        $lines = [\str_repeat('x', 600 * 1024)];
        $this->assertSame($lines, $highlighter->highlight($lines, 1));
    }

    /** The byte cap is exclusive: content at exactly the limit still gets highlighted. */
    public function testByteCapBoundaryIsExclusive(): void
    {
        // Observed through a COLOUR rather than by prepending text: markup that
        // does not reduce to its source is refused now, so a text marker would
        // measure the guard instead of the cap.
        $marker = "\x1b[31m";
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $marker.$content."\x1b[39m");
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('a.php');

        $content = \str_repeat('x', 512 * 1024); // exactly the cap
        $result = $highlighter->highlight([$content], 1);

        $this->assertStringContainsString($marker, $result[0], 'content at exactly the cap is still highlighted, not falls back to plain');
    }

    /** A null language (unknown extension) must never construct a request for the backend. */
    public function testUnknownExtensionNeverDispatchesToTheBackend(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('binary.dat');

        $highlighter->highlight(['plain text'], 1);

        $this->assertSame(0, Probe::get($highlighter, 'strikes'), 'a null language short-circuits before ever reaching the provider dispatch');
    }

    /** invalidate() resets every version/counter field to its exact idle sentinel, not just "falsy". */
    public function testInvalidateResetsInternalStateToTheExactSentinelValues(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('a.php');
        $highlighter->highlight(['<?php'], 1);

        $highlighter->invalidate();

        $this->assertSame(-1, Probe::get($highlighter, 'cacheVersion'));
        $this->assertSame(-1, Probe::get($highlighter, 'resolvedVersion'));
        $this->assertSame(-1, Probe::get($highlighter, 'inFlightVersion'));
        $this->assertSame(0, Probe::get($highlighter, 'staleEdits'));
        $this->assertSame([], Probe::get($highlighter, 'cache'));
        $this->assertNull(Probe::get($highlighter, 'lastRawLines'));
    }
}
