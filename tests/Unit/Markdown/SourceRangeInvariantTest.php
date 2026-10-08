<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Markdown;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\Node;
use Porta\Markdown\Node\Text;

/**
 * The promise a later WYSIWYG mode builds on, checked over every spec example:
 * each node's source range lies inside its parent's, and siblings come in
 * source order without overlapping — so a position in the rendered page maps
 * to exactly one place in the text, and back.
 */
final class SourceRangeInvariantTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function examples(): iterable
    {
        yield from SpecTest::commonMark();
        yield from SpecTest::gfm();
    }

    #[DataProvider('examples')]
    public function testRangesNestAndStayInOrder(string $markdown, string $html, string $section): void
    {
        $document = new MarkdownParser()->parse($markdown);
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
                \json_encode($markdown),
            );
            $this->assertLessThanOrEqual($child->range->end, $child->range->start, $where);
            $this->assertTrue($parent->range->contains($child->range), 'outside its parent: '.$where);
            $this->assertGreaterThanOrEqual($previousEnd, $child->range->start, 'overlaps the sibling before: '.$where);
            $previousEnd = $child->range->end;
            $this->assertVerbatim($child, $markdown, $where);
            $this->walk($child, $markdown);
        }
    }

    /** Text written without an escape, an entity or a line ending is its own source. */
    private function assertVerbatim(Node $node, string $markdown, string $where): void
    {
        if (!$node instanceof Text) {
            return;
        }
        $source = \substr($markdown, $node->range->start, $node->range->end - $node->range->start);
        if (false === \strpbrk($source, characters: "\\&\n\t")) {
            $this->assertSame($source, $node->literal, 'text that is not its source: '.$where);
        }
    }
}
