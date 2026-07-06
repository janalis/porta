<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;

final class CodeHighlighterFallbackTest extends TestCase
{
    public function testParserExceptionFallsBackToPlainLines(): void
    {
        $highlighter = new CodeHighlighter(static function (): string {
            throw new \RuntimeException('parser exploded');
        });
        $highlighter->setFile('x.php');

        $this->assertSame(['<?php', 'echo 1;'], $highlighter->highlight(['<?php', 'echo 1;'], 1));
    }

    public function testLineCountMismatchFallsBackToPlainLines(): void
    {
        $highlighter = new CodeHighlighter(static fn (): string => 'one line no matter what');
        $highlighter->setFile('x.php');

        $this->assertSame(['a', 'b'], $highlighter->highlight(['a', 'b'], 1));
    }
}
