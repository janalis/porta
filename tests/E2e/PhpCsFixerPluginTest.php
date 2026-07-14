<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * The shipped php-cs-fixer plugin (.porta/plugins/php-cs-fixer/plugin.php),
 * exercised against stub fixer binaries — the real plugin file is loaded from
 * the repo so the test keeps it honest.
 */
final class PhpCsFixerPluginTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /**
     * @param array<int, callable> $steps ms => step($tui, $terminal, $ide)
     */
    private function runIde(TempProject $project, ?string $file, array $steps, ?float $duration = null): VirtualTerminal
    {
        $duration ??= max([] === $steps ? 0.0 : (max(array_keys($steps)) / 1000) + 0.5, 0.7);

        $terminal = new VirtualTerminal(columns: 160, rows: 24);
        $ide = new Ide($project->dir, $file, $terminal);
        $tui = $ide->getTui();

        $timers = [];
        foreach ($steps as $delayMs => $step) {
            $timers[] = EventLoop::delay((int) $delayMs / 1000, static fn () => $step($tui, $terminal, $ide));
        }
        $timers[] = EventLoop::delay($duration, static fn () => $tui->stop());

        try {
            $ide->run();
        } finally {
            foreach ($timers as $timer) {
                EventLoop::cancel($timer);
            }
        }

        return $terminal;
    }

    /** A project with the real plugin file and (optionally) a stub fixer binary. */
    private function project(?int $stubExitCode = 0): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/php-cs-fixer/plugin.php');
        $project = new TempProject([
            '.porta/plugins/php-cs-fixer/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x=1;\n",
            'notes.txt' => "plain\n",
        ]);

        if (null !== $stubExitCode) {
            // PATH is /nonexistent, so the shebang must point at the php binary directly.
            $stub = '#!'.\PHP_BINARY.' -n'."\n<?php\n"."file_put_contents(\$argv[2], file_get_contents(\$argv[2]).\"// fixed\\n\");\n"."exit({$stubExitCode});\n";
            mkdir($project->path('vendor/bin'), 0777, true);
            file_put_contents($project->path('vendor/bin/php-cs-fixer'), $stub);
            chmod($project->path('vendor/bin/php-cs-fixer'), 0o755);
        }

        return $project;
    }

    public function testFixCommandRunsTheFixerAndThePollerReloadsTheBuffer(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x06"); // ctrl+alt+f → php-cs-fixer:fix
                },
                1400 => function ($tui, $terminal, $ide) use ($project): void {
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: fixed a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertStringContainsString('// fixed', (string) file_get_contents($project->path('a.php')), 'the stub rewrote the file');

                    // The filesystem poller reloads the clean buffer with the fixed content.
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->pollFilesystem();
                    $this->assertStringContainsString('// fixed', $tui->getById('editor')->getDocument()->getText(), 'buffer reloaded');
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }

    public function testFixOnSaveToggleRunsTheFixerAfterEverySave(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal, $ide) use ($project): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('php-cs-fixer:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['php-cs-fixer']['autoFormat'] ?? false), 'the toggle persisted to .porta.json');
                    $tui->handleInput("\x13"); // ctrl+s → save → the hook runs the fixer
                },
                1400 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertSame(1, substr_count((string) file_get_contents($project->path('a.php')), '// fixed'), 'save triggered one fix');

                    // Toggling off stops the hook.
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('php-cs-fixer:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer on save: off', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertFalse((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['php-cs-fixer']['autoFormat'] ?? true), 'the off state persisted');
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->pollFilesystem(); // absorb the on-disk change first
                    $tui->handleInput("\x13");
                },
                2600 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertSame(1, substr_count((string) file_get_contents($project->path('a.php')), '// fixed'), 'no fix while toggled off');
                },
            ],
            2.9,
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsFlashForMissingBinaryNonPhpFilesAndFailures(): void
    {
        // No stub binary anywhere (PATH is /nonexistent).
        $project = $this->project(stubExitCode: null);
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x06");
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer not found (vendor/bin or PATH)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.5,
        );

        // A non-PHP active file refuses politely.
        $project = $this->project();
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                200 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x06");
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: open a PHP file first', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            0.5,
        );

        // A failing fixer reports its exit code.
        $project = $this->project(stubExitCode: 3);
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => static function ($tui): void {
                    $tui->handleInput("\x1b\x06");
                },
                1400 => function ($tui, $terminal): void {
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: exited with code 3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
            1.7,
        );

        $this->addToAssertionCount(1);
    }
}
