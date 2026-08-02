<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Container\AsIdeService;
use Porta\Event\BufferSaved;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Tui\Tui;

/**
 * Owns the branch indicator shown in the status bar, refreshed asynchronously
 * through {@see GitInfo} on the shared event loop.
 *
 * Change marks used to live here too. They are a plugin's job now — published
 * through `PluginApi::setChangeMarks()` — because the gutter stripe is one
 * version control system's answer to a question core asks generically, while
 * the branch cell is still core's own status-bar content.
 */
#[AsIdeService]
final class GitRefresher
{
    private ?string $branch = null;

    public function __construct(
        private readonly GitInfo $git,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
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

    #[AsEventListener]
    public function onBufferSaved(BufferSaved $event): void
    {
        $this->refreshBranch();
    }

    #[AsEventListener]
    public function onGitRemoteCompleted(GitRemoteCompleted $event): void
    {
        // A fetch updates the remote-tracking state the branch segment reflects;
        // a pull can move HEAD outright.
        $this->refreshBranch();
    }

    #[AsEventListener]
    public function onGitCommitted(GitCommitted $event): void
    {
        $this->refreshBranch();
    }
}
