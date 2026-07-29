<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The shipped phpstan plugin (.porta/plugins/phpstan/plugin.php) against stub
 * analyser binaries — the real plugin file is loaded from the repo. Errors
 * surface as editor diagnostics and in the Problems panel; a clean run clears.
 */
final class PhpstanPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent');
    }

    /** A project with the real plugin file and (optionally) a stub phpstan. */
    private function project(?string $stubBody = null): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/phpstan/plugin.php');
        $project = new TempProject([
            '.porta/plugins/phpstan/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x = 1;\n\$y = 2;\n",
            'notes.txt' => "plain\n",
        ]);

        if (null !== $stubBody) {
            $this->installStub($project, $stubBody);
        }

        return $project;
    }

    private function installStub(TempProject $project, string $body): void
    {
        // PATH is /nonexistent, so the shebang must point at the php binary directly.
        if (!is_dir($project->path('vendor/bin'))) {
            mkdir($project->path('vendor/bin'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('vendor/bin/phpstan'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body);
        chmod($project->path('vendor/bin/phpstan'), permissions: 0o755);
    }

    /** A stub reporting one error on 1-based line 2 of the analysed file, exit 1. */
    private function errorStub(): string
    {
        return <<<'PHP'
            $file = $argv[count($argv) - 1];
            echo json_encode(['files' => [$file => ['messages' => [
                ['message' => 'Boom detected', 'line' => 2, 'ignorable' => true],
            ]]], 'errors' => []]);
            exit(1);
            PHP;
    }

    public function testErrorsBecomeDiagnosticsAndACleanRunClearsThem(): void
    {
        $project = $this->project($this->errorStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x01"); // ctrl+alt+a → phpstan:analyse
                },
                300 => function ($tui, $terminal) use ($project): void {
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => str_contains($screen(), 'phpstan: 1 error in a.php'), what: 'the analyse toast');
                    // The toast and the inline diagnostic land on different
                    // invalidation paths — await the diagnostic itself.
                    $editor = $tui->getById('editor');
                    $rendered = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $editor->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));
                    $this->await(static fn (): bool => str_contains($rendered(), 'Boom detected'), what: 'the diagnostic to render inline in the editor');

                    // The Problems panel aggregates it like any LSP diagnostic.
                    $tui->handleInput("\x1b6"); // alt+6
                    $this->await(static fn (): bool => str_contains($screen(), '✗ Boom detected'), what: 'the Problems row');
                    $tui->handleInput("\x1b"); // close the panel

                    // Swap in a clean stub and re-analyse: diagnostics clear.
                    $this->installStub($project, "echo json_encode(['files' => [], 'errors' => []]); exit(0);");
                    $tui->handleInput("\x1b\x01");
                    $this->await(static fn (): bool => str_contains($screen(), 'phpstan: no errors in a.php'), what: 'the clean-run toast');
                    // A negative on a FRESH render: await the clearing itself.
                    $this->await(static fn (): bool => !str_contains($rendered(), 'Boom detected'), what: 'the clean run to clear the inline diagnostic');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAnalyseOnSaveToggle(): void
    {
        $project = $this->project($this->errorStub());

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal, $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('phpstan:toggle-on-save');
                    $tui->tick();
                    $this->assertStringContainsString('phpstan on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (\Porta\Config\IdeConfig::load($project->dir)->plugins['phpstan']['autoLint'] ?? false), 'the toggle persisted to .porta.json');
                    $tui->handleInput("\x13"); // ctrl+s → save → analyse
                },
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpstan: 1 error in a.php'));
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: 1 error in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'saving analysed the file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testGuardsForMissingBinaryNonPhpFilesAndInternalFailures(): void
    {
        $project = $this->project(); // no stub, PATH is empty
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x01");
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpstan not found (vendor/bin or PATH)'), what: 'the missing-binary flash');
                },
            ],
        );

        $project = $this->project($this->errorStub());
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x01");
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpstan: open a PHP file first'), what: 'the non-PHP guard flash');
                },
            ],
        );

        // An internal phpstan failure (no JSON, exit 255) reports the code.
        $project = $this->project("fwrite(STDOUT, 'fatal'); exit(255);");
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x01");
                },
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'phpstan: exited with code 255'));
                    $tui->tick();
                    $this->assertStringContainsString('phpstan: exited with code 255', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
