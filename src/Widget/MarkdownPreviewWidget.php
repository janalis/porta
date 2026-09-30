<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;

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
 * puts the editor's caret on the word clicked and scrolls it into view.
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

    /**
     * A click on the page puts the editor's caret on what was clicked: $word
     * where the clicked block begins in the text this page was drawn from —
     * searched from $sourceLine to the block's end, since a wrapped paragraph
     * is one rendered block of several source lines — or the block's start
     * when the word is not there as drawn. Its edges are trimmed first: markup
     * parts a drawn `bold.` from its source `**bold**.`.
     */
    #[\Override]
    protected function pointAtSource(int $sourceLine, string $word): void
    {
        $lines = \explode("\n", $this->markdown);
        $needle = \trim($word, characters: '.,;:!?()[]{}*_`"\'');
        [$line, $byte] = [$sourceLine, 0];
        for ($n = $sourceLine; '' !== $needle && $n < \count($lines) && ($n === $sourceLine || '' !== \trim($lines[$n])); ++$n) {
            $found = \strpos($lines[$n], $needle);
            if (false !== $found) {
                [$line, $byte] = [$n, $found];
                break;
            }
        }
        $this->handler?->revealSource($line, $byte);
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
