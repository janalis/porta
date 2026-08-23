<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Run;

use PHPUnit\Framework\TestCase;
use Porta\Run\RunConfigs;
use Porta\Tests\Support\TempProject;

final class RunConfigsTest extends TestCase
{
    public function testComposerScripts(): void
    {
        $project = new TempProject(['composer.json' => json_encode([
            'name' => 'acme/x',
            'scripts' => ['test' => 'phpunit', 'cs' => ['step-a', 'step-b'], 'coverage' => 'phpunit --coverage'],
        ])]);

        $this->assertSame(['test', 'cs', 'coverage'], new RunConfigs($project->dir)->composerScripts());
    }

    public function testComposerScriptsWhenAbsentOrScriptless(): void
    {
        $this->assertSame([], new RunConfigs(new TempProject()->dir)->composerScripts());

        $noScripts = new TempProject(['composer.json' => json_encode(['name' => 'x'])]);
        $this->assertSame([], new RunConfigs($noScripts->dir)->composerScripts());

        $corrupt = new TempProject(['composer.json' => '{not json']);
        $this->assertSame([], new RunConfigs($corrupt->dir)->composerScripts());
    }

    public function testMakeTargets(): void
    {
        $makefile = <<<'MAKE'
            VAR := value
            .PHONY: build test

            build: deps
            	echo building

            test:
            	phpunit

            build:
            	echo duplicate ignored

            %.o: %.c
            	compile
            MAKE;
        $project = new TempProject(['Makefile' => $makefile]);

        $targets = new RunConfigs($project->dir)->makeTargets();
        $this->assertSame(['build', 'test'], $targets, 'variables, duplicates and pattern rules excluded');
    }

    public function testMakeTargetsWhenAbsent(): void
    {
        $this->assertSame([], new RunConfigs(new TempProject()->dir)->makeTargets());
    }

    public function testDiscoverCombinesEverythingWithCurrentFileFirst(): void
    {
        $project = new TempProject([
            'composer.json' => json_encode(['scripts' => ['test' => 'phpunit']]),
            'Makefile' => "build:\n\techo\n",
        ]);
        $current = $project->write('src/Foo.php', "<?php\n");

        $tasks = new RunConfigs($project->dir)->discover($current);
        $labels = array_column($tasks, 'label');

        $this->assertSame(
            [
                'Run current file: Foo.php',
                'composer test',
                'make build',
            ],
            $labels,
        );
        $this->assertSame([\PHP_BINARY, $current], $tasks[0]['command']);
        $this->assertSame(['composer', 'run-script', 'test'], $tasks[1]['command']);
        $this->assertSame(['make', 'build'], $tasks[2]['command']);
    }

    public function testDiscoverSkipsNonPhpCurrentFileAndCanBeEmpty(): void
    {
        $project = new TempProject([]);
        $this->assertSame([], new RunConfigs($project->dir)->discover($project->path('notes.txt')));
        $this->assertSame([], new RunConfigs($project->dir)->discover(null));
    }

    public function testRunCurrentFileUsesTheConfiguredPhpRunCommand(): void
    {
        $project = new TempProject();
        $current = $project->write('src/Foo.php', "<?php\n");

        $tasks = new RunConfigs($project->dir, ['php' => ['runCommand' => 'frankenphp php-cli']])->discover($current);
        $this->assertSame(['frankenphp', 'php-cli', $current], $tasks[0]['command'], 'the run command becomes the interpreter argv prefix');

        // Whitespace-only / absent falls back to the bundled interpreter.
        $blank = new RunConfigs($project->dir, ['php' => ['runCommand' => '   ']])->discover($current);
        $this->assertSame([\PHP_BINARY, $current], $blank[0]['command']);
    }

    public function testAFormFeedOnlyRunCommandFallsBackToTheBundledInterpreter(): void
    {
        // \x0C (form feed) is not in trim()'s default charlist but IS
        // matched by Argv::split()'s \s-based tokenizer — an obscure input
        // where the two disagree about "blank."
        $project = new TempProject();
        $current = $project->write('src/Foo.php', "<?php\n");

        $tasks = new RunConfigs($project->dir, ['php' => ['runCommand' => "\x0C"]])->discover($current);
        $this->assertSame([\PHP_BINARY, $current], $tasks[0]['command']);
    }

    public function testEmptyStringScriptNameIsFiltered(): void
    {
        $project = new TempProject(['composer.json' => (string) json_encode(['scripts' => ['' => 'do-nothing', 'test' => 'phpunit']])]);
        $this->assertSame(['test'], new RunConfigs($project->dir)->composerScripts());
    }

    public function testFilteredEmptyKeyLeavesTheRemainingNamesContiguouslyIndexed(): void
    {
        // The empty key sorts BEFORE the valid one, so filtering it out
        // leaves a gap unless the result is reindexed.
        $project = new TempProject(['composer.json' => (string) json_encode(['scripts' => ['' => 'x', 'test' => 'phpunit']])]);
        $names = new RunConfigs($project->dir)->composerScripts();
        $this->assertSame(['test'], $names);
        $this->assertSame('["test"]', json_encode($names), 'stays a JSON array, not an object');
    }

    public function testAPurelyNumericScriptNameDoesNotCrashDiscovery(): void
    {
        // JSON decodes a numeric string key ("0") back to an int array key
        // — composerScripts() must still hand the filter closure a real
        // string, or a strict-typed parameter throws under strict_types.
        $project = new TempProject(['composer.json' => (string) json_encode(['scripts' => ['0' => 'legacy', 'test' => 'phpunit']])]);
        $this->assertSame(['0', 'test'], new RunConfigs($project->dir)->composerScripts());
    }

    public function testAQuotedInterpreterPathWithSpacesSurvivesTokenization(): void
    {
        // The shared Argv tokenizer: the same space-in-path bug fixed for the
        // LSP seam must not live on in the run seam.
        $project = new TempProject();
        $current = $project->write('src/Foo.php', "<?php\n");

        $tasks = new RunConfigs($project->dir, ['php' => ['runCommand' => '"/opt/my php/bin/php" -d memory_limit=1G']])->discover($current);

        $this->assertSame(['/opt/my php/bin/php', '-d', 'memory_limit=1G', $current], $tasks[0]['command']);
    }
}
