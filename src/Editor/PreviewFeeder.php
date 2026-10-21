<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Support\Scheduler;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\Tui\Tui;

/**
 * Keeps a live preview in step with the text it previews: the pane is handed
 * the document's text when the buffer comes on screen, and again once typing
 * pauses.
 *
 * It watches from the frame hook, the way {@see StructureFollowsCaret}
 * follows the caret, rather than from an edit event: every mutation — typing,
 * a formatter, an LSP edit, an undo, a reload from disk — moves
 * {@see Document::$version}, and there is no event for all of them. The check
 * is two comparisons, and only a version it has not fed arms the timer.
 *
 * It also carries the caret the other way from click-to-source: when the
 * caret reaches another line, the pane is asked to bring what that line
 * wrote into view ({@see EditorPaneWidget::followSource()}). And when the
 * editor scrolls, the pane and its flank are repainted: one that draws level
 * with the text — the merge view's sides — reads the editor's top line as it
 * renders, and a render it has cached would not.
 *
 * The pause is trailing and re-armed on every change, so a burst of typing
 * costs one render at its end, not one per keystroke. What that render costs
 * is the pane's business — the image and graph panes spawn a converter, and
 * both keep the previous picture up until the new one lands.
 */
#[AsEager]
#[AsIdeService]
final class PreviewFeeder
{
    /** How long the text has to stay still before the preview follows it. */
    public const float PAUSE_SECONDS = 0.15;

    /** What the pane was last handed, so an unchanged document costs nothing. */
    private ?string $fedPath = null;
    private int $fedVersion = -1;

    /** Where the caret was when the preview last followed it: path, then line. */
    private ?string $followedPath = null;
    private int $followedLine = -1;

    /** The editor's view — its top line and pinned headers — when the preview was last repainted for it. */
    private string $paintedView = '';

    /** The version the armed timer will feed, so a frame that sees it again leaves the timer alone. */
    private ?string $pendingPath = null;
    private int $pendingVersion = -1;
    private ?string $timer = null;

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly EditorPanes $panes,
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
        private readonly CodeEditorWidget $editor,
        ?FrameHooks $frames = null,
    ) {
        $frames?->onBeforeFrame($this->watch(...));
    }

    /** Hand $buffer's text to its preview now — it has just come on screen. */
    public function feed(Buffer $buffer): void
    {
        $this->cancel();
        $pane = $this->panes->shownPreview($buffer);
        if (null === $pane) {
            return;
        }
        $pane->show($buffer->path, $buffer->document->getText());
        $this->fedPath = $buffer->path;
        $this->fedVersion = $buffer->document->version;
    }

    /**
     * Cheap and idempotent, per the hook's contract: arm the pause only for a
     * version not yet fed or pending, and let the preview follow the caret
     * only when it reached another line.
     */
    public function watch(): void
    {
        $buffer = $this->buffers->active();
        $pane = null === $buffer ? null : $this->panes->shownPreview($buffer);
        if (null === $buffer || null === $pane) {
            return;
        }
        $this->follow($buffer, $pane);
        $this->repaintOnScroll($pane);
        $version = $buffer->document->version;
        $settled = ($buffer->path === $this->fedPath && $version === $this->fedVersion) || ($buffer->path === $this->pendingPath && $version === $this->pendingVersion);
        if ($settled) {
            return;
        }
        $this->cancel();
        $this->pendingPath = $buffer->path;
        $this->pendingVersion = $version;
        $this->timer = $this->scheduler->delay(self::PAUSE_SECONDS, function (): void {
            $this->timer = null;
            $active = $this->buffers->active();
            if (null !== $active) {
                $this->feed($active);
                $this->tui->requestRender();
            }
        });
    }

    /** The caret moved to another line of the previewed text: the preview brings it into view. */
    private function follow(Buffer $buffer, EditorPaneWidget $pane): void
    {
        $document = $buffer->document;
        if ($buffer->path === $this->followedPath && $document->cursorLine === $this->followedLine) {
            return;
        }
        $this->followedPath = $buffer->path;
        $this->followedLine = $document->cursorLine;
        $pane->followSource($document->cursorLine, $document->cursorCol);
        $this->tui->requestRender();
    }

    /**
     * The editor's view moved since the last repaint: the pane and its flank
     * draw again — and once more on the next frame. The pinned sticky-scroll
     * headers settle while the editor renders, AFTER a flank seated before it
     * has drawn this frame from the last ones; the frame asked for here is the
     * one that sees them settled, and stops when the view stays put.
     */
    private function repaintOnScroll(EditorPaneWidget $pane): void
    {
        $viewport = $this->editor->viewport();
        // What each row shows, not only where the view starts: under soft
        // wrap the view moves WITHIN a tall top line and a toggle or a resize
        // re-breaks every line, and a code lens, a line block or a fold moves
        // every line below it without the top line moving at all.
        $rows = \array_map(
            static fn (?EditorRow $row): string => null === $row ? '-' : $row->line.'.'.$row->inLine.'/'.$row->segments,
            $this->editor->screenRows($viewport->renderRows - \count($viewport->stickyLines)),
        );
        $view = \implode(',', $viewport->stickyLines).'|'.\implode(' ', $rows);
        if ($view === $this->paintedView) {
            return;
        }
        $this->paintedView = $view;
        $pane->invalidate();
        $pane->flank()?->invalidate();
        $this->tui->requestRender();
    }

    private function cancel(): void
    {
        if (null !== $this->timer) {
            $this->scheduler->cancel($this->timer);
            $this->timer = null;
        }
        $this->pendingPath = null;
        $this->pendingVersion = -1;
    }
}
