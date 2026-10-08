<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Event\SourceEditRequested;
use Porta\Highlight\CodeHighlighterFactory;
use Porta\I18n\Translator;
use Porta\Ui\Graphics\TerminalGraphics;
use Porta\Ui\Theme\Styles;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A markdown file as it reads, beside (or instead of) its text: the live
 * preview core ships, drawn exactly as the documentation is
 * ({@see MarkdownPageWidget}) — the navigation bar over the page, links to
 * cycle with Tab and follow with Enter or a click, Backspace back through the
 * pages followed, Ctrl+F to find in the page.
 *
 * Its tree is the PROJECT: the breadcrumb walks up to the project root, and a
 * link to another markdown file opens that file's own tab, which shows its
 * preview in turn — so following links reads a repository's docs the way the
 * help browser reads Porta's.
 *
 * And the page points back at its text: a click on it, away from a link,
 * puts the editor's caret on the character clicked and scrolls it into view,
 * and a task's box ticks — or unticks — the task in the text.
 *
 * It follows the TEXT, not the file: {@see \Porta\Editor\PreviewFeeder} hands
 * it the document on every pause in typing, so what it shows is what the
 * editor holds, saved or not. An edit keeps the reader where they were; only
 * another file starts from the top.
 */
#[AsIdeService]
#[AsWidget('markdown-preview')]
final class MarkdownPreviewWidget extends MarkdownPageWidget
{
    /** The routing id, matching the claim registered in {@see \Porta\Bootstrap\CorePanes}. */
    public const string PANE_ID = 'markdown';

    /** The path as the buffer knows it — the page keeps it canonical, an edit must name the buffer. */
    private ?string $bufferPath = null;

    public function __construct(
        ?Styles $styles = null,
        ?CodeHighlighterFactory $highlighters = null,
        ?Translator $translator = null,
        ?TerminalGraphics $graphics = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        parent::__construct($styles, $highlighters, $translator, $graphics);
    }

    /** A click on the page puts the editor's caret on what was clicked. */
    #[\Override]
    protected function pointAtSource(int $sourceLine, int $byteCol): void
    {
        $this->handler?->revealSource($sourceLine, $byteCol);
    }

    /** A task's box: `[ ]` becomes `[x]` in the text, and `[x]` (or `[X]`) `[ ]`. */
    #[\Override]
    protected function toggleTask(int $start, int $end): void
    {
        $box = \substr($this->markdown, $start, $end - $start);
        if (null !== $this->bufferPath) { // only a task's own box, three bytes, comes here (RenderedDoc::isAction)
            $this->events?->dispatch(new SourceEditRequested($this->bufferPath, $start, $end, $box, ' ' === $box[1] ? '[x]' : '[ ]'));
        }
    }

    /** The caret reached another line of the text: keep its block in view. */
    #[\Override]
    public function followSource(int $line, int $byteCol): void
    {
        $this->showSourceLine($line);
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
        $this->bufferPath = $path;
        $same = $path === $this->path || \realpath($path) === $this->path;
        if ($same && $markdown === $this->markdown) {
            return; // the same text for the same file: nothing to draw again
        }
        $this->adopt($path, $markdown, fromTop: !$same);
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

    /**
     * A column of margin on the left, as the editor's gutter gives its text:
     * the page reads away from the divider, and a heading's rule never starts
     * against it — the frame's join pass turns a `─` beside a divider into a
     * tee, which would tie the rule to the pane's edge.
     */
    #[\Override]
    protected function margin(int $cols): string
    {
        return $cols > 1 ? ' ' : '';
    }
}
