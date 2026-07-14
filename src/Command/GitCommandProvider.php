<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Git\GitCommands;

/** Git palette commands on the current file/repo. */
final class GitCommandProvider implements CommandProvider
{
    public function __construct(
        private readonly GitCommands $git,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('git_stage', fn () => $this->git->stageCurrent(), label: 'Git: Stage Current File')
            ->add('git_commit', fn () => $this->git->commit(), label: 'Git: Commit…')
            ->add('git_blame', fn () => $this->git->blameLine(), label: 'Git: Blame Current Line')
            ->add('git_undo_commit', fn () => $this->git->undoLastCommit(), label: 'Git: Undo Last Commit');
    }
}
