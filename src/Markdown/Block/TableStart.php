<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\MappedText;

/**
 * GFM's table: a delimiter row (`| --- | :-: |`) under a paragraph whose last
 * line has as many cells turns that line into the table's header. The lines
 * before it stay a paragraph.
 */
final class TableStart
{
    private const string DELIMITER_ROW = '/^\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/';

    public function open(BlockParser $parser, OpenBlock $container): int
    {
        $content = $container->content;

        return BlockKind::Paragraph === $container->kind && null !== $content && 1 === \preg_match(self::DELIMITER_ROW, $parser->cursor->restFromNonspace())
            ? $this->openUnder($parser, $container, $content)
            : BlockStarts::NONE;
    }

    /** The delimiter row under a paragraph: a table when its last line has as many cells. */
    private function openUnder(BlockParser $parser, OpenBlock $container, MappedText $content): int
    {
        $cursor = $parser->cursor;
        $alignments = \array_map(static fn (MappedText $cell): string => $cell->text, TableRows::cells(MappedText::verbatim($cursor->restFromNonspace(), 0)));
        $split = \strrpos($content->text, needle: "\n");
        $header = false === $split ? $content : $content->from($split + 1);
        if (\count(TableRows::cells($header)) !== \count($alignments)) {
            return BlockStarts::NONE;
        }
        $parser->closeUnmatched();
        $table = new OpenBlock(BlockKind::Table, $header->source(0), $parser->lineNumber - 1);
        $table->alignments = $alignments;
        $table->rows[] = [$header, $header->source(0), $header->source(\strlen($header->text) - 1) + 1];
        $cursor->advance(\strlen($cursor->line) - $cursor->offset);
        $this->place($parser, $container, $content, $split, $table);

        return BlockStarts::LEAF;
    }

    /**
     * A paragraph that was only the header becomes the table. Otherwise the
     * lines above the header stay a paragraph, which ends on the line before it.
     */
    private function place(BlockParser $parser, OpenBlock $paragraph, MappedText $content, int|false $split, OpenBlock $table): void
    {
        if (false === $split) {
            $parser->replace($paragraph, $table);

            return;
        }
        $kept = $content->head($split);
        $paragraph->content = $kept;
        $paragraph->end = $kept->source(\strlen($kept->text) - 1) + 1;
        $paragraph->endLine = $parser->lineNumber - 2;
        $parser->finalize($paragraph);
        $parser->tip->append($table);
        $parser->tip = $table;
    }
}
