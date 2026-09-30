<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A paint barrier that gives up says what WAS on screen.
 *
 * The harness's own contract, pinned against the real Ide because the screen
 * is the part worth checking: a timeout that loses the grid still throws, so
 * only a test reading the message notices it went.
 */
final class PaintBarrierTimeoutTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testATimedOutPaintWaitCarriesTheScreen(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['a.php' => "<?php\necho 'on screen';\n"]);
        $message = null;

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal) use (&$message): void {
                    try {
                        $this->awaitPainted($tui, $terminal, 'never painted', timeout: 0.1);
                    } catch (\RuntimeException $gaveUp) {
                        $message = $gaveUp->getMessage();
                    }
                },
            ],
        );

        $this->assertIsString($message, 'the wait gave up rather than passing');
        $this->assertStringContainsString('waiting for never painted to paint', $message, 'the message still names what was lost');
        $this->assertMatchesRegularExpression("/\\n\\| .*echo 'on screen';/", $message, 'and carries the editor row that WAS on screen');
    }
}
