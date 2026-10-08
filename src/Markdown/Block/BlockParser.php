<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\HtmlSyntax;
use Porta\Markdown\MappedText;

/**
 * The first phase of CommonMark parsing: lines into a tree of blocks.
 *
 * Each line first walks down the open containers, each consuming its prefix
 * ({@see Continuation}); whatever is left may open new blocks
 * ({@see BlockStarts}); the rest is added to the deepest open leaf, or lazily
 * continues a paragraph. Blocks are closed — finalised — as soon as a line
 * fails to continue them ({@see Finalizer}). The algorithm and its quirks are
 * the reference implementation's (commonmark.js), so the spec's examples are
 * what it answers to.
 *
 * Every block records where it starts and the end of its last non-blank line,
 * in source bytes, and paragraphs keep their text mapped to the source.
 */
final class BlockParser
{
    public LineCursor $cursor;
    public OpenBlock $tip;
    public int $lineNumber = -1;
    public bool $allClosed = true;
    private OpenBlock $document;
    private OpenBlock $oldTip;
    private OpenBlock $lastMatched;
    private readonly Continuation $continuation;
    private readonly BlockStarts $starts;
    private readonly Finalizer $finalizer;

    public function __construct()
    {
        $this->cursor = new LineCursor();
        $this->continuation = new Continuation();
        $this->starts = new BlockStarts();
        $this->finalizer = new Finalizer();
        $this->document = new OpenBlock(BlockKind::Document, 0, 0);
        $this->tip = $this->document;
        $this->oldTip = $this->document;
        $this->lastMatched = $this->document;
    }

    /** The document's block tree and its link reference definitions. */
    public function parse(string $text): ParsedBlocks
    {
        $document = new OpenBlock(BlockKind::Document, 0, 0);
        $this->document = $document;
        $this->tip = $document;
        $this->oldTip = $document;
        $this->lastMatched = $document;
        $this->lineNumber = -1;
        $this->finalizer->references = [];
        // Lines end at LF, CR or CRLF; a final line ending ends the last line, it does not start one.
        $offset = 0;
        $length = \strlen($text);
        while ($offset < $length) {
            $lineLength = \strcspn($text, characters: "\r\n", offset: $offset);
            $this->incorporate(\substr($text, $offset, $lineLength), $offset);
            $offset += $lineLength;
            $offset += "\r\n" === \substr($text, $offset, length: 2) ? 2 : 1;
        }
        while (BlockKind::Document !== $this->tip->kind) {
            $this->finalize($this->tip);
        }
        $this->finalize($document);
        $document->end = \strlen($text);

        return new ParsedBlocks($document, $this->finalizer->references);
    }

    private function incorporate(string $line, int $start): void
    {
        ++$this->lineNumber;
        $this->cursor->reset($line, $start);
        $this->oldTip = $this->tip;
        $container = $this->matchContainers();
        if (null === $container) {
            return; // a closing fence consumed the line
        }
        $this->allClosed = $container === $this->oldTip;
        $this->lastMatched = $container;
        $container = $this->openNewBlocks($container);
        $this->addLine($container);
        $this->extendOpenBlocks();
    }

    /** Walk down the open blocks while each continues on this line; null when one consumed it whole. */
    private function matchContainers(): ?OpenBlock
    {
        $container = $this->document;
        while (null !== ($child = $container->lastChild()) && $child->open) {
            $this->cursor->findNextNonspace();
            $result = $this->continuation->continues($this->cursor, $child);
            if (Continuation::CONSUMED === $result) {
                $this->extendOpenBlocks(); // the closing fence is the code block's last line
                $this->finalize($child);

                return null;
            }
            if (Continuation::FAILED === $result) {
                break;
            }
            $container = $child;
        }

        return $container;
    }

    private function openNewBlocks(OpenBlock $container): OpenBlock
    {
        $matchedLeaf = BlockKind::Paragraph !== $container->kind && BlockKind::Table !== $container->kind && $container->kind->acceptsLines();
        while (!$matchedLeaf) {
            $this->cursor->findNextNonspace();
            $result = $this->starts->open($this, $container);
            if (BlockStarts::NONE === $result) {
                $this->cursor->advanceNextNonspace();
                break;
            }
            $container = $this->tip;
            $matchedLeaf = BlockStarts::LEAF === $result;
        }

        return $container;
    }

    private function addLine(OpenBlock $container): void
    {
        $cursor = $this->cursor;
        if (!$this->allClosed && !$cursor->blank && BlockKind::Paragraph === $this->tip->kind) {
            $this->appendText($this->tip); // a lazy continuation line

            return;
        }
        $this->closeUnmatched();
        if ($container->kind->acceptsLines()) {
            $this->appendText($container);
            if (BlockKind::HtmlBlock === $container->kind && HtmlSyntax::blockEnds($container->htmlType, \substr($cursor->line, $cursor->offset))) {
                $this->extendOpenBlocks();
                $this->finalize($container);
            }

            return;
        }
        if (!$cursor->blank && $cursor->offset < \strlen($cursor->line)) {
            $paragraph = $this->addChild(BlockKind::Paragraph, $cursor->nextNonspace);
            $cursor->advanceNextNonspace();
            $this->appendText($paragraph);
        }
    }

    /** Add the rest of the line to a leaf, a partly consumed tab first as the spaces it still stands for. */
    private function appendText(OpenBlock $block): void
    {
        $cursor = $this->cursor;
        $spaces = '';
        $tab = $cursor->offset;
        if ($cursor->partialTab) {
            ++$cursor->offset;
            $spaces = \str_repeat(' ', 4 - ($cursor->column % 4));
        }
        $rest = \substr($cursor->line, $cursor->offset);
        if (BlockKind::Paragraph === $block->kind || BlockKind::Table === $block->kind) {
            $pieces = '' === $spaces ? [[0, $cursor->source($cursor->offset)]] : [[0, $cursor->source($tab)], [\strlen($spaces), $cursor->source($cursor->offset)]];
            $line = new MappedText($spaces.$rest, $pieces);
            BlockKind::Table === $block->kind ? $this->finalizer->addTableRow($block, $line, $cursor) : $block->addContent($line);

            return;
        }
        if ($block->bodyStart < 0 && (!$block->fenced || [] !== $block->lines)) {
            $block->bodyStart = $cursor->source($tab);
        }
        if ((!$block->fenced || [] !== $block->lines) && '' !== \trim($rest, characters: " \t")) {
            $block->bodyEnd = $cursor->lineStart + \strlen(\rtrim($cursor->line, characters: " \t"));
        }
        $block->lines[] = $spaces.$rest;
    }

    /** A non-blank line belongs to every block still open: each now ends with it. */
    private function extendOpenBlocks(): void
    {
        if ('' === \trim($this->cursor->line, characters: " \t")) {
            return; // a container prefix alone (`>`) is not blank: the quote goes on
        }
        $end = $this->cursor->lineStart + \strlen(\rtrim($this->cursor->line, characters: " \t"));
        for ($block = $this->tip; null !== $block; $block = $block->parent) {
            $block->end = \max($block->end, $end);
            $block->endLine = $this->lineNumber;
        }
    }

    public function closeUnmatched(): void
    {
        if ($this->allClosed) {
            return;
        }
        while ($this->oldTip !== $this->lastMatched) {
            $parent = $this->oldTip->parent;
            $this->finalize($this->oldTip);
            $this->oldTip = $parent ?? $this->lastMatched;
        }
        $this->allClosed = true;
    }

    /** Open a block at byte $offset of the line, closing whatever cannot hold it. */
    public function addChild(BlockKind $kind, int $offset): OpenBlock
    {
        while (!$this->tip->kind->canContain($kind)) {
            $this->finalize($this->tip);
        }
        $child = new OpenBlock($kind, $this->cursor->source($offset), $this->lineNumber);
        $this->tip->append($child);

        return $this->tip = $child;
    }

    /** Swap $old for $new in its parent, $new becoming the tip. */
    public function replace(OpenBlock $old, OpenBlock $new): void
    {
        $parent = $old->parent;
        if (null !== $parent) {
            $parent->children[(int) \array_search($old, $parent->children, strict: true)] = $new;
            $new->parent = $parent;
        }
        $this->tip = $new;
    }

    public function takeReferences(OpenBlock $paragraph): void
    {
        $this->finalizer->takeReferences($paragraph);
    }

    public function finalize(OpenBlock $block): void
    {
        $block->open = false;
        $this->finalizer->finalize($block);
        $this->tip = $block->parent ?? $block;
    }
}
