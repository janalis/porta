<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Container\AsIdeService;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Owns the asynchronous git-state refreshes: the branch indicator shown in
 * the status bar and the per-buffer change marks shown in the editor gutter.
 * Everything is a callback on the shared event loop via {@see GitInfo}.
 */
#[AsIdeService]
final class GitRefresher
{
    private ?string $branch = null;

    public function __construct(
        private readonly GitInfo $git,
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly ?GitSettings $gitSettings = null,
    ) {}

    /** The branch indicator for the status bar (refreshed asynchronously). */
    public function branch(): ?string
    {
        return $this->branch;
    }

    public function refreshBranch(): void
    {
        if (!$this->git->isAvailable()) {
            return;
        }
        $this->git->branch(function (?string $branch): void {
            $this->branch = $branch;
            $this->statusBar->invalidate();
            $this->tui->requestRender();
        });
    }

    /** Recompute git change marks for a buffer (on open and after save). */
    public function refreshMarks(Buffer $buffer): void
    {
        if (!$this->git->isAvailable() || $buffer->isImage() || false === $this->gitSettings?->gutterMarks()) {
            return;
        }
        $this->git->diff($buffer->path, function (array $marks) use ($buffer): void {
            $buffer->gitMarks = $marks;
            if ($buffer === $this->buffers->active()) {
                $this->editor->setGitMarks($marks);
            }
            $this->tui->requestRender();
        });
    }

    /** Recompute git change marks for the active buffer, if any. */
    public function refreshActiveMarks(): void
    {
        if (null !== ($buffer = $this->buffers->active())) {
            $this->refreshMarks($buffer);
        }
    }

    /** Text opens get change marks; image/help tabs never diffed and still don't. */
    #[AsEventListener]
    public function onBufferOpened(BufferOpened $event): void
    {
        if (!$event->buffer->isImage() && !$event->buffer->isHelp()) {
            $this->refreshMarks($event->buffer);
        }
    }

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->refreshMarks($event->buffer);
        $this->refreshBranch();
    }

    #[AsEventListener]
    public function onGitRemoteCompleted(GitRemoteCompleted $event): void
    {
        // A pull can move HEAD and rewrite file contents; a fetch updates
        // the remote-tracking state the branch segment may reflect.
        $this->refreshBranch();
        $this->refreshActiveMarks();
    }

    #[AsEventListener]
    public function onGitCommitted(GitCommitted $event): void
    {
        $this->refreshActiveMarks();
        $this->refreshBranch();
    }
}
