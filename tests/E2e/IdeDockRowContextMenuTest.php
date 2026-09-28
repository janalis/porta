<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\DiagnosticSeverity;
use Porta\Ide;
use Porta\Lsp\Uri;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A right-click on a results row offers what a right-click on the file it
 * names offers in the tree: its path, to copy. Before, the dock's rows were
 * the one list that answered "No context menu here".
 */
final class IdeDockRowContextMenuTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    public function testRightClickingAResultsRowOffersItsPath(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\n// two\n"]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $tui->handleInput("\x1b6"); // alt+6 → Show Problems
                    $this->awaitPainted($tui, $terminal, '0 results');
                    Services::resolve($ide, BufferService::class)->applyDiagnostics(
                        Uri::fromPath($project->path('a.php')),
                        [1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'live boom', 'col' => 0, 'endCol' => 3]]],
                        'test',
                    );
                    $this->awaitPainted($tui, $terminal, '✗ live boom');

                    // The row in the dock, below its strip: the editor draws the
                    // same message inline, higher up.
                    $grid = $this->screenGrid($tui, $terminal);
                    $strip = $this->rowOf($grid, 'Problems');
                    $this->assertNotNull($strip, 'the dock strip is on screen');
                    $below = $this->rowOf(\array_slice($grid, $strip), '✗ live boom');
                    $this->assertNotNull($below, 'the diagnostic row is in the dock');
                    $row = $strip + $below;
                    $col = \mb_strpos($grid[$row], needle: 'live boom');
                    $this->assertIsInt($col);
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 1, $row + 1));
                    $this->awaitPainted($tui, $terminal, 'Copy Relative Path');
                    $this->assertNotNull($this->rowOf($this->screenGrid($tui, $terminal), 'Copy Path'));

                    // The filter row names no file: no menu, and says so.
                    $tui->handleInput("\x1b");
                    $this->frame($tui);
                    $filter = $this->rowOf($grid, 'Filter');
                    $this->assertNotNull($filter, 'the filter row is on screen');
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", 3, $filter + 1));
                    $this->awaitPainted($tui, $terminal, 'No context menu here');
                },
            ],
        );
    }
}
