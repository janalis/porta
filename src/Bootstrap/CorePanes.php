<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorPanes;
use Porta\Editor\TextPreview;
use Porta\I18n\Translator;
use Porta\Support\Files;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\TextPreviewWidget;

/**
 * Registers the panes core ships — the help browser and the read-only look at
 * a file too large to edit — into the same registry a plugin's
 * `registerPane()` reaches through
 * {@see \Porta\Editor\PaneRegistrar}.
 *
 * Core registering through the plugin extension point is what keeps the point
 * honest: it had three consumers inside `src/` before any plugin depended on
 * it, and when the two viewers moved out only their CODE changed — each took
 * its registration and its loader to its own `plugin.php` unaltered. What is
 * left here is the one pane core genuinely owns.
 *
 * A loader THROWS to refuse a file — too large to decode, not UTF-8 — and
 * `BufferService` flashes the message it carries. That is the one place a pane
 * gets to say "not this file" after its claim already said "mine".
 */
#[AsIdeService]
final class CorePanes
{
    public function __construct(
        private readonly EditorPanes $panes,
        private readonly HelpViewerWidget $helpViewer,
        private readonly TextPreviewWidget $textPreview,
        private readonly Translator $translator,
    ) {}

    public function register(): void
    {
        $this->panes->register($this->helpViewer, $this->loadHelp(...));
        $this->panes->register($this->textPreview, $this->loadPreview(...));
    }

    /**
     * A BOUNDED prefix of a file too large to edit, plus its real size.
     *
     * Reading all of it would reintroduce, one layer down, the exact problem
     * the size cap exists to avoid: a database dump or a bundled asset is not
     * bounded by anything, and `file_get_contents` on it takes the session.
     * One cap's worth is read, and the pane says so on its top line.
     *
     * Cut at the last NEWLINE inside the cap, not at the byte: a prefix ending
     * mid-line would show a half-line the file does not contain, and cutting
     * mid-CHARACTER would fail the UTF-8 check below over bytes that are fine
     * in the whole file. A cap's worth with no newline in it at all — a minified
     * bundle on one line — keeps the raw prefix, because there is nothing
     * better to cut at and the alternative is showing nothing.
     */
    private function loadPreview(string $path): TextPreview
    {
        $bytes = (int) \filesize($path);
        $prefix = (string) \file_get_contents($path, length: Files::MAX_TEXT_BYTES);
        $truncated = \strlen($prefix) < $bytes;
        if ($truncated && false !== ($lastBreak = \strrpos($prefix, needle: "\n"))) {
            $prefix = \substr($prefix, offset: 0, length: $lastBreak);
        }
        if ('' !== $prefix && !\mb_check_encoding($prefix, encoding: 'UTF-8')) {
            throw new \RuntimeException($this->translator->trans('Not a UTF-8 text file'));
        }

        return new TextPreview($prefix, $bytes, $truncated);
    }

    /** A help page is text: read it, refusing anything that is not UTF-8. */
    private function loadHelp(string $path): string
    {
        $content = Files::read($path);
        if ('' !== $content && !\mb_check_encoding($content, encoding: 'UTF-8')) {
            throw new \RuntimeException($this->translator->trans('Not a UTF-8 text file'));
        }

        return $content;
    }
}
