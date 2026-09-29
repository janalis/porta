<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Container\ToolLaunch;
use Porta\History\RecentProjectsStore;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Porta launched as git's difftool / mergetool, with nothing answering the
 * launch (no bundled plugin loads in this suite): what core owns on its own —
 * the ephemeral run, the editor fallback, and the merge verdict.
 */
final class IdeGitToolLaunchTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    private const string CONFLICTED = "<?php\n<<<<<<< HEAD\necho 'ours';\n=======\necho 'theirs';\n>>>>>>> feature\n";

    public function testWithNothingToAnswerADiffOpensTheRightHandFileAndLeavesNoTrace(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['right.php' => "<?php\necho 'right-hand side';\n"]);
        $left = $project->path('left_LOCAL.php');
        \file_put_contents($left, data: "<?php\necho 'left';\n");
        $this->allowFirstRunWelcome(); // a first run on this root — and still no Welcome
        $ide = null;

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $running) use (&$ide): void {
                    $ide = $running;
                    $this->awaitPainted($tui, $terminal, 'right-hand side');
                    $this->await(static fn (): bool => \in_array('Enable the git plugin to see the comparison', Toasts::all($running), strict: true), what: 'the reason there is no comparison');
                    $this->assertStringNotContainsString('Welcome', \implode("\n", $this->screenGrid($tui, $terminal)));
                },
            ],
            tool: ToolLaunch::diff($left, $project->path('right.php')),
        );

        $this->assertInstanceOf(Ide::class, $ide);
        $this->assertSame(0, $ide->exitCode());
        $this->assertFileDoesNotExist($project->path('.porta/session.json'), 'the project\'s session is not a diff\'s to overwrite');
        $this->assertNotContains(\realpath($project->dir), Services::resolve($ide, RecentProjectsStore::class)->all(), 'a tool run is not the user opening the project');
    }

    public function testAMergeLeftWithConflictMarkersExitsOne(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['m.php' => self::CONFLICTED]);
        $ide = null;

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $running) use (&$ide): void {
                    $ide = $running;
                    $this->awaitPainted($tui, $terminal, "echo 'theirs';");
                },
            ],
            tool: self::merge($project),
        );

        $this->assertInstanceOf(Ide::class, $ide);
        $this->assertSame(1, $ide->exitCode(), 'git keeps the file unresolved');
    }

    public function testAMergeSavedWithoutMarkersExitsZero(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['m.php' => self::CONFLICTED]);
        $ide = null;

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $running) use (&$ide, $project): void {
                    $ide = $running;
                    $this->awaitPainted($tui, $terminal, "echo 'theirs';");
                    // Resolved and saved — as Alt+M then Ctrl+S would leave it.
                    \file_put_contents($project->path('m.php'), data: "<?php\necho 'ours';\necho 'theirs';\n");
                },
            ],
            tool: self::merge($project),
        );

        $this->assertInstanceOf(Ide::class, $ide);
        $this->assertSame(0, $ide->exitCode());
    }

    public function testAMergeWhoseResultIsGoneExitsOne(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['m.php' => "<?php\n"]);
        $ide = null;

        $this->runIde(
            $project,
            null,
            [
                0 => static function (Tui $tui, VirtualTerminal $terminal, Ide $running) use (&$ide, $project): void {
                    $ide = $running;
                    \unlink($project->path('m.php'));
                },
            ],
            tool: self::merge($project),
        );

        $this->assertInstanceOf(Ide::class, $ide);
        $this->assertSame(1, $ide->exitCode(), 'nothing to call resolved');
    }

    private static function merge(TempProject $project): ToolLaunch
    {
        return ToolLaunch::merge($project->path('m_LOCAL.php'), $project->path('m_REMOTE.php'), $project->path('m_BASE.php'), $project->path('m.php'));
    }
}
