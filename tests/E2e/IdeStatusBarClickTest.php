<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
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
                    self::assertNotNull($row, 'the status bar is on screen');
                    $col = \mb_strpos($grid[$row], needle: '1:1');
                    self::assertIsInt($col, 'the caret position cell is on the status bar');
                },
                260 => static function ($tui) use (&$row, &$col): void {
                    // SGR mouse columns and rows are 1-based.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                },
                320 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->frame($tui);
                    $grid = $this->screenGrid($tui, $terminal);
                    self::assertNotNull(
                        $this->rowOf($grid, 'Go to line'),
                        'clicking the caret cell opens the Go to line prompt',
                    );
                },
            ],
        );
    }
}
