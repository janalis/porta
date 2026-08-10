<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\GitBranchSegment;
use Porta\Git\GitCommands;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitSettings;
use Porta\I18n\Translator;
use Porta\Support\Clipboard;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\StatusTone;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The branch indicator as an ordinary status contributor.
 *
 * It was three classes' business before: StatusBarProvider built the cell from
 * GitRefresher and GitSettings, published a branchStart/branchEnd pair, and the
 * context-menu router paired that one span with the one git menu. The cell
 * carries its own menu now, so what is left to check is that the contributor
 * still answers the two questions those three used to answer between them —
 * whether to appear at all, and what right-clicking offers.
 */
final class GitBranchSegmentTest extends TestCase
{
    private function segment(bool $showBranch, ?string $branch): GitBranchSegment
    {
        $translator = new Translator('en');
        $tui = TestTui::create(80, 24);
        $project = new TempProject();
        $git = new GitInfo($project->dir);
        $refresher = new GitRefresher($git, new StatusBarWidget(), $tui);
        // The branch resolves asynchronously through git; set it directly so
        // the test is about the CELL rather than about a subprocess.
        Probe::set($refresher, 'branch', $branch);
        $commands = new GitCommands(
            $git,
            new CodeEditorWidget(),
            new Notifier(new StatusBarWidget(), $tui),
            $tui,
            $translator,
            $refresher,
            new Clipboard($tui),
            new EventDispatcher(),
        );

        return new GitBranchSegment($refresher, new GitSettings(['gitShowBranch' => $showBranch]), $commands, $translator);
    }

    public function testTheSettingSuppressesTheCellEntirely(): void
    {
        // Not merely hidden: a suppressed cell contributes no span either, so
        // right-clicking where it would have been offers nothing.
        $this->assertSame([], $this->segment(showBranch: false, branch: 'main')->statusCells());
    }

    public function testNoResolvedBranchMeansNoCell(): void
    {
        // The branch resolves through an async git call; until it lands there
        // is nothing to show, which is the normal state for the first frames.
        $this->assertSame([], $this->segment(showBranch: true, branch: null)->statusCells());
    }

    public function testAResolvedBranchIsAnAccentCellCarryingItsOwnMenu(): void
    {
        $cells = $this->segment(showBranch: true, branch: 'trunk')->statusCells();
        self::assertCount(1, $cells);
        $cell = $cells[0];

        $this->assertNotNull($cell);
        $this->assertStringContainsString('trunk', $cell->text);
        $this->assertSame(StatusTone::Accent, $cell->tone, 'picked out from the prefix, because it is clickable');
        $this->assertNotNull($cell->menu, 'and it carries what a right-click offers');
        $this->assertSame(
            ['Switch Branch…', 'New Branch…', 'Delete Branch…'],
            array_map(static fn (object $item): string => $item->label, ($cell->menu)()),
        );
    }
}
