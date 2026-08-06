<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\Container\AsEager;
use Porta\Workbench\Menu\MenuContribution;
use Porta\Workbench\Menu\MenuContributions;
use Porta\Workbench\Menu\MenuModel;

/**
 * Git's top-level menu and the commands that survive its view being open.
 *
 * Both used to be constants in {@see MenuModel} — a `DEFS['Git']` group and a
 * `VIEW_SAFE['git']` list — and the whole group was hidden by a
 * `'Git' !== $title || $git->isRepository()` closure the rebuilder passed in,
 * which existed for this one group and no other.
 *
 * The two lists belong together. A contributor that supplied its menu but not
 * its view-safe list would grey out its own items the moment its view opened:
 * the gate enables only what the active view names, so an empty list means a
 * dead menu with nothing to say why.
 *
 * Placed after Run, which is where the group sat among core's own.
 */
#[AsEager]
final readonly class GitMenu
{
    public function __construct(
        MenuContributions $menus,
        GitInfo $git,
    ) {
        $menus->register(new MenuContribution(
            title: 'Git',
            after: 'Run',
            items: [
                'i:ide_git',
                MenuModel::SEP,
                'i:git_stage',
                'i:git_commit',
                'i:git_undo_commit',
                'i:git_blame',
                'i:git_branches',
                MenuModel::SEP,
                'i:git_fetch',
                'i:git_pull',
                'i:git_push',
            ],
            // Outside a repository none of these verbs is registered, so every
            // item would render dead; the group is hidden instead.
            visible: static fn (): bool => $git->isRepository(),
            viewId: 'git',
            viewSafe: [
                'ide_quit',
                'ide_git',
                'git_commit',
                'git_undo_commit',
                'git_fetch',
                'git_pull',
                'git_push',
                'git_branches',
                'ide_command_palette',
                'ide_help',
                'ide_welcome',
            ],
        ));
    }
}
