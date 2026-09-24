<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\HoverMarkdown;

final class HoverMarkdownTest extends TestCase
{
    /** The marks a terminal cannot render go; the words stay. */
    public function testProseLosesItsMarkup(): void
    {
        $this->assertSame(
            "Greeter\n\nGreets the name, see docs.\nbold and strong",
            HoverMarkdown::plain("## Greeter\n\n  Greets the `name`, see [docs](https://x.test).\n**bold** and __strong__"),
        );
    }

    /** A code block keeps its lines, indented to stand apart; a rule becomes a gap; blank runs collapse. */
    public function testCodeBlocksAreIndentedAndRulesGap(): void
    {
        $this->assertSame(
            "  function greet(): string\n  {}\n\nReturns it.\n\n    indented stays",
            HoverMarkdown::plain("```php\nfunction greet(): string\n{}\n```\n---\nReturns it.\n\n\n\n    indented stays\n"),
        );
    }
}
