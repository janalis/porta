<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Markdown\Inline\Entities;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\Block;
use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\Node\HtmlBlock;
use Porta\Markdown\Node\Node;
use Porta\Markdown\Node\TableCell;
use Porta\Markdown\Node\Text;

/**
 * The promise a later WYSIWYG mode builds on, checked over every spec example:
 * each node's source range lies inside its parent's, and siblings come in
 * source order without overlapping — so a position in the rendered page maps
 * to exactly one place in the text, and back. A block's range is exactly its
 * written lines, a cell its content, and text its own source.
 */
final class SourceRangeInvariantTest extends TestCase
{
    /** Inputs no spec example has, each reaching range arithmetic the spec does not. */
    private const array EXTRA = [
        'four quoted lines' => "> a\n> b\n> c\n> d *e*\n",
        'a paragraph above a table' => "p\n| a | b |\n| - | :- |\n| c |  \n",
        'an empty cell' => "| a | |\n| - | - |\n| | b |\n",
        'a list ending in a line of spaces' => "- a\n   \n",
        'code ending in spaces' => "```\nfoo  \n   \n```\n",
        'a paragraph ending in spaces' => "a  \n",
        'a bare link before more text' => "www.a.com x *b*\n",
        'CRLF' => "a\r\nb\r\n\r\n- c\r\n",
    ];

    /** @return iterable<string, array{string, string, string}> */
    public static function examples(): iterable
    {
        yield from SpecTest::commonMark();
        yield from SpecTest::gfm();
        foreach (self::EXTRA as $name => $markdown) {
            yield $name => [$markdown, '', 'extra'];
        }
    }

    #[DataProvider('examples')]
    public function testRangesNestAndStayInOrder(string $markdown, string $html, string $section): void
    {
        $document = new MarkdownParser()->parse($markdown);
        $this->assertSame(0, $document->range->start);
        $this->assertSame(\strlen($markdown), $document->range->end);
        $this->walk($document, $markdown);
    }

    private function walk(Node $parent, string $markdown): void
    {
        $previousEnd = $parent->range->start;
        foreach ($parent->children() as $child) {
            $where = \sprintf(
                '%s [%d,%d) in %s [%d,%d) of %s',
                $child::class,
                $child->range->start,
                $child->range->end,
                $parent::class,
                $parent->range->start,
                $parent->range->end,
                \json_encode($markdown, \JSON_THROW_ON_ERROR),
            );
            $this->assertLessThanOrEqual($child->range->end, $child->range->start, $where);
            $this->assertTrue($parent->range->contains($child->range), 'outside its parent: '.$where);
            $this->assertGreaterThanOrEqual($previousEnd, $child->range->start, 'overlaps the sibling before: '.$where);
            $previousEnd = $child->range->end;
            $this->assertText($child, $markdown, $where);
            $this->assertEdges($child, $markdown, $where);
            $this->assertBody($child, $markdown, $where);
            $this->walk($child, $markdown);
        }
    }

    /**
     * Text is its own source, or the one escape or entity that decodes to it
     * (in a table cell `\|` is `|`): an escape never merges into the text
     * beside it.
     */
    private function assertText(Node $node, string $markdown, string $where): void
    {
        if (!$node instanceof Text) {
            return;
        }
        $source = \substr($markdown, $node->range->start, $node->range->length());
        $m = [];
        $escape = 2 === \strlen($source) && '\\' === $source[0] && \str_contains(Entities::ESCAPABLE, $source[1]);
        $entity = 1 === \preg_match(Entities::ENTITY, $source, $m) && $m[0] === $source;
        $expected = $escape || $entity ? Entities::unescape($source) : \str_replace('\\|', replace: '|', subject: $source);
        $this->assertSame($expected, $node->literal, 'text that is not its source: '.$where);
    }

    /** A block runs from its first non-blank byte to the last of its last line; a cell is its trimmed content. */
    private function assertEdges(Node $node, string $markdown, string $where): void
    {
        $slice = \substr($markdown, $node->range->start, $node->range->length());
        if ($node instanceof TableCell) {
            $this->assertSame(\trim($slice, characters: " \t"), $slice, 'a cell wider than its content: '.$where);

            return;
        }
        if (!$node instanceof Block) {
            return;
        }
        $this->assertGreaterThan(0, $node->range->length(), 'an empty block: '.$where);
        $this->assertSame(\rtrim($slice, characters: " \t\r\n"), $slice, 'a block ends on a blank: '.$where);
        if (!$node instanceof CodeBlock && !$node instanceof HtmlBlock) {
            $this->assertSame(\ltrim($slice, characters: " \t\r\n"), $slice, 'a block starts on a blank: '.$where);
        }
        $rest = \substr($markdown, $node->range->end, \strcspn($markdown, characters: "\r\n", offset: $node->range->end));
        $this->assertSame('', \trim($rest, characters: " \t"), 'a block ends before its line does: '.$where);
    }

    /** Code and raw HTML end on their last non-blank line. */
    private function assertBody(Node $node, string $markdown, string $where): void
    {
        if (!$node instanceof CodeBlock && !$node instanceof HtmlBlock) {
            return;
        }
        $body = $node instanceof CodeBlock ? $node->body : $node->range;
        $this->assertTrue($node->range->contains($body), 'a body outside its block: '.$where);
        $slice = \substr($markdown, $body->start, $body->length());
        $this->assertSame(\rtrim($slice, characters: " \t\r\n"), $slice, 'a body ends on a blank: '.$where);
        $lines = \array_filter(\explode("\n", $node->literal), static fn (string $line): bool => '' !== \trim($line, characters: " \t"));
        if ([] !== $lines) {
            $this->assertStringEndsWith(\trim((string) \end($lines), characters: " \t"), $slice, 'a body short of its last line: '.$where);
        }
    }
}
