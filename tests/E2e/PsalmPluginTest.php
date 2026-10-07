<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\IdeConfig;
use Porta\Ide;
use Porta\Plugin\Runtime\PluginHost;
use Porta\Settings\SettingsRowKind;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The shipped psalm plugin (plugins/psalm/plugin.php) against stub
 * analyser binaries — the real plugin file is loaded from the repo. Issues
 * surface as editor diagnostics and in the Problems panel; a clean run clears.
 */
final class PsalmPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /** A project with the real plugin file and (optionally) a stub psalm. */
    private function project(?string $stubBody = null): TempProject
    {
        $plugin = (string) \file_get_contents(__DIR__.'/../../plugins/psalm/plugin.php');
        $project = new TempProject([
            '.porta/plugins/psalm/plugin.php' => $plugin,
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
        if (!\is_dir($project->path('vendor/bin'))) {
            \mkdir($project->path('vendor/bin'), permissions: 0o777, recursive: true);
        }
        \file_put_contents($project->path('vendor/bin/psalm'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$body);
        \chmod($project->path('vendor/bin/psalm'), permissions: 0o755);
    }

    /** A stub reporting one error on 1-based line 2 of the analysed file, exit 2. */
    private function errorStub(): string
    {
        return <<<'PHP'
            echo json_encode([[
                'severity' => 'error',
                'type' => 'UndefinedVariable',
                'message' => 'Boom detected',
                'line_from' => 2,
                'column_from' => 1,
            ]]);
            exit(2);
            PHP;
    }

    public function testIssuesBecomeDiagnosticsAndACleanRunClearsThem(): void
    {
        $project = $this->project($this->errorStub());
        // The message after the code is what this reads; PhpStorm's default shows none.
        $project->write('.porta.json', (string) \json_encode(['settings' => ['diagnosticMessages' => 'inline']]));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x10"); // ctrl+alt+p → psalm:analyse
                },
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($project): void {
                    // TWO paints, not one: the status line reports the run, and
                    // the inline diagnostic lands in the editor separately. Await
                    // the LAST thing asserted, not the first.
                    $this->awaitPainted($tui, $terminal, 'psalm: 1 issue in a.php');
                    // Inline: the SENTENCE, with nothing in front of it. The
                    // rule's name used to be glued to the message by the plugin
                    // because there was nowhere else to put it.
                    $this->awaitPainted($tui, $terminal, 'Boom detected');
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringNotContainsString('UndefinedVariable: Boom detected', $screen, 'the rule is not part of the message');

                    // The Problems panel aggregates it like any LSP diagnostic
                    // — and this is where the rule's name belongs, at the END
                    // of the row: what you search for, not what you read past.
                    $tui->handleInput("\x1b6"); // alt+6
                    $this->awaitPainted($tui, $terminal, 'Boom detected  UndefinedVariable');
                    $this->assertStringContainsString('✗ Boom detected  UndefinedVariable', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $tui->handleInput("\x1b"); // close the panel

                    // Swap in a clean stub and re-analyse: diagnostics clear.
                    $this->installStub($project, 'echo json_encode([]); exit(0);');
                    $tui->handleInput("\x1b\x10");
                },
                450 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm: no issues in a.php'), what: 'psalm to report the clean run');
                    $this->assertStringContainsString('psalm: no issues in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $editorScreen = \implode("\n", Paint::lines($tui->getById('editor'), new RenderContext(120, 20)));
                    $this->assertStringNotContainsString('Boom detected', AnsiUtils::stripAnsiCodes($editorScreen), 'the clean run cleared the diagnostic');
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
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    Services::resolve($ide, CommandRegistry::class)->run('psalm:toggle-on-save');
                    $this->awaitPainted($tui, $terminal, 'psalm on save: on');
                    $this->assertStringContainsString('psalm on save: on', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                    $this->assertTrue((bool) (IdeConfig::load($project->dir)->plugins['psalm']['autoLint'] ?? false), 'the toggle persisted to .porta.json');

                    // The persisted setting shows on the plugin's settings page.
                    $rows = Services::resolve($ide, PluginHost::class)->settings
                        ->rows('psalm');
                    $byKey = \array_column($rows, null, 'key');
                    $this->assertArrayHasKey('pluginSetting:autoLint', $byKey, 'the on-save option is a settings row');
                    $this->assertSame('Analyse on save', $byKey['pluginSetting:autoLint']->label);
                    $this->assertSame(SettingsRowKind::Bool, $byKey['pluginSetting:autoLint']->kind);

                    $tui->handleInput("\x13"); // ctrl+s → save → analyse
                },
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm: 1 issue in a.php'), what: 'psalm to report the issue');
                    $this->assertStringContainsString('psalm: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'saving analysed the file');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testPersistedAutoLintAnalysesOnSaveAcrossRestarts(): void
    {
        // A .porta.json seeded with autoLint=true behaves as if the toggle had
        // been flipped in an earlier session: the first save analyses, no toggle needed.
        $project = $this->project($this->errorStub());
        $project->write('.porta.json', (string) \json_encode(['plugins' => ['psalm' => ['autoLint' => true]]]));

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x13"); // ctrl+s → save → analyse (no toggle this session)
                },
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm: 1 issue in a.php'), what: 'psalm to report the issue');
                    $this->assertStringContainsString('psalm: 1 issue in a.php', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the persisted setting survived the restart');
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
                    $tui->handleInput("\x1b\x10");
                    // Await the painted flash: a tick() is not a frame (the
                    // adaptive ticker), so asserting straight after one races
                    // the render under load.
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm not found (vendor/bin or PATH)'), what: 'the missing-binary flash');
                },
            ],
        );

        $project = $this->project($this->errorStub());
        $this->runIde(
            $project,
            $project->path('notes.txt'),
            [
                100 => function ($tui, $terminal): void {
                    $tui->handleInput("\x1b\x10");
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm: open a PHP file first'), what: 'the wrong-file flash');
                },
            ],
        );

        // An internal psalm failure (no JSON, e.g. missing psalm.xml) reports the code.
        $project = $this->project("fwrite(STDERR, 'Could not locate a config XML file'); exit(1);");
        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x1b\x10");
                },
                300 => function ($tui, $terminal): void {
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'psalm: exited with code 1'), what: 'the internal-failure flash');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
