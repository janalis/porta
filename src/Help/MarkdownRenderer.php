<?php

declare(strict_types=1);

namespace Porta\Help;

use Porta\Help\Layout\AlertTitles;
use Porta\Help\Layout\BlockLayout;
use Porta\Help\Layout\CodeLayout;
use Porta\Help\Layout\HtmlBlockLayout;
use Porta\Help\Layout\ImageLayout;
use Porta\Help\Layout\LayoutState;
use Porta\Help\Layout\PageLine;
use Porta\Help\Layout\StylePen;
use Porta\Help\Layout\TableLayout;
use Porta\Highlight\CodeHighlighter;
use Porta\Markdown\Inline\LinkSyntax;
use Porta\Markdown\MarkdownParser;
use Porta\Markdown\Node\Document;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\Node\Node;
use Porta\Markdown\SourceText;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Theme\Styles;

/**
 * Markdown to terminal lines, as GitHub would show it, for the F1 browser and
 * the markdown preview: the text parsed once ({@see MarkdownParser}) and laid
 * out for each pane width ({@see BlockLayout}) into a {@see RenderedDoc} —
 * styled lines, link spans, and the source behind every line and cell.
 *
 * All colour flows through the injected {@see Styles}: with colours off, the
 * output is pure text. Every inline style is a self-contained SGR region, so
 * the page can splice a highlight over any span with plain column slicing.
 */
final class MarkdownRenderer
{
    private const int MIN_WIDTH = 20;

    private readonly MarkdownParser $parser;
    private readonly StylePen $pen;
    private readonly CodeLayout $code;
    private readonly AlertTitles $alertTitles;
    private readonly ImageLayout $images;
    private readonly HtmlBlockLayout $html;
    private ?Document $document = null;
    private string $parsed = '';

    public function __construct(
        Styles $styles,
        ?CodeHighlighter $highlighter = null,
        ?AlertTitles $alertTitles = null,
        ?TerminalGraphics $graphics = null,
    ) {
        $this->parser = new MarkdownParser();
        $this->pen = new StylePen($styles);
        $this->code = new CodeLayout($this->pen, $highlighter);
        $this->alertTitles = $alertTitles ?? AlertTitles::english();
        $this->images = new ImageLayout($graphics);
        $this->html = new HtmlBlockLayout($this->pen, $this->images);
    }

    /** The document tree for $markdown, parsed once however many widths it is laid out at. */
    public function document(string $markdown): Document
    {
        if (null === $this->document || $markdown !== $this->parsed) {
            $this->document = $this->parser->parse($markdown);
            $this->parsed = $markdown;
        }

        return $this->document;
    }

    /** Colours are baked into the lines and the code memo: drop them on a theme switch. */
    public function refreshTheme(): void
    {
        $this->code->refreshTheme();
    }

    /**
     * @param array<int, true> $openDetails the source offsets of the `<details>` the reader has opened
     * @param string|null      $baseDir     where the page's relative image paths start
     */
    public function render(string $markdown, int $width, array $openDetails = [], ?string $baseDir = null): RenderedDoc
    {
        $width = \max(self::MIN_WIDTH, $width);
        $document = $this->document($markdown);
        $footnotes = [];
        self::collectFootnotes($document, $footnotes);
        $state = new LayoutState(new SourceText($markdown), $footnotes, $openDetails, $baseDir);
        $layout = new BlockLayout($this->pen, $state, $this->code, new TableLayout($this->pen), $this->html, $this->images, $this->alertTitles);
        $lines = [...$layout->blocks($document->blocks, $width), ...$layout->footnotes($width)];
        $this->images->sweep();

        return self::assemble($lines, $state->source);
    }

    /** @param array<string, FootnoteDefinition> $footnotes */
    private static function collectFootnotes(Node $node, array &$footnotes): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof FootnoteDefinition) {
                $footnotes[LinkSyntax::normalize($child->label)] ??= $child;
            }
            self::collectFootnotes($child, $footnotes);
        }
    }

    /** @param list<PageLine> $lines */
    private static function assemble(array $lines, SourceText $source): RenderedDoc
    {
        $links = [];
        $anchors = [];
        foreach ($lines as $index => $line) {
            foreach ($line->links as [$col, $end, $target, $group]) {
                $links[] = new LinkSpan($index, $col, $end, $target, $group);
            }
            foreach ($line->anchors as $anchor) {
                $anchors[$anchor] ??= $index;
            }
        }
        \usort($links, static fn (LinkSpan $a, LinkSpan $b): int => [$a->group, $a->line, $a->col] <=> [$b->group, $b->line, $b->col]);

        return new RenderedDoc(
            \array_map(static fn (PageLine $line): string => $line->text, $lines),
            $links,
            \array_map(static fn (PageLine $line): int => $line->origin, $lines),
            \array_map(static fn (PageLine $line): array => $line->cells, $lines),
            $anchors,
            $source,
        );
    }
}
