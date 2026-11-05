<?php

declare(strict_types=1);

namespace Porta\Composer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Run\RunController;
use Porta\Terminal\TerminalSessions;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The plugin through a real IDE, with a stand-in composer
 * (`fixtures/composer.php`) that logs what it is asked and answers `outdated`
 * from a file.
 */
final class IdeComposerPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;
    use UsesBundledPlugins;

    private const string MANIFEST = <<<'JSON'
        {
            "name": "app/demo",
            "require": {
                "php": ">=8.5",
                "a/one": "^1.0"
            },
            "require-dev": {
                "c/three": "^1.0"
            }
        }

        JSON;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    private static function project(string $binary = ''): TempProject
    {
        $project = new TempProject([
            'composer.json' => self::MANIFEST,
            'bin/composer' => \sprintf("#!%s\n<?php\nrequire %s;\n", \PHP_BINARY, \var_export(__DIR__.'/fixtures/composer.php', return: true)),
            'outdated.json' => \json_encode(
                [
                    'installed' => [
                        ['name' => 'a/one', 'version' => '1.0.0', 'latest' => '1.1.0', 'latest-status' => 'semver-safe-update', 'abandoned' => false],
                        ['name' => 'c/three', 'version' => '1.2.0', 'latest' => '2.0.0', 'latest-status' => 'update-possible', 'abandoned' => false],
                        ['name' => 'd/gone', 'version' => '1.0.0', 'latest' => '1.0.0', 'latest-status' => 'up-to-date', 'abandoned' => 'e/new'],
                        ['name' => 'z/fine', 'version' => '1.0.0', 'latest' => '1.0.0', 'latest-status' => 'up-to-date', 'abandoned' => false],
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        \chmod($project->path('bin/composer'), permissions: 0o755);
        $project->write(
            '.porta.json',
            \json_encode(
                [
                    'enabledPlugins' => ['composer'],
                    'plugins' => ['composer' => ['binary' => '' === $binary ? $project->path('bin/composer') : $binary]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        return $project;
    }

    /** Run one of the plugin's commands, on a fresh output buffer so an await sees only what it caused. */
    private function command(Ide $ide, VirtualTerminal $terminal, string $id): void
    {
        $terminal->consumeOutput();
        Services::resolve($ide, CommandRegistry::class)->run('composer:'.$id);
    }

    private function screen(Tui $tui, VirtualTerminal $terminal): string
    {
        return \implode("\n", $this->screenGrid($tui, $terminal));
    }

    /** The Packages tab's check has come back: the plugin is free to run again. */
    private function awaitChecked(Tui $tui): void
    {
        $results = $tui->getById('results');
        $this->await(static fn (): bool => !Probe::get($results, 'searching'), what: 'the check to finish');
    }

    /** @return list<string> what the stand-in was asked, call by call */
    private static function calls(TempProject $project): array
    {
        $log = $project->path('composer.log');

        return \is_file($log) ? \explode("\n", \trim((string) \file_get_contents($log))) : [];
    }

    public function testOutdatedPackagesAreListedAndMarkedThenUpdatedAndRequired(): void
    {
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('composer.json'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->command($ide, $terminal, 'outdated');
                    $this->awaitPainted($tui, $terminal, '2 direct dependencies have');
                    $this->assertSame(['outdated --direct --format=json --no-plugins --no-interaction'], self::calls($project), 'its plugins off: asking runs nothing of the project\'s');
                    $screen = $this->screen($tui, $terminal);
                    $this->assertStringContainsString('a/one 1.0.0 ↑ 1.1.0', $screen, 'a row per package');
                    $this->assertStringContainsString('c/three 1.2.0 ⇑ 2.0.0 (past the constraint)', $screen);
                    $this->assertStringContainsString('d/gone 1.0.0 ⚠ abandoned, use e/new', $screen);
                    $this->assertStringNotContainsString('z/fine', $screen, 'what is current is left out');
                    $this->assertMatchesRegularExpression('~"a/one": "\^1\.0"\s+↑ 1\.1\.0~u', $screen, 'and a mark after the line requiring it');

                    // Away and back: the editor drops the marks with the file, and they come back with it.
                    $project->write('a.php', "<?php\n");
                    $buffers = Services::resolve($ide, BufferService::class);
                    $decorations = Widgets::editor($tui)->decorations;
                    $buffers->openFile($project->path('a.php'));
                    $this->assertSame([], $decorations->lineAnnotations, 'another file shows none');
                    $buffers->openFile($project->path('composer.json'));
                    $this->await(static fn (): bool => '↑ 1.1.0' === ($decorations->lineAnnotations[4] ?? null), what: 'composer.json\'s marks to come back');
                },
                150 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // An edit not yet saved: composer reads the file on disk, so it is saved first.
                    $tui->setFocus(Widgets::editor($tui));
                    Widgets::editor($tui)->jumpTo(4, 0);
                    $tui->handleInput(' ');
                    $this->command($ide, $terminal, 'update_at_caret');
                    $this->awaitPainted($tui, $terminal, 'composer update finished');
                    $this->assertStringStartsWith("{\n    \"name\"", (string) \file_get_contents($project->path('composer.json')));
                    $this->assertStringContainsString("\n         \"a/one\"", (string) \file_get_contents($project->path('composer.json')), 'the edit was saved first');
                    $this->await(static fn (): bool => 3 === \count(self::calls($project)), what: 'the list to be asked for again');
                    $this->awaitChecked($tui);
                    $this->assertSame('update a/one', self::calls($project)[1]);
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Widgets::editor($tui)->jumpTo(1, 0);
                    $this->command($ide, $terminal, 'remove_at_caret');
                    $this->awaitPainted($tui, $terminal, 'Put the caret on a package');
                },
                250 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Asked from the dock: the question takes the keys from it.
                    $tui->setFocus($tui->getById('results'));
                    $this->command($ide, $terminal, 'require');
                    $this->awaitPainted($tui, $terminal, 'Require (vendor/name');
                    $tui->handleInput('b/two   --dev ');
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'composer require finished');
                    $this->assertContains('require b/two --dev', self::calls($project));
                    // composer rewrote the file: the buffer follows it, and the marks their lines.
                    $this->await(static fn (): bool => 5 === \count(self::calls($project)), what: 'the list to be asked for again');
                    $this->awaitChecked($tui);
                    $terminal->consumeOutput();
                    $ide->pollFilesystem();
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->await(static fn (): bool => '↑ 1.1.0' === ($decorations->lineAnnotations[5] ?? null), what: 'a/one, a line lower, to be marked there');
                },
                280 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Changed outside the editor, with no run to ask again: the marks follow the reload.
                    $project->write('composer.json', \str_replace(
                        "\"require\": {\n",
                        replace: "\"require\": {\n        \"x/new\": \"*\",\n",
                        subject: (string) \file_get_contents($project->path('composer.json')),
                    ));
                    $ide->pollFilesystem();
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->await(static fn (): bool => '↑ 1.1.0' === ($decorations->lineAnnotations[6] ?? null), what: 'the reloaded file\'s marks to move');
                    $this->assertArrayNotHasKey(5, $decorations->lineAnnotations);
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Nothing typed: nothing run.
                    $calls = \count(self::calls($project));
                    $this->command($ide, $terminal, 'require');
                    $this->awaitPainted($tui, $terminal, 'Require (vendor/name');
                    $tui->handleInput("  \r");
                    Widgets::editor($tui)->jumpTo(9, 0); // c/three, two lines lower for the added ones
                    $this->command($ide, $terminal, 'remove_at_caret');
                    $this->awaitPainted($tui, $terminal, 'composer remove finished');
                    $this->assertSame('remove c/three', self::calls($project)[$calls]);
                },
            ],
        );
    }

    public function testFailuresSaySoAndAFailedRunAsksNothingMore(): void
    {
        $project = self::project();

        $this->runIde(
            $project,
            $project->path('composer.json'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->command($ide, $terminal, 'install');
                    $this->awaitPainted($tui, $terminal, 'composer install finished');
                    $this->assertSame(['install'], self::calls($project), 'with no list asked for, none is asked for after');

                    $this->command($ide, $terminal, 'outdated');
                    $this->awaitPainted($tui, $terminal, '2 direct dependencies have');
                    $this->awaitChecked($tui);

                    // The list was asked for, but the run failed: it changed nothing to ask about.
                    $project->write('exit-code', '3');
                    $this->command($ide, $terminal, 'update');
                    $this->awaitPainted($tui, $terminal, 'exited with code 3');
                    $this->assertFalse(Probe::get($tui->getById('results'), 'searching'), 'no check after a failed run');
                    $this->assertSame('update', \array_last(self::calls($project)));

                    \unlink($project->path('outdated.json'));
                    $this->command($ide, $terminal, 'outdated');
                    $this->awaitPainted($tui, $terminal, 'composer outdated exited with code 2');
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $project->write('outdated.json', '{"installed": []}');
                    $this->command($ide, $terminal, 'outdated');
                    $this->awaitPainted($tui, $terminal, 'Every direct dependency is on its');

                    // Another composer.json — a library's inside the project — is not the project's.
                    $project->write('lib/composer.json', self::MANIFEST);
                    $terminal->consumeOutput();
                    Services::resolve($ide, BufferService::class)->openFile($project->path('lib/composer.json'));
                    Widgets::editor($tui)->jumpTo(4, 0);
                    $this->command($ide, $terminal, 'update_at_caret');
                    $this->awaitPainted($tui, $terminal, 'Put the caret on a package');
                },
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Stopped before it finished, a run says so.
                    $project->write('sleep', '');
                    $this->command($ide, $terminal, 'install');
                    $runs = Services::resolve($ide, RunController::class);
                    $sessions = Probe::get($runs, 'sessions');
                    $this->assertInstanceOf(TerminalSessions::class, $sessions);
                    $runs->closeSession((int) \array_search($sessions->find('run:Composer'), $sessions->sessions, strict: true));
                    $this->awaitPainted($tui, $terminal, 'composer install was stopped');
                    \unlink($project->path('sleep'));
                },
                350 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // Edited in the editor AND changed on disk: which wins is the user's to say, so nothing runs.
                    $buffers = Services::resolve($ide, BufferService::class);
                    $buffers->openFile($project->path('composer.json'));
                    $tui->setFocus(Widgets::editor($tui));
                    $tui->handleInput(' ');
                    $project->write('composer.json', self::MANIFEST."\n\n");
                    $ide->pollFilesystem();
                    $calls = self::calls($project);
                    $this->command($ide, $terminal, 'install');
                    $this->awaitPainted($tui, $terminal, 'it changed on disk');
                    $this->assertSame($calls, self::calls($project), 'composer was not run');
                },
            ],
        );
    }

    public function testABinaryThatCannotStartSaysSo(): void
    {
        $project = self::project();
        $project->write(
            '.porta.json',
            \json_encode(
                [
                    'enabledPlugins' => ['composer'],
                    'plugins' => ['composer' => ['binary' => $project->path('composer.json')]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        $this->runIde(
            $project,
            $project->path('composer.json'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->command($ide, $terminal, 'outdated');
                    $this->awaitPainted($tui, $terminal, 'composer could not be started');
                },
            ],
        );
    }
}
