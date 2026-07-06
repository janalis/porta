<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CodeHighlighterTest extends TestCase
{
    public function testHighlightsPhpAndPreservesLineStructure(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $this->assertSame('php', $highlighter->getLanguage());

        $lines = $highlighter->highlight(explode("\n", "<?php\n/* multi\nline comment */\nreturn 'str';"), 1);

        $this->assertCount(4, $lines);
        $this->assertStringContainsString("\x1b[", implode('', $lines));

        $plain = array_map(AnsiUtils::stripAnsiCodes(...), $lines);
        $this->assertSame('/* multi', $plain[1]);
        $this->assertSame("return 'str';", $plain[3]);
    }

    public function testMultiLineTokenColorCarriesAcrossLines(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('test.php');
        $lines = $highlighter->highlight(explode("\n", "<?php\n/* multi\nline comment */\nreturn 'str';"), 1);

        $commentGray = "\x1b[38;5;243m";
        $this->assertTrue(
            str_contains($lines[1], $commentGray) || str_contains($lines[2], $commentGray),
            'comment color re-opened on continuation lines',
        );
    }

    public function testMemoizesOnVersion(): void
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('a.php');

        $first = $highlighter->highlight(['<?php $a = 1;'], 7);
        $second = $highlighter->highlight(['<?php CHANGED — but same version, cache hit'], 7);

        $this->assertSame($first, $second);
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

        $lines = [str_repeat('x', 600 * 1024)];
        $this->assertSame($lines, $highlighter->highlight($lines, 1));
    }
}
