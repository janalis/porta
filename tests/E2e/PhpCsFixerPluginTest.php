<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
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
    use RunsIde;

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
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x06"); // ctrl+alt+f → php-cs-fixer:fix
                },
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer: fixed a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: fixed a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->await(fn (): bool => str_contains((string) @file_get_contents($project->path('a.php')), '// fixed'));
                    $this->assertStringContainsString('// fixed', (string) file_get_contents($project->path('a.php')), 'the stub rewrote the file');

                    // The filesystem poller reloads the clean buffer with the fixed content.
                    new \ReflectionProperty($ide, 'bufferService')
                        ->getValue($ide)
                        ->pollFilesystem();
                    $this->assertStringContainsString('// fixed', $tui->getById('editor')->getDocument()->getText(), 'buffer reloaded');
                },
            ],
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
                100 => function ($tui, $terminal, $ide) use ($project): void {
                    new \ReflectionProperty($ide, 'commands')->getValue($ide)->run('php-cs-fixer:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['php-cs-fixer']['autoFormat'] ?? false), 'the toggle persisted to .porta.json');
                    $tui->handleInput("\x13"); // ctrl+s → save → the hook runs the fixer
                },
                300 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->await(fn (): bool => substr_count((string) @file_get_contents($project->path('a.php')), '// fixed') >= 1);
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
                450 => function ($tui, $terminal, $ide) use ($project): void {
                    $this->assertSame(1, substr_count((string) file_get_contents($project->path('a.php')), '// fixed'), 'no fix while toggled off');
                },
            ],
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
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x06");
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer not found (vendor/bin or PATH)', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // A non-PHP active file refuses politely.
        $project = $this->project();
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x06");
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: open a PHP file first', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        // A failing fixer reports its exit code.
        $project = $this->project(stubExitCode: 3);
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x06");
                },
                300 => function ($tui, $terminal): void {
                    $this->await(fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer: exited with code 3'));
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: exited with code 3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
