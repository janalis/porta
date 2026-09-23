<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\ServerLocator;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

final class ServerLocatorTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;

    /** @var array<string, array{command: list<string>, name: string}> a phpantom plugin server, for the defaults layer */
    private const array PHPANTOM = ['php' => [['command' => ['/opt/phpantom_lsp'], 'name' => 'phpantom']]];

    public function testConfigStringCommandIsTheCatchAll(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'php phpactor.phar language-server'])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['*'], \array_keys($servers), 'a single command string serves every language');
        $this->assertSame(['php', 'phpactor.phar', 'language-server'], $servers['*'][0]['command']);
        $this->assertSame('phpactor', $servers['*'][0]['name'], 'interpreter token skipped, .phar stripped');
    }

    public function testConfigArgvListIsTheCatchAll(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['node', '/x/intelephense.js', '--stdio']])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['*'], \array_keys($servers));
        $this->assertSame(['node', '/x/intelephense.js', '--stdio'], $servers['*'][0]['command']);
        $this->assertSame('intelephense', $servers['*'][0]['name']);
    }

    public function testOverrideWinsOverEverything(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['php' => 'phpactor language-server']])]);

        $servers = ServerLocator::locateAll($project->dir, 'my-ls --stdio', self::PHPANTOM);

        $this->assertSame(['*'], \array_keys($servers), 'the --lsp override beats a map config and plugin servers');
        $this->assertSame(['my-ls', '--stdio'], $servers['*'][0]['command']);
    }

    public function testEmptyOverrideIsIgnored(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'phpactor language-server'])]);

        $servers = ServerLocator::locateAll($project->dir, '');

        $this->assertSame(['phpactor', 'language-server'], $servers['*'][0]['command'], 'empty override falls through to config');
    }

    public function testEnvVariableIsTheCatchAll(): void
    {
        $project = new TempProject();
        \putenv('PORTA_LSP=custom-ls --stdio');

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['*'], \array_keys($servers));
        $this->assertSame('custom-ls', $servers['*'][0]['name']);
    }

    public function testDisplayNameFallsBackWhenAllTokensAreInterpreters(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'php'])]);

        $this->assertSame('php', ServerLocator::locateAll($project->dir)['*'][0]['name']);
    }

    public function testDisplayNameRecognizesAnInterpreterRegardlessOfCase(): void
    {
        // "PHP" (as some shells / user configs write it) must still be skipped
        // as an interpreter token, not mistaken for the server binary itself.
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => 'PHP phpactor.phar language-server'])]);

        $this->assertSame('phpactor', ServerLocator::locateAll($project->dir)['*'][0]['name']);
    }

    public function testMapCommandValuesCastToStrings(): void
    {
        // A JSON argv list can carry non-string scalars (e.g. a bare port
        // number); every element must reach the server definition as a string.
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['typescript' => ['ls', 9229]]])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['ls', '9229'], $servers['typescript'][0]['command']);
    }

    public function testMapCommandValuesAreReindexedIntoAPlainList(): void
    {
        // A JSON object with numeric-but-gapped keys (e.g. {"0": "ls", "2": "x"})
        // decodes to a PHP array with a gap; the command must still come out
        // as a plain 0..n list, not carry that gap through.
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['typescript' => [0 => 'ls', 2 => '--stdio']]])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['ls', '--stdio'], $servers['typescript'][0]['command']);
    }

    public function testArgvListCommandValuesCastToStrings(): void
    {
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['ls', 9229]])]);

        $this->assertSame(['ls', '9229'], ServerLocator::locateAll($project->dir)['*'][0]['command']);
    }

    public function testMissingOrMalformedConfigDoesNotCrash(): void
    {
        // Held in variables: the inline `new TempProject(...)->dir` form frees
        // the fixture before locateAll runs, so both cases degenerated to the
        // missing-directory path and the malformed file was never parsed.
        \putenv('PATH=/nonexistent');
        $noLspKey = new TempProject(['.porta.json' => \json_encode(['keymap' => []])]);
        $broken = new TempProject(['.porta.json' => '{not json']);

        $this->assertSame([], ServerLocator::locateAll($noLspKey->dir));
        $this->assertSame([], ServerLocator::locateAll($broken->dir), 'a broken file degrades gracefully');
    }

    // --- The plugin-server (default) layer ------------------------------------

    public function testPluginServersAreTheDefaultLayer(): void
    {
        \putenv('PATH=/nonexistent'); // no core auto-detect
        $project = new TempProject();

        $servers = ServerLocator::locateAll($project->dir, null, self::PHPANTOM);

        $this->assertSame(['php'], \array_keys($servers), 'PHP now comes from a plugin, not a hardcoded default');
        $this->assertSame('phpantom', $servers['php'][0]['name']);
        $this->assertSame(['/opt/phpantom_lsp'], $servers['php'][0]['command']);
    }

    public function testConfigMapOverridesAPluginServer(): void
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['php' => 'phpactor language-server']])]);

        $servers = ServerLocator::locateAll($project->dir, null, self::PHPANTOM);

        $this->assertSame(['phpactor', 'language-server'], $servers['php'][0]['command'], 'the user config beats the plugin server');
        $this->assertSame('phpactor', $servers['php'][0]['name']);
    }

    public function testPluginInitializationOptionsSurviveTheDefaultsMergeButNotAnOverride(): void
    {
        \putenv('PATH=/nonexistent');
        $plugins = [
            'twig' => [['command' => ['/opt/sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]]],
            'yaml' => [['command' => ['/opt/sf-ls'], 'name' => 'sf-ls', 'initializationOptions' => ['workspaceTrust' => false]]],
        ];

        $plain = new TempProject();
        $servers = ServerLocator::locateAll($plain->dir, null, $plugins);
        $this->assertSame(['workspaceTrust' => false], $servers['twig'][0]['initializationOptions'] ?? null, 'the plugin entry passes through whole');

        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['twig' => 'my-twig-ls']])]);
        $overridden = ServerLocator::locateAll($project->dir, null, $plugins);
        $this->assertArrayNotHasKey('initializationOptions', $overridden['twig'][0], 'a config override replaces the entry, options included');
        $this->assertSame(['workspaceTrust' => false], $overridden['yaml'][0]['initializationOptions'] ?? null, 'the untouched language keeps its options');
    }

    public function testMapOverrideLeavesOtherLanguagesOnTheirDefaults(): void
    {
        // A map that only sets typescript leaves php on its plugin server.
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['.porta.json' => \json_encode(['lsp' => ['typescript' => 'my-tsls --stdio', 'python' => '', 'yaml' => ['ls', '--stdio']]])]);

        $servers = ServerLocator::locateAll($project->dir, null, self::PHPANTOM);

        $this->assertSame(['php', 'typescript', 'yaml'], \array_keys($servers));
        $this->assertSame('phpantom', $servers['php'][0]['name'], 'php keeps its plugin server');
        $this->assertSame(['my-tsls', '--stdio'], $servers['typescript'][0]['command'], 'the override applies');
        $this->assertSame(['ls', '--stdio'], $servers['yaml'][0]['command'], 'argv-list value form');
    }

    // --- Core auto-detect (JS/TS, Python — PHP is plugin-only now) ------------

    public function testPhpIsNoLongerAutoDetected(): void
    {
        $project = new TempProject();
        \chmod($project->write('vendor/bin/phpactor', "#!/bin/sh\n"), permissions: 0o755);
        $tools = new TempProject([], 'porta-bin-');
        \chmod($tools->write('phpactor', "#!/bin/sh\n"), permissions: 0o755);
        \putenv('PATH='.$tools->dir);

        $this->assertSame([], ServerLocator::locateAll($project->dir), 'no plugin server, no config → PHP is unserved');
        $this->assertArrayNotHasKey('php', ServerLocator::autoDetected($project->dir));
    }

    public function testAutoDetectPerLanguageServers(): void
    {
        $project = new TempProject();
        $tools = new TempProject([], 'porta-bin-');
        foreach (['typescript-language-server', 'pyright-langserver'] as $bin) {
            \chmod($tools->write($bin, "#!/bin/sh\n"), permissions: 0o755);
        }
        \putenv('PATH='.$tools->dir);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame('typescript-language-server', $servers['javascript'][0]['name']);
        $this->assertSame($servers['javascript'], $servers['typescript'], 'JS and TS share one server');
        $this->assertSame([$tools->path('typescript-language-server'), '--stdio'], $servers['javascript'][0]['command']);
        $this->assertSame('pyright', $servers['python'][0]['name']);
        $this->assertSame([$tools->path('pyright-langserver'), '--stdio'], $servers['python'][0]['command']);
        $this->assertArrayNotHasKey('php', $servers);
    }

    public function testPythonFallsBackToPylsp(): void
    {
        $project = new TempProject();
        $tools = new TempProject([], 'porta-bin-');
        \chmod($tools->write('pylsp', "#!/bin/sh\n"), permissions: 0o755);
        \putenv('PATH='.$tools->dir);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['python'], \array_keys($servers));
        $this->assertSame('pylsp', $servers['python'][0]['name']);
        $this->assertSame([$tools->path('pylsp')], $servers['python'][0]['command'], 'no --stdio arg for pylsp, unlike pyright');
    }

    public function testNothingInstalledAndNoPluginIsEmpty(): void
    {
        $project = new TempProject();
        \putenv('PATH=/nonexistent');

        $this->assertSame([], ServerLocator::locateAll($project->dir));
    }

    public function testDescribeParsesACommand(): void
    {
        $this->assertSame(['node', '/x/ls.js', '--stdio'], Probe::invokeStatic(ServerLocator::class, 'server', Probe::invokeStatic(ServerLocator::class, 'split', 'node /x/ls.js --stdio'))['command']);
        $this->assertSame('ls', Probe::invokeStatic(ServerLocator::class, 'server', Probe::invokeStatic(ServerLocator::class, 'split', 'node /x/ls.js --stdio'))['name']);
    }

    public function testSplitHonorsQuotedArgumentsWithSpaces(): void
    {
        // The tokenizer itself is Support\Argv (ArgvTest holds its cases);
        // this pins that the LSP seam actually routes through it.
        /** @var list<string> $parts */
        $parts = Probe::invokeStatic(ServerLocator::class, 'split', 'php "/path with spaces/lsp.php"');
        $this->assertSame(['php', '/path with spaces/lsp.php'], $parts);
    }
}
