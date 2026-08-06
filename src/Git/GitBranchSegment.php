<?php

declare(strict_types=1);

namespace Porta\Git;

use Porta\I18n\Translator;
use Porta\Ui\Glyphs;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The branch indicator, as an ordinary status contributor.
 *
 * {@see \Porta\Workbench\StatusBarProvider} used to build this cell itself,
 * holding {@see GitRefresher} and {@see GitSettings} as constructor
 * dependencies and publishing a `branchStart`/`branchEnd` pair so the pointer
 * router could hit-test this one cell and open this one menu. Three classes
 * naming git to make one span clickable; now the cell carries its own menu and
 * the status bar knows nothing about what is in it.
 *
 * Priority 200 puts it after the debugger's segment and before plugins', which
 * is the order the prefix read in before.
 */
#[AsTaggedItem(priority: 200)]
final readonly class GitBranchSegment implements \Porta\Workbench\StatusSegment
{
    public function __construct(
        private GitRefresher $refresher,
        private GitSettings $settings,
        private GitCommands $commands,
        private Translator $translator,
    ) {}

    public function statusCell(): ?StatusCell
    {
        if (!$this->settings->showBranch()) {
            return null;
        }
        $branch = $this->refresher->branch();
        if (null === $branch) {
            return null;
        }

        return new StatusCell(
            Glyphs::STATUS_BRANCH." {$branch}",
            StatusTone::Accent,
            fn (): array => $this->branchItems(),
        );
    }

    /**
     * What a right-click on the branch offers. These were three entries in
     * `ContextMenuBuilder::branchItems()`, reached through a git-shaped branch
     * in the context-menu router.
     *
     * @return list<MenuItem>
     */
    private function branchItems(): array
    {
        return [
            new MenuItem($this->translator->trans('Switch Branch…'), '', fn () => $this->commands->branches()),
            new MenuItem($this->translator->trans('New Branch…'), '', fn () => $this->commands->newBranch()),
            new MenuItem($this->translator->trans('Delete Branch…'), '', fn () => $this->commands->deleteBranch()),
        ];
    }
}
