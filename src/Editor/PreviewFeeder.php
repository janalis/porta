<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Support\Scheduler;
use Porta\Ui\FrameHooks;
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

    /** The version the armed timer will feed, so a frame that sees it again leaves the timer alone. */
    private ?string $pendingPath = null;
    private int $pendingVersion = -1;
    private ?string $timer = null;

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly EditorPanes $panes,
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
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

    /** Cheap and idempotent, per the hook's contract: arm the pause only for a version not yet fed or pending. */
    public function watch(): void
    {
        $buffer = $this->buffers->active();
        if (null === $buffer || null === $this->panes->shownPreview($buffer)) {
            return;
        }
        $version = $buffer->document->version;
        if ($buffer->path === $this->fedPath && $version === $this->fedVersion) {
            return;
        }
        if ($buffer->path === $this->pendingPath && $version === $this->pendingVersion) {
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
