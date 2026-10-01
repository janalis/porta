<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Text the IDE shows is shown, never obeyed: a file named with an escape in
 * it — a repository can hold one — or a message carrying one draws as its
 * characters, and the escape never reaches the terminal. An OSC 52 there
 * would write the user's clipboard just by being on screen.
 */
final class ShownTextIsInertTest extends TestCase
{
    use RunsIde;

    public function testAnEscapeInAFileNameOrAToastNeverReachesTheTerminal(): void
    {
        $evil = static fn (string $mark): string => "a\x1b]52;c;".$mark."\x07b";
        $project = new TempProject(['src/A.php' => "<?php\n", $evil('FILENAME').'.php' => 'x']);

        $this->runIde($project, $project->path('src/A.php'), [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($evil): void {
                $this->awaitPainted($tui, $terminal, 'a]52;c;FILENAMEb.php');
                Services::resolve($ide, Notifier::class)->flash($evil('TOAST')."\x1b[2J");
                $this->awaitPainted($tui, $terminal, 'a]52;c;TOASTb[2J');
                foreach (['FILENAME', 'TOAST'] as $mark) {
                    $this->assertStringNotContainsString("\x1b]52;c;".$mark, $terminal->getOutput(), $mark.' was shown, not obeyed');
                }
            },
        ]);
    }
}
