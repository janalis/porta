<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\MappedText;

/**
 * A block while the block parser still has it open: mutable, linked to its
 * parent, gathering lines. {@see TreeBuilder} turns the finished tree of
 * these into the immutable {@see \Porta\Markdown\Node\Node}s.
 */
final class OpenBlock
{
    /** @var list<OpenBlock> */
    public array $children = [];
    public bool $open = true;
    /** Where the block ends in the source: the end of its last non-blank line. */
    public int $end;
    public int $endLine;
    /** The text lines a leaf gathers, each still mapped to the source. */
    public ?MappedText $content = null;
    /** @var list<string> a code or HTML block's lines, verbatim */
    public array $lines = [];
    public ?ListMarker $marker = null;
    public int $level = 0;
    public bool $setext = false;
    public bool $fenced = false;
    public string $fenceChar = '';
    public int $fenceLength = 0;
    public int $fenceOffset = 0;
    public string $info = '';
    public int $htmlType = 0;
    public string $label = '';
    /** Where a code block's code starts in the source. */
    public int $bodyStart = -1;
    /** Where a code block's last non-blank code line ends. */
    public int $bodyEnd = 0;
    public bool $tight = true;
    /** @var list<string> a table's delimiter row, one entry per column */
    public array $alignments = [];
    /** @var list<array{MappedText, int, int}> a table's rows: the row text, its source start and end */
    public array $rows = [];

    public function __construct(
        public readonly BlockKind $kind,
        public readonly int $start,
        public readonly int $startLine,
        public ?OpenBlock $parent = null,
    ) {
        $this->end = $start;
        $this->endLine = $startLine;
    }

    public function lastChild(): ?self
    {
        return [] === $this->children ? null : $this->children[\count($this->children) - 1];
    }

    public function append(self $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function remove(self $child): void
    {
        $this->children = \array_values(\array_filter($this->children, static fn (self $c): bool => $c !== $child));
    }

    public function addContent(MappedText $line): void
    {
        $this->content = null === $this->content ? $line : $this->content->append(MappedText::verbatim("\n", $this->content->source(\strlen($this->content->text) - 1) + 1))->append($line);
    }

    /** Whether a blank line separates this block from the sibling after it. */
    public function blankBefore(self $next): bool
    {
        return $next->startLine > ($this->endLine + 1);
    }
}
