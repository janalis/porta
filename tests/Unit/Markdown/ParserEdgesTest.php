<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\TestCase;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\Strikethrough;
use Porta\Markdown\Node\Text;
use Porta\Tests\Support\Markdown\HtmlOracle;

/**
 * The corners the specs' examples do not reach: GFM's strikethrough runs that
 * are not delimiters, autolinks that are not domains, a destination nested
 * past the spec's limit, and a footnote that goes on past a blank line.
 */
final class ParserEdgesTest extends TestCase
{
    private static function html(string $markdown): string
    {
        return new HtmlOracle()->render(new MarkdownParser()->parse($markdown));
    }

    public function testThreeTildesStrikeNothingAndRunsOfDifferentLengthsDoNotPair(): void
    {
        $this->assertSame("<p>so ~~~x~~~ ~~a~ <del>b</del></p>\n", self::html('so ~~~x~~~ ~~a~ ~b~'), 'a line opening with three tildes would be a fence');
        $paragraph = new MarkdownParser()->parse('~~gone~~')->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertInstanceOf(Strikethrough::class, $paragraph->inlines[0]);
    }

    public function testABareAddressNeedsARealDomain(): void
    {
        $this->assertSame("<p>www.a_b.c_d http://localhost/x a@b</p>\n", self::html('www.a_b.c_d http://localhost/x a@b'));
        $this->assertSame("<p><a href=\"http://www.a_b.example.com\">www.a_b.example.com</a></p>\n", self::html('www.a_b.example.com'), 'an underscore is fine before the last two segments');
    }

    public function testADestinationNestedPastThirtyTwoParenthesesIsNoLink(): void
    {
        $deep = \str_repeat('(', times: 33).\str_repeat(')', times: 33);
        $this->assertStringNotContainsString('<a ', self::html('[x](a'.$deep.')'));
        $deep = \str_repeat('(', times: 32).\str_repeat(')', times: 32);
        $this->assertStringContainsString('<a href="a'.$deep.'">', self::html('[x](a'.$deep.')'));
    }

    public function testAFootnoteGoesOnPastABlankLineWhileItsLinesAreIndented(): void
    {
        $document = new MarkdownParser()->parse("Hi[^n].\n\n[^n]: First.\n\n    Second.\n\nAfter.\n");
        $definition = $document->blocks[1];
        $this->assertInstanceOf(FootnoteDefinition::class, $definition);
        $this->assertCount(2, $definition->blocks);
        $this->assertInstanceOf(Paragraph::class, $document->blocks[2]);
    }

    public function testALinkReferencedBeforeItsDefinitionResolves(): void
    {
        $paragraph = new MarkdownParser()->parse("[guide] and [guide][]\n\n[Guide]: /g \"T\"\n")->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $links = \array_values(\array_filter($paragraph->inlines, static fn (Inline $node): bool => $node instanceof Link));
        $this->assertCount(2, $links);
        $this->assertSame(['/g', 'T'], [$links[0]->destination, $links[0]->title]);
        $this->assertInstanceOf(Text::class, $links[1]->inlines[0]);
    }
}
