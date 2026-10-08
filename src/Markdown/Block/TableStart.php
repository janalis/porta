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
        $cursor = $parser->cursor;
        $content = $container->content;
        if (BlockKind::Paragraph !== $container->kind || null === $content || 1 !== \preg_match(self::DELIMITER_ROW, $cursor->restFromNonspace())) {
            return BlockStarts::NONE;
        }
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
        if (false === $split) {
            $parser->replace($container, $table);

            return BlockStarts::LEAF;
        }
        $this->keepParagraph($parser, $container, $content->head($split));
        $parser->tip->append($table);
        $parser->tip = $table;

        return BlockStarts::LEAF;
    }

    /** The lines above the header stay a paragraph, which ends on the line before it. */
    private function keepParagraph(BlockParser $parser, OpenBlock $paragraph, MappedText $kept): void
    {
        $paragraph->content = $kept;
        $paragraph->end = $kept->source(\strlen($kept->text) - 1) + 1;
        $paragraph->endLine = $parser->lineNumber - 2;
        $parser->finalize($paragraph);
    }
}
