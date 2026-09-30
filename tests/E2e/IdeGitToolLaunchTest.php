<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
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

    /**
     * With a listener, the launch is ITS to answer: core opens nothing and
     * says nothing. When the comparison is done, the plugin asks to quit —
     * the same guarded quit Ctrl+Q runs, so a clean session just ends.
     */
    public function testAListenerAnswersTheLaunchAndMayAskToQuit(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['right.php' => "<?php\necho 'right-hand side';\n", 'left.php' => "<?php\n"]);
        $dir = $project->path('.porta/plugins/diff-answer');
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Event\DiffToolLaunched;
            use Porta\Event\QuitRequested;
            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Plugin\Api\PluginApi;

            return new class('diff-answer') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $root = $api->projectRoot();
                    $api->on(DiffToolLaunched::class, static function (DiffToolLaunched $launch) use ($root): void {
                        file_put_contents($root.'/answered', basename($launch->left).' vs '.basename($launch->right));
                    });
                    $api->registerCommand('done', 'Done', static fn () => $api->emit(new QuitRequested()));
                }
            };
            PHP);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->assertFileExists($project->path('answered'), 'the launch reached the listener');
                    $this->assertSame('left.php vs right.php', \file_get_contents($project->path('answered')));
                    $this->assertNotContains('Enable the git plugin to see the comparison', Toasts::all($ide), 'core stays out of an answered launch');
                    $this->assertStringNotContainsString('right-hand side', \implode("\n", $this->screenGrid($tui, $terminal)), 'and opens no fallback file');

                    Services::resolve($ide, CommandRegistry::class)->run('diff-answer:done');

                    $this->assertFalse($tui->isRunning(), 'a clean session quits on the plugin\'s request');
                },
            ],
            tool: ToolLaunch::diff($project->path('left.php'), $project->path('right.php')),
        );
    }

    public function testWithNothingToAnswerADirectoryDiffOpensNothingAndSaysWhy(): void
    {
        // `git difftool --dir-diff` hands over two directories: there is no
        // file to fall back to, only the reason to give.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['left/a.php' => "<?php\n", 'right/a.php' => "<?php\necho 1;\n"]);
        $ide = null;

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $running) use (&$ide): void {
                    $ide = $running;
                    $this->await(static fn (): bool => \in_array('Enable the git plugin to see the comparison', Toasts::all($running), strict: true), what: 'the reason there is no comparison');
                },
            ],
            tool: ToolLaunch::diff($project->path('left'), $project->path('right')),
        );

        $this->assertInstanceOf(Ide::class, $ide);
        $this->assertSame(0, $ide->exitCode());
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
