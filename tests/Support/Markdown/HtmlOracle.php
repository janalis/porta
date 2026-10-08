<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Markdown;

use Porta\Markdown\Node\Alignment;
use Porta\Markdown\Node\BlockQuote;
use Porta\Markdown\Node\CodeBlock;
use Porta\Markdown\Node\CodeSpan;
use Porta\Markdown\Node\Document;
use Porta\Markdown\Node\Emoji;
use Porta\Markdown\Node\Emphasis;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\FootnoteReference;
use Porta\Markdown\Node\HardBreak;
use Porta\Markdown\Node\Heading;
use Porta\Markdown\Node\HtmlBlock;
use Porta\Markdown\Node\HtmlInline;
use Porta\Markdown\Node\Image;
use Porta\Markdown\Node\Link;
use Porta\Markdown\Node\ListBlock;
use Porta\Markdown\Node\ListItem;
use Porta\Markdown\Node\Node;
use Porta\Markdown\Node\Paragraph;
use Porta\Markdown\Node\SoftBreak;
use Porta\Markdown\Node\Strikethrough;
use Porta\Markdown\Node\Strong;
use Porta\Markdown\Node\Table;
use Porta\Markdown\Node\TableRow;
use Porta\Markdown\Node\Text;
use Porta\Markdown\Node\ThematicBreak;

/**
 * The parsed tree as the HTML the CommonMark spec's examples expect — the
 * reference renderer's output, line breaks included — so the parser can be
 * held to every example. Test-only: Porta never renders markdown to HTML.
 */
final class HtmlOracle
{
    private const string TAG_FILTER = '/<(?=\/?(?:title|textarea|style|xmp|iframe|noembed|noframes|script|plaintext)\b)/i';

    private string $out = '';

    public function __construct(
        private readonly bool $tagFilter = false,
    ) {}

    private bool $tight = false;

    public function render(Document $document): string
    {
        $this->out = '';
        foreach ($document->blocks as $block) {
            $this->block($block);
        }

        return $this->out;
    }

    private function cr(): void
    {
        if ('' !== $this->out && !\str_ends_with($this->out, "\n")) {
            $this->out .= "\n";
        }
    }

    private function block(Node $block): void
    {
        match (true) {
            $block instanceof Paragraph => $this->paragraph($block),
            $block instanceof Heading => $this->wrapBlock('h'.$block->level, $block->inlines),
            $block instanceof ThematicBreak => $this->line('<hr />'),
            $block instanceof CodeBlock => $this->code($block),
            $block instanceof HtmlBlock => $this->line($this->filter($block->literal)),
            $block instanceof BlockQuote => $this->container('<blockquote>', $block->blocks, '</blockquote>'),
            $block instanceof ListBlock => $this->list($block),
            $block instanceof Table => $this->table($block),
            $block instanceof FootnoteDefinition => $this->container('<div class="footnote" id="fn-'.self::esc($block->label).'">', $block->blocks, '</div>'),
            default => null,
        };
    }

    private function line(string $html): void
    {
        $this->cr();
        $this->out .= $html;
        $this->cr();
    }

    private function paragraph(Paragraph $paragraph): void
    {
        if ($this->tight) {
            $this->inlines($paragraph->inlines);

            return;
        }
        $this->wrapBlock('p', $paragraph->inlines);
    }

    /** @param list<Node> $inlines */
    private function wrapBlock(string $tag, array $inlines): void
    {
        $this->cr();
        $this->out .= '<'.$tag.'>';
        $this->inlines($inlines);
        $this->out .= '</'.$tag.'>';
        $this->cr();
    }

    /** @param list<Node> $blocks */
    private function container(string $open, array $blocks, string $close): void
    {
        $tight = $this->tight;
        $this->tight = false;
        $this->line($open);
        foreach ($blocks as $block) {
            $this->block($block);
        }
        $this->line($close);
        $this->tight = $tight;
    }

    private function code(CodeBlock $code): void
    {
        $language = \preg_split('/\s+/', $code->info)[0] ?? '';
        $class = '' === $language ? '' : ' class="language-'.self::esc($language).'"';
        $this->line('<pre><code'.$class.'>'.self::esc($code->literal).'</code></pre>');
    }

    private function list(ListBlock $list): void
    {
        $tag = $list->ordered ? 'ol' : 'ul';
        $start = $list->ordered && 1 !== $list->start ? ' start="'.$list->start.'"' : '';
        $this->line('<'.$tag.$start.'>');
        foreach ($list->items as $item) {
            $this->item($item, $list->tight);
        }
        $this->line('</'.$tag.'>');
    }

    private function item(ListItem $item, bool $tight): void
    {
        $outer = $this->tight;
        $this->tight = $tight;
        $this->out .= '<li>';
        if (null !== $item->checked) {
            $this->out .= '<input '.($item->checked ? 'checked="" ' : '').'disabled="" type="checkbox"> ';
        }
        foreach ($item->blocks as $block) {
            $this->block($block);
        }
        $this->out .= '</li>';
        $this->cr();
        $this->tight = $outer;
    }

    private function table(Table $table): void
    {
        $this->line('<table>');
        $this->line('<thead>');
        $this->row($table->header, 'th', $table->alignments);
        $this->line('</thead>');
        if ([] !== $table->rows) {
            $this->line('<tbody>');
            foreach ($table->rows as $row) {
                $this->row($row, 'td', $table->alignments);
            }
            $this->line('</tbody>');
        }
        $this->line('</table>');
    }

    /** @param list<Alignment> $alignments */
    private function row(TableRow $row, string $tag, array $alignments): void
    {
        $this->line('<tr>');
        foreach ($row->cells as $index => $cell) {
            $align = match ($alignments[$index] ?? Alignment::None) {
                Alignment::Left => ' align="left"',
                Alignment::Center => ' align="center"',
                Alignment::Right => ' align="right"',
                Alignment::None => '',
            };
            $this->out .= '<'.$tag.$align.'>';
            $this->inlines($cell->inlines);
            $this->out .= '</'.$tag.'>';
            $this->cr();
        }
        $this->line('</tr>');
    }

    /** @param list<Node> $inlines */
    private function inlines(array $inlines): void
    {
        $this->out .= $this->nested($inlines);
    }

    private function inline(Node $inline): string
    {
        return match (true) {
            $inline instanceof Text => self::esc($inline->literal),
            $inline instanceof SoftBreak => "\n",
            $inline instanceof HardBreak => "<br />\n",
            $inline instanceof CodeSpan => '<code>'.self::esc($inline->literal).'</code>',
            $inline instanceof HtmlInline => $this->filter($inline->literal),
            $inline instanceof Emphasis => '<em>'.$this->nested($inline->inlines).'</em>',
            $inline instanceof Strong => '<strong>'.$this->nested($inline->inlines).'</strong>',
            $inline instanceof Strikethrough => '<del>'.$this->nested($inline->inlines).'</del>',
            $inline instanceof Link => '<a href="'
                .self::esc(self::uri($inline->destination))
                .'"'
                .('' === $inline->title ? '' : ' title="'.self::esc($inline->title).'"')
                .'>'
                .$this->nested($inline->inlines)
                .'</a>',
            $inline instanceof Image => '<img src="'
                .self::esc(self::uri($inline->destination))
                .'" alt="'
                .$this->alt($inline->inlines)
                .'"'
                .('' === $inline->title ? '' : ' title="'.self::esc($inline->title).'"')
                .' />',
            $inline instanceof FootnoteReference => '<sup class="footnote-ref"><a href="#fn-'.self::esc($inline->label).'">'.self::esc($inline->label).'</a></sup>',
            $inline instanceof Emoji => $inline->glyph,
            default => '',
        };
    }

    /** An image's alt text: the text of its children, every tag dropped. */
    private function plain(Node $inline): string
    {
        return match (true) {
            $inline instanceof Text, $inline instanceof CodeSpan => self::esc($inline->literal),
            $inline instanceof SoftBreak, $inline instanceof HardBreak => "\n",
            $inline instanceof Emoji => $inline->glyph,
            default => $this->alt($inline->children()),
        };
    }

    /** @param list<Node> $inlines */
    private function alt(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            $text .= $this->plain($inline);
        }

        return $text;
    }

    /** @param list<Node> $inlines */
    private function nested(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            $text .= $this->inline($inline);
        }

        return $text;
    }

    private static function esc(string $text): string
    {
        return \str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $text);
    }

    /** GFM's tagfilter: a few raw tags are written escaped. */
    private function filter(string $html): string
    {
        return !$this->tagFilter ? $html : \preg_replace(self::TAG_FILTER, replacement: '&lt;', subject: $html) ?? $html;
    }

    /** The reference renderer's URL normalisation: percent-encode what is not already safe or encoded. */
    private static function uri(string $uri): string
    {
        return \preg_replace_callback(
            '/%[0-9a-fA-F]{2}|[^A-Za-z0-9;\/?:@&=+$,\-_.!~*\'()#]/',
            static function (array $m): string {
                if ('%' === $m[0][0] && 3 === \strlen($m[0])) {
                    return $m[0];
                }

                return \rawurlencode($m[0]);
            },
            $uri,
        ) ?? $uri;
    }
}
