<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Help\MarkdownRenderer;
use Porta\Help\RenderedDoc;
use Porta\Support\AnsiText;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Support\ScrollsRows;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * A markdown file as it reads, beside (or instead of) its text: the live
 * preview core ships, rendered by the same {@see MarkdownRenderer} as the help
 * pages and re-rendered per pane width.
 *
 * It follows the TEXT, not the file: {@see \Porta\Editor\PreviewFeeder} hands
 * it the document on every pause in typing, so what it shows is what the
 * editor holds, saved or not. An edit keeps the reader where they were — the
 * scroll survives a new text for the same file, and only a different file
 * starts from the top.
 *
 * Read-only by construction, the help pane's cousin without its navigation:
 * links are drawn as links, and following one is the editor's job, not a
 * preview's.
 */
#[AsIdeService]
#[AsWidget('markdown-preview')]
final class MarkdownPreviewWidget extends EditorPaneWidget
{
    use ScrollsRows;

    /** The routing id, matching the claim registered in {@see \Porta\Bootstrap\CorePanes}. */
    public const string PANE_ID = 'markdown';

    private ?string $path = null;

    private string $markdown = '';

    /** The text rendered for {@see $renderedWidth}, dropped on a new text, a resize or a theme switch. */
    private ?RenderedDoc $doc = null;

    private int $renderedWidth = 0;

    private readonly MarkdownRenderer $renderer;

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
        $this->renderer = new MarkdownRenderer($this->styles);
    }

    #[\Override]
    public function paneId(): string
    {
        return self::PANE_ID;
    }

    /** @param mixed $payload the markdown text the editor holds */
    #[\Override]
    public function show(string $path, mixed $payload): void
    {
        $markdown = (string) $payload;
        if ($path === $this->path && $markdown === $this->markdown) {
            return; // the same text for the same file: nothing to draw again
        }
        if ($path !== $this->path) {
            $this->scrollTop = 0;
        }
        $this->path = $path;
        $this->markdown = $markdown;
        $this->doc = null;
        $this->invalidate();
    }

    #[\Override]
    public function clear(): void
    {
        $this->path = null;
        $this->markdown = '';
        $this->doc = null;
        $this->scrollTop = 0;
        $this->invalidate();
    }

    /**
     * Nothing of its own to say: beside the editor the caret's legend stays,
     * and alone the file's path on the left already names what this is.
     */
    #[\Override]
    public function statusRight(): string
    {
        return '';
    }

    /** Rendered lines bake palette codes: drop them on a theme switch. */
    #[\Override]
    public function refreshTheme(): void
    {
        $this->doc = null;
        $this->invalidate();
    }

    /** @return list<string> */
    #[\Override]
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        if (null === $this->doc || $cols !== $this->renderedWidth) {
            $this->doc = $this->renderer->render($this->markdown, $cols);
            $this->renderedWidth = $cols;
        }
        $this->bodyRows = $rows;
        // A shorter text, or a taller pane, can leave the view past the end.
        $this->scrollTop = $this->clampScroll($this->scrollTop);

        $lines = [];
        for ($row = 0; $row < $rows; ++$row) {
            // Truncate as well as pad: the renderer floors its own wrap width,
            // so a pane narrower than that gets lines wider than itself, and a
            // row wider than its pane throws out of the render loop.
            $lines[] = AnsiText::truncate($this->doc->lines[$this->scrollTop + $row] ?? '', $cols, pad: true);
        }

        return $lines;
    }

    private function rowCount(): int
    {
        return \count($this->doc->lines ?? []);
    }
}
