<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Git\GitCommands;
use Porta\Git\GitRemoteCommands;

/** Git palette commands on the current file/repo. */
final class GitCommandProvider implements CommandProvider
{
    public function __construct(
        private readonly GitCommands $git,
        private readonly GitRemoteCommands $remote,
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
            ->add('git_push', fn () => $this->remote->push(), keyBound: true)
            ->add('git_push_upstream', fn () => $this->remote->pushSetUpstream(), label: 'Git: Push (Set Upstream)');
    }
}
