<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

use Porta\Markdown\Inline\HtmlSyntax;
use Porta\Markdown\MappedText;

/**
 * What a line may open, tried in the spec's order: a quote, an ATX heading, a
 * code fence, an HTML block, a setext underline, a thematic break, a footnote,
 * a list item, indented code — and, GFM's, a table under its header line.
 *
 * Each answers {@see NONE}, {@see CONTAINER} (opened one: keep looking for
 * more on the same line) or {@see LEAF} (opened one that takes the rest).
 */
final readonly class BlockStarts
{
    public const int NONE = 0;
    public const int CONTAINER = 1;
    public const int LEAF = 2;

    private const string THEMATIC_BREAK = '/^(?:(?:\*[ \t]*){3,}|(?:_[ \t]*){3,}|(?:-[ \t]*){3,})$/';

    private TableStart $table;

    public function __construct()
    {
        $this->table = new TableStart();
    }

    public function open(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        if ($cursor->indented()) {
            return $this->indentedCode($parser); // nothing else opens past three columns of indentation
        }
        foreach ([
            $this->quote(...),
            $this->atxHeading(...),
            $this->fence(...),
            $this->html(...),
            $this->setext(...),
            $this->thematicBreak(...),
            $this->footnote(...),
            $this->listItem(...),
        ] as $start) {
            $result = $start($parser, $container);
            if (self::NONE !== $result) {
                break;
            }
        }

        return self::NONE === $result ? $this->table->open($parser, $container) : $result;
    }

    private function quote(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        if ('>' !== $cursor->peekNonspace()) {
            return self::NONE;
        }
        $at = $cursor->nextNonspace;
        $cursor->advanceNextNonspace();
        $cursor->advance(1);
        if (Continuation::spaceOrTab($cursor->line[$cursor->offset] ?? '')) {
            $cursor->advanceColumns(1);
        }
        $parser->closeUnmatched();
        $parser->addChild(BlockKind::BlockQuote, $at);

        return self::CONTAINER;
    }

    private function atxHeading(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        $m = [];
        if (1 !== \preg_match('/^(#{1,6})(?:[ \t]+|$)/', $cursor->restFromNonspace(), $m)) {
            return self::NONE;
        }
        $at = $cursor->nextNonspace;
        $cursor->advanceNextNonspace();
        $cursor->advance(\strlen($m[0]));
        $parser->closeUnmatched();
        $heading = $parser->addChild(BlockKind::Heading, $at);
        $heading->level = \strlen($m[1]);
        $content = \substr($cursor->line, $cursor->offset);
        $stripped = \preg_replace(['/^[ \t]*#+[ \t]*$/', '/[ \t]+#+[ \t]*$/'], replacement: '', subject: $content) ?? $content;
        $heading->content = MappedText::verbatim($stripped, $cursor->source($cursor->offset));
        $cursor->advance(\strlen($cursor->line) - $cursor->offset);

        return self::LEAF;
    }

    private function fence(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        $m = [];
        if (1 !== \preg_match('/^(?:`{3,}(?!.*`)|~{3,})/', $cursor->restFromNonspace(), $m)) {
            return self::NONE;
        }
        $parser->closeUnmatched();
        $code = $parser->addChild(BlockKind::CodeBlock, $cursor->nextNonspace);
        $code->fenced = true;
        $code->fenceLength = \strlen($m[0]);
        $code->fenceChar = $m[0][0];
        $code->fenceOffset = $cursor->indent;
        $cursor->advanceNextNonspace();
        $cursor->advance(\strlen($m[0]));

        return self::LEAF;
    }

    private function html(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        $lazyParagraph = !$parser->allClosed && !$cursor->blank && BlockKind::Paragraph === $parser->tip->kind;
        $type = '<' === $cursor->peekNonspace() ? HtmlSyntax::blockStart($cursor->restFromNonspace()) : 0;
        if (0 === $type || (7 === $type && (BlockKind::Paragraph === $container->kind || $lazyParagraph))) {
            return self::NONE;
        }
        $parser->closeUnmatched();
        $parser->addChild(BlockKind::HtmlBlock, $cursor->offset)->htmlType = $type;

        return self::LEAF;
    }

    private function setext(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        $m = [];
        if (BlockKind::Paragraph !== $container->kind || 1 !== \preg_match('/^(?:=+|-+)[ \t]*$/', $cursor->restFromNonspace(), $m)) {
            return self::NONE;
        }
        $parser->closeUnmatched();
        $parser->takeReferences($container);
        $result = self::NONE;
        if (null !== $container->content && '' !== $container->content->text) {
            $heading = new OpenBlock(BlockKind::Heading, $container->start, $container->startLine);
            $heading->level = '=' === $m[0][0] ? 1 : 2;
            $heading->setext = true;
            $heading->content = $container->content;
            $parser->replace($container, $heading);
            $cursor->advance(\strlen($cursor->line) - $cursor->offset);
            $result = self::LEAF;
        }

        return $result;
    }

    private function thematicBreak(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        if (1 !== \preg_match(self::THEMATIC_BREAK, $cursor->restFromNonspace())) {
            return self::NONE;
        }
        $parser->closeUnmatched();
        $parser->addChild(BlockKind::ThematicBreak, $cursor->nextNonspace);
        $cursor->advance(\strlen($cursor->line) - $cursor->offset);

        return self::LEAF;
    }

    private function footnote(BlockParser $parser, OpenBlock $container): int
    {
        $cursor = $parser->cursor;
        $m = [];
        if (1 !== \preg_match('/^\[\^([^\]\x00\r\n \t]+)\]:[ \t]*/', $cursor->restFromNonspace(), $m)) {
            return self::NONE;
        }
        $at = $cursor->nextNonspace;
        $cursor->advanceNextNonspace();
        $cursor->advance(\strlen($m[0]));
        $parser->closeUnmatched();
        $parser->addChild(BlockKind::FootnoteDefinition, $at)->label = $m[1];

        return self::CONTAINER;
    }

    private function listItem(BlockParser $parser, OpenBlock $container): int
    {
        $marker = new ListMarkerReader()->read($parser->cursor, $container);
        if (null === $marker) {
            return self::NONE;
        }
        $parser->closeUnmatched();
        $tip = $parser->tip;
        if (BlockKind::List !== $tip->kind || null === $tip->marker || !$tip->marker->sameListAs($marker)) {
            $list = $parser->addChild(BlockKind::List, $marker->sourceStart - $parser->cursor->lineStart);
            $list->marker = $marker;
        }
        $parser->addChild(BlockKind::Item, $marker->sourceStart - $parser->cursor->lineStart)->marker = $marker;

        return self::CONTAINER;
    }

    private function indentedCode(BlockParser $parser): int
    {
        $cursor = $parser->cursor;
        if (BlockKind::Paragraph === $parser->tip->kind || $cursor->blank) {
            return self::NONE;
        }
        $cursor->advanceColumns(4);
        $parser->closeUnmatched();
        $parser->addChild(BlockKind::CodeBlock, $cursor->offset);

        return self::LEAF;
    }
}
