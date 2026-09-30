<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Clicking the status bar, through the real mouse router.
 *
 * The status bar had exactly one hit-test — right-click menus — so its
 * right-hand cells were inert text. This drives the whole chain the way a user
 * does: a terminal mouse sequence in, a screen to read out. Both the column
 * arithmetic and the routing are only correct together, and each is convincing
 * alone.
 */
final class IdeStatusBarClickTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testClickingTheCaretPositionOpensGoToLine(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n\$a = 1;\n\$b = 2;\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal) use (&$row, &$col): void {
                    $this->frame($tui);
                    $grid = $this->screenGrid($tui, $terminal);
                    // The status row is found by its content, not by counting
                    // rows: the dock and the find bar move it.
                    $row = $this->rowOf($grid, 'LSP:');
                    $this->assertNotNull($row, 'the status bar is on screen');
                    $col = \mb_strpos($grid[$row], needle: '1:1');
                    $this->assertIsInt($col, 'the caret position cell is on the status bar');
                },
                260 => static function ($tui) use (&$row, &$col): void {
                    // SGR mouse columns and rows are 1-based.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                },
                320 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->frame($tui);
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Go to line'), 'clicking the caret cell opens the Go to line prompt');
                },
            ],
        );
    }

    /**
     * PhpStorm's way back to the tree: a double-click on the file part expands
     * the tree down to the file and selects it, as a double-click on its tab
     * does, and focus stays in the editor.
     */
    public function testDoubleClickingTheFileRevealsItInTheTree(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'src/deep/Target.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('src/deep/Target.php'), OpenFocus::Editor);
                    $this->frame($tui);
                    $tree = $tui->getById('tree');
                    $this->assertNotSame('Target.php', $tree->selectedNode()?->name, 'opening a file does not reveal it in the tree');

                    [$col, $row] = $this->statusFile($tui, $terminal, 'src/deep/Target.php');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));
                    $this->assertNotSame('Target.php', $tree->selectedNode()?->name, 'a single click reveals nothing');
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));

                    $this->assertSame('Target.php', $tree->selectedNode()?->name, 'the double-click revealed and selected the deep file');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'focus stayed in the editor');
                },
            ],
        );
    }

    /**
     * A right-click on the file part offers the way back to the tree and the
     * tree's path-copy pair; its first item selects the file in the tree and
     * focuses it.
     */
    public function testRightClickingTheFileOffersTheTreeMenu(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n", 'src/deep/Target.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, BufferService::class)
                        ->openFile($project->path('src/deep/Target.php'), OpenFocus::Editor);
                    $this->frame($tui);
                    [$col, $row] = $this->statusFile($tui, $terminal, 'src/deep/Target.php');
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 1, $row + 1));
                    $this->awaitPainted($tui, $terminal, 'Select Opened File in Tree');
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertNotNull($this->rowOf($grid, 'Copy Relative Path'), 'the tree\'s path-copy pair is offered');
                    $this->assertNull($this->rowOf($grid, 'Close Others'), 'not the tab menu');

                    $tui->handleInput("\r"); // the first item is selected
                    $this->frame($tui);
                    $this->assertSame('Target.php', $tui->getById('tree')->selectedNode()?->name, 'the file is selected in the tree');
                    $this->assertSame('tree', $tui->getFocus()?->getId(), 'and the tree has focus');
                },
            ],
        );
    }

    /**
     * A plugin's status cell that carries a menu wears the select's `▾`, and
     * a select opens on a plain LEFT click — the branch cell is the one that
     * asked for it, core does not know which cell is whose.
     */
    public function testLeftClickingAPluginCellWithAMenuOpensIt(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n"]);
        $dir = $project->path('.porta/plugins/cell-menu');
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Plugin\Api\PluginApi;
            use Porta\Workbench\Menu\MenuItem;
            use Porta\Workbench\StatusCell;

            return new class('cell-menu') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $api->registerStatusSegment(static fn (): StatusCell => new StatusCell(
                        'on-trunk',
                        menu: static fn (): array => [new MenuItem('Switch to another branch', '', static function (): void {})],
                    ));
                }
            };
            PHP);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitPainted($tui, $terminal, 'on-trunk');
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = $this->rowOf($grid, 'on-trunk');
                    $this->assertNotNull($row);
                    $col = \mb_strpos($grid[$row], needle: 'on-trunk');
                    $this->assertIsInt($col);

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 2, $row + 1));
                    $this->awaitPainted($tui, $terminal, 'Switch to another branch');
                },
            ],
        );
    }

    /**
     * Where the status bar draws $path, 0-based: the row is found by its
     * content, since the dock and the find bar move it.
     *
     * @return array{int, int}
     */
    private function statusFile(Tui $tui, VirtualTerminal $terminal, string $path): array
    {
        $grid = $this->screenGrid($tui, $terminal);
        $row = $this->rowOf($grid, 'LSP:');
        $this->assertNotNull($row, 'the status bar is on screen');
        $col = \mb_strpos($grid[$row], $path);
        $this->assertIsInt($col, 'the file part is on the status bar');

        return [$col, $row];
    }
}
