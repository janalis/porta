<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\Heading;
use Porta\Markdown\Node\Inline;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\ListItem;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\Strikethrough;
use Porta\Markdown\Node\Table;
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
        $this->assertInstanceOf(Paragraph::class, $definition->blocks[1], 'indented four, it is prose, not code');
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

    /** @return iterable<string, array{string, string}> */
    public static function corners(): iterable
    {
        yield 'CRLF ends a line' => ["a\r\nb\r\n\r\nc\r\n", "<p>a\nb</p>\n<p>c</p>\n"];
        yield 'a continuation tab' => ["aaa\n\tbbb\n", "<p>aaa\nbbb</p>\n"];
        yield 'a quote prefix ends no html' => ["> <!X\n> y\n", "<blockquote>\n<!X\ny\n</blockquote>\n"];
        yield 'kind 4 ends at >' => ["<!X>\nfoo\n", "<!X>\n<p>foo</p>\n"];
        yield 'kind 7 continues a lazy paragraph' => ["> a\n<del>\n", "<blockquote>\n<p>a\n<del></p>\n</blockquote>\n"];
        yield 'kind 7 after a heading' => ["> # h\n<del>\n", "<blockquote>\n<h1>h</h1>\n</blockquote>\n<del>\n"];
        yield 'a closing tag of script is no block' => ["</script>\n", "<p></script></p>\n"];
        yield 'nor in capitals' => ["</SCRIPT>\n", "<p></SCRIPT></p>\n"];
        yield 'only at the start' => ["<a title=\"</pre>\">\n", "<a title=\"</pre>\">\n"];
        yield 'after a hard break' => ["a\\\n*b*\n", "<p>a<br />\n<em>b</em></p>\n"];
        yield 'after a code span' => ["`a`*b*\n", "<p><code>a</code><em>b</em></p>\n"];
        yield 'after three tildes' => ["a ~~~*b*\n", "<p>a ~~~<em>b</em></p>\n"];
        yield 'after a footnote' => ["a[^1]*b*\n\n[^1]: n\n", "<p>a<sup class=\"footnote-ref\"><a href=\"#fn-1\">1</a></sup><em>b</em></p>\n<div class=\"footnote\" id=\"fn-1\">\n<p>n</p>\n</div>\n"];
        yield 'after an autolink' => ["<http://a>*b*\n", "<p><a href=\"http://a\">http://a</a><em>b</em></p>\n"];
        yield 'a footnote before a paren is a link' => ["a[^1](x)\n\n[^1]: n\n", "<p>a<a href=\"x\">^1</a></p>\n<div class=\"footnote\" id=\"fn-1\">\n<p>n</p>\n</div>\n"];
        yield 'bucket: open+3 vs close+2' => ["a*b c** d***e\n", "<p>a<em>b c** d</em>**e</p>\n"];
        yield 'bucket: lengths 1 and 3' => ["x**y a* c***\n", "<p>x<strong>y a* c</strong>*</p>\n"];
        yield 'bucket: lengths 1 and 5' => ["x**y a* c*****\n", "<p>x<strong>y a* c</strong>***</p>\n"];
        yield 'bucket: * and _ apart' => ["*a b_ c*\n", "<p><em>a b_ c</em></p>\n"];
        yield 'a shorter tilde is passed' => ["~~a ~b~~\n", "<p><del>a ~b</del></p>\n"];
        yield 'a used closer leaves the stack' => ["*a*b*c*\n", "<p><em>a</em>b<em>c</em></p>\n"];
        yield 'the line start is blank' => ["_a_ b\n", "<p><em>a</em> b</p>\n"];
        foreach (['«', '…', '।', '😀'] as $punctuation) {
            yield 'after '.$punctuation => ["_a_{$punctuation} b\n", "<p><em>a</em>{$punctuation} b</p>\n"];
        }
        yield 'no paren, no inline link' => ["[a]b)\n", "<p>[a]b)</p>\n"];
        yield 'a title after a word' => ["[a](b \"t\")\n", "<p><a href=\"b\" title=\"t\">a</a></p>\n"];
        yield 'a title needs a space' => ["[a](<b>\"t\")\n", "<p>[a](<b>&quot;t&quot;)</p>\n"];
        yield 'a last escaped paren' => ["[a](b\\)\n", "<p>[a](b)</p>\n"];
        yield 'a backslash escapes no space' => ["[a](b\\ c)\n", "<p>[a](b\\ c)</p>\n"];
        yield 'a padded label' => ["[ a ]\n\n[a]: /u\n", "<p><a href=\"/u\"> a </a></p>\n"];
        yield 'a backslash-newline label' => ["*a*\n\n[a][b\\\nc]\n\n[b\\\nc]: /u\n", "<p><em>a</em></p>\n<p><a href=\"/u\">a</a></p>\n"];
        yield 'a label past 999' => ['['.\str_repeat('a', times: 1000)."]: /u\n", '<p>['.\str_repeat('a', times: 1000)."]: /u</p>\n"];
        yield 'an unknown entity in a destination' => ["[a](/&nosuch;)\n", "<p><a href=\"/&amp;nosuch;\">a</a></p>\n"];
        yield 'code point edges' => ["&#x10FFFF; &#xD800; &#xDFFF; &#x1F600;\n", "<p>\u{10FFFF} \u{FFFD} \u{FFFD} \u{1F600}</p>\n"];
        yield 'www. domains' => ["www.a_b.cd www.localhost\n", "<p>www.a_b.cd <a href=\"http://www.localhost\">www.localhost</a></p>\n"];
        yield 'an entity-like tail' => ["www.x.com/&a;b&cd;\n", "<p><a href=\"http://www.x.com/&amp;a;b\">www.x.com/&amp;a;b</a>&amp;cd;</p>\n"];
        yield 'a link before emphasis' => ["www.x.com *a*\n", "<p><a href=\"http://www.x.com\">www.x.com</a> <em>a</em></p>\n"];
        yield 'a link inside emphasis' => ["*www.x.com*\n", "<p><em><a href=\"http://www.x.com\">www.x.com</a></em></p>\n"];
        yield 'html stays html' => ["<b> www.x.com\n", "<p><b> <a href=\"http://www.x.com\">www.x.com</a></p>\n"];
        yield 'a link stops at its run' => ["www.x.com`y`\n", "<p><a href=\"http://www.x.com\">www.x.com</a><code>y</code></p>\n"];
        yield 'an empty item interrupts nothing' => ["a\n* \n", "<p>a\n*</p>\n"];
        yield 'a number mid-line' => ["b. 1) c\n", "<p>b. 1) c</p>\n"];
        yield 'a closing fence starts its line' => ["```\n`` ```\n```\n", "<pre><code>`` ```\n</code></pre>\n"];
        yield 'a trailing line of spaces' => ["    a\n      \n", "<pre><code>a\n</code></pre>\n"];
        yield 'an item blank line' => ["- a\n\n      b\n        \n      c\n", "<ul>\n<li>\n<p>a</p>\n<pre><code>b\n\nc\n</code></pre>\n</li>\n</ul>\n"];
        yield 'a footnote blank line' => ["[^1]: a\n\n        b\n          \n        c\n", "<div class=\"footnote\" id=\"fn-1\">\n<p>a</p>\n<pre><code>b\n\nc\n</code></pre>\n</div>\n"];
        yield 'an indented footnote' => ["  [^1]: x\n\nr[^1]\n", "<div class=\"footnote\" id=\"fn-1\">\n<p>x</p>\n</div>\n<p>r<sup class=\"footnote-ref\"><a href=\"#fn-1\">1</a></sup></p>\n"];
        yield 'tab from column 5' => ["-    a\n\n     \tb\n", "<ul>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n</ul>\n"];
        yield 'tab from column 3' => [">  \tfoo\n", "<blockquote>\n<p>foo</p>\n</blockquote>\n"];
        yield 'tab after an indented quote' => ["  >\t  code\n", "<blockquote>\n<p>code</p>\n</blockquote>\n"];
        yield 'item eats a tab and a space' => ["1.   a\n\n\t     b\n", "<ol>\n<li>\n<p>a</p>\n<pre><code>b\n</code></pre>\n</li>\n</ol>\n"];
        yield 'item eats a tab at column 3' => ["1.   a\n\n   \t    b\n", "<ol>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n</ol>\n"];
        yield 'footnote tab, indent 4' => [
            "r[^1]\n\n[^1]:\t-\tb\n\n        c\n",
            "<p>r<sup class=\"footnote-ref\"><a href=\"#fn-1\">1</a></sup></p>\n<div class=\"footnote\" id=\"fn-1\">\n<ul>\n<li>\n<p>b</p>\n<p>c</p>\n</li>\n</ul>\n</div>\n",
        ];
        yield 'footnote tab, indent 3' => [
            "r[^1]\n\n[^1]:\t-\tb\n\n       c\n",
            "<p>r<sup class=\"footnote-ref\"><a href=\"#fn-1\">1</a></sup></p>\n<div class=\"footnote\" id=\"fn-1\">\n<ul>\n<li>b</li>\n</ul>\n<p>c</p>\n</div>\n",
        ];
        yield 'tight pipes' => ["|a|b|\n|-|-|\n", "<table>\n<thead>\n<tr>\n<th>a</th>\n<th>b</th>\n</tr>\n</thead>\n</table>\n"];
        yield 'spaces after the last pipe' => ["| a |  \n| - |\n", "<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n</table>\n"];
        yield 'left aligned' => ["| a |\n| :- |\n", "<table>\n<thead>\n<tr>\n<th align=\"left\">a</th>\n</tr>\n</thead>\n</table>\n"];
        yield 'a table under a line is tight' => ["- p\n  | a |\n  | - |\n- q\n", "<ul>\n<li>p\n<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n</table>\n</li>\n<li>q</li>\n</ul>\n"];
        yield 'a table past a blank is loose' => [
            "- p\n\n  | a |\n  | - |\n- q\n",
            "<ul>\n<li>\n<p>p</p>\n<table>\n<thead>\n<tr>\n<th>a</th>\n</tr>\n</thead>\n</table>\n</li>\n<li>\n<p>q</p>\n</li>\n</ul>\n",
        ];
    }

    /**
     * Corners each reaching a branch no spec example takes, pinned from this
     * parser's own output once read against the spec's rule for that branch.
     */
    #[DataProvider('corners')]
    public function testACorner(string $markdown, string $html): void
    {
        $this->assertSame($html, self::html($markdown), (string) \json_encode($markdown));
    }

    public function testTasksAlertsAndFlagsTheTreeCarries(): void
    {
        $this->assertSame([], self::item("- [x] \n")->blocks, 'a box alone leaves no paragraph');
        $open = self::item("- [ ] open  \n")->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $open);
        $this->assertInstanceOf(Text::class, $open->inlines[0]);
        $this->assertSame('open', $open->inlines[0]->literal);
        $this->assertNull(self::item("- # [x] a\n")->checked, 'a box must open the item\'s paragraph');
        $this->assertNull(self::item("- a [x] b\n")->checked);
        foreach (["> # [!NOTE]\n", "> x [!NOTE]\n"] as $markdown) {
            $quote = new MarkdownParser()->parse($markdown)->blocks[0];
            $this->assertInstanceOf(BlockQuote::class, $quote);
            $this->assertNull($quote->alert, $markdown);
        }
        $blocks = new MarkdownParser()->parse("a\n=\n\n# b\n\n| c |\n| - |\n")->blocks;
        $this->assertInstanceOf(Heading::class, $blocks[0]);
        $this->assertInstanceOf(Heading::class, $blocks[1]);
        $this->assertSame([true, false], [$blocks[0]->setext, $blocks[1]->setext]);
        $this->assertInstanceOf(Table::class, $blocks[2]);
        $this->assertSame($blocks[2]->header, $blocks[2]->children()[0]);
        $paragraph = new MarkdownParser()->parse("<http://a> [b](c) www.d.com\n")->blocks[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $links = \array_values(\array_filter($paragraph->inlines, static fn (Inline $inline): bool => $inline instanceof Link));
        $this->assertSame([true, false, true], \array_map(static fn (Link $link): bool => $link->autolink, $links));
    }

    private static function item(string $markdown): ListItem
    {
        $list = new MarkdownParser()->parse($markdown)->blocks[0];
        Assert::assertInstanceOf(ListBlock::class, $list, 'the input is a list');

        return $list->items[0];
    }
}
