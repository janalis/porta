<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The shipped php-cs-fixer plugin (plugins/php-cs-fixer/plugin.php),
 * exercised against stub fixer binaries — the real plugin file is loaded from
 * the repo so the test keeps it honest.
 */
final class PhpCsFixerPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /** A project with the real plugin file and (optionally) a stub fixer binary. */
    private function project(?int $stubExitCode = 0): TempProject
    {
        $plugin = (string) \file_get_contents(__DIR__.'/../../plugins/php-cs-fixer/plugin.php');
        $project = new TempProject([
            '.porta/plugins/php-cs-fixer/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x=1;\n",
            'notes.txt' => "plain\n",
        ]);

        if (null !== $stubExitCode) {
            // PATH is /nonexistent, so the shebang must point at the php binary directly.
            $stub = '#!'.\PHP_BINARY.' -n'."\n<?php\nfile_put_contents(\$argv[2], file_get_contents(\$argv[2]).\"// fixed\\n\");\n"."exit({$stubExitCode});\n";
            \mkdir($project->path('vendor/bin'), permissions: 0o777, recursive: true);
            \file_put_contents($project->path('vendor/bin/php-cs-fixer'), $stub);
            \chmod($project->path('vendor/bin/php-cs-fixer'), permissions: 0o755);
        }

        return $project;
    }

    public function testFixCommandRunsTheFixerAndTheBufferUpdatesItself(): void
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
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer: fixed a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('php-cs-fixer: fixed a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->await(static fn (): bool => \str_contains((string) (\is_file($project->path('a.php')) ? \file_get_contents($project->path('a.php')) : false), '// fixed'));
                    $this->assertStringContainsString('// fixed', (string) \file_get_contents($project->path('a.php')), 'the stub rewrote the file');

                    // No pollFilesystem() here on purpose: the plugin applies
                    // the result into the buffer when the tool exits, so the
                    // edit is there without waiting a poll tick — and it is one
                    // Ctrl+Z away, which a poller reload never was.
                    $this->await(static fn (): bool => \str_contains($tui->getById('editor')->getDocument()->getText(), '// fixed'));
                    $this->assertStringContainsString('// fixed', $tui->getById('editor')->getDocument()->getText(), 'the buffer updated itself');
                    $this->assertFalse($tui->getById('editor')->getDocument()->isDirty(), 'and it matches disk, so it is not modified');

                    $tui->getById('editor')->handleInput("\x1a"); // ctrl+z
                    $this->assertStringNotContainsString('// fixed', $tui->getById('editor')->getDocument()->getText(), 'the format is undoable');
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
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('php-cs-fixer:toggle-on-save');
                    $this->awaitPainted($tui, $terminal, 'php-cs-fixer on save: on');
                    $this->assertStringContainsString('php-cs-fixer on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (IdeConfig::load($project->dir)->plugins['php-cs-fixer']['autoFormat'] ?? false), 'the toggle persisted to .porta.json');
                    $tui->handleInput("\x13"); // ctrl+s → save → the hook runs the fixer
                },
                300 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    $this->await(
                        static fn (): bool => \substr_count((string) (\is_file($project->path('a.php')) ? \file_get_contents($project->path('a.php')) : false), needle: '// fixed') >= 1,
                        what: 'the on-save fixer to run',
                    );
                    $this->assertSame(1, \substr_count((string) \file_get_contents($project->path('a.php')), needle: '// fixed'), 'save triggered one fix');

                    // Toggling off stops the hook.
                    Services::resolve($ide, CommandRegistry::class)->run('php-cs-fixer:toggle-on-save');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer on save: off'), what: 'the toggle-off flash');
                    $this->assertFalse((bool) (IdeConfig::load($project->dir)->plugins['php-cs-fixer']['autoFormat'] ?? true), 'the off state persisted');
                    // Await the RELOAD LANDING IN THE BUFFER, not just the poll
                    // call: saving an un-reloaded buffer would write back the
                    // unfixed text and zero the count below.
                    Services::resolve($ide, BufferService::class)->pollFilesystem();
                    $editor = $tui->getById('editor');
                    $this->await(static fn (): bool => \str_contains($editor->getDocument()->getText(), '// fixed'), what: 'the fixed file to reload into the buffer');
                    $tui->handleInput("\x13");
                    // A negative ("no second fix ran") has nothing to await:
                    // give the would-be fixer a real window, then assert.
                    $this->pump(0.3);
                    $this->assertSame(1, \substr_count((string) \file_get_contents($project->path('a.php')), needle: '// fixed'), 'no fix while toggled off');
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
                100 => function ($tui, $terminal, Ide $ide): void {
                    $tui->handleInput("\x1b\x06");
                    $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'php-cs-fixer not found (vendor/bin or PATH)'), what: 'the missing-binary toast');
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
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer: open a PHP file first'), what: 'the non-PHP guard flash');
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
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'php-cs-fixer: exited with code 3'));
                    $this->assertStringContainsString('php-cs-fixer: exited with code 3', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTypingWhileTheFixerRunsKeepsTheKeystrokes(): void
    {
        // The version guard. The subprocess read the file a moment ago; if the
        // user has typed since, applying its output would silently discard
        // their edit. Core's format-on-save makes the same check with a
        // document version, which a plugin cannot see.
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x06"); // ctrl+alt+f
                    $tui->getById('editor')->getDocument()->insert('X'); // typed while it runs
                },
                400 => function ($tui, $terminal) use ($project): void {
                    $this->await(static fn (): bool => \str_contains((string) (\is_file($project->path('a.php')) ? \file_get_contents($project->path('a.php')) : false), '// fixed'));
                    $tui->tick();

                    $text = $tui->getById('editor')->getDocument()->getText();
                    $this->assertStringContainsString('X', $text, 'the keystroke survived');
                    $this->assertStringNotContainsString('// fixed', $text, 'and the stale output was not applied over it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
