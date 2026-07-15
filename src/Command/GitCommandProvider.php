<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Git\BlameAnnotations;
use Porta\Git\GitCommands;
use Porta\Git\GitRemoteCommands;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Git palette commands on the current file/repo. */
#[AsTaggedItem(priority: 100)]
final class GitCommandProvider implements CommandProvider
{
    public function __construct(
        private readonly GitCommands $git,
        private readonly GitRemoteCommands $remote,
        private readonly BlameAnnotations $blame,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('git_stage', fn () => $this->git->stageCurrent(), label: 'Git: Stage Current File')
            ->add('git_commit', fn () => $this->git->commit(), label: 'Git: Commit…')
            ->add('git_blame', fn () => $this->git->blameLine(), label: 'Git: Blame Current Line')
            ->add('git_undo_commit', fn () => $this->git->undoLastCommit(), label: 'Git: Undo Last Commit')
            ->add('git_branches', fn () => $this->git->branches(), label: 'Git: Branches…')
            ->add('git_fetch', fn () => $this->remote->fetch(), label: 'Git: Fetch')
            ->add('git_pull', fn () => $this->remote->pull(), label: 'Git: Pull')
            ->add('git_push', fn () => $this->remote->push(), keyBound: KeyDispatch::Bound)
            ->add('git_push_upstream', fn () => $this->remote->pushSetUpstream(), label: 'Git: Push (Set Upstream)')
            ->add('git_remote_cancel', fn () => $this->remote->cancelOperation(), label: 'Git: Cancel Remote Operation')
            ->add('git_stash', fn () => $this->git->stash(), label: 'Git: Stash')
            ->add('git_stash_pop', fn () => $this->git->stashPop(), label: 'Git: Stash Pop')
            ->add('git_stashes', fn () => $this->git->stashes(), label: 'Git: Stashes…')
            ->add('git_file_history', fn () => $this->git->fileHistory(), label: 'Git: File History')
            ->add('git_toggle_blame', fn () => $this->blame->toggle(), label: 'Git: Toggle Blame Annotations');
    }
}
