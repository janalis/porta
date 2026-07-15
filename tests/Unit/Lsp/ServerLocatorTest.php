<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Lsp\ServerLocator;
use Porta\Tests\Support\TempProject;

final class ServerLocatorTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PORTA_LSP');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        putenv('PORTA_LSP');
    }

    public function testConfigStringCommand(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'php phpactor.phar language-server'])]);

        $server = ServerLocator::locate($project->dir);

        $this->assertSame(['php', 'phpactor.phar', 'language-server'], $server['command']);
        $this->assertSame('phpactor', $server['name'], 'interpreter token skipped, .phar stripped');
    }

    public function testExplicitOverrideWinsOverConfig(): void
    {
        // Even with a .porta.json lsp set, an explicit override takes precedence.
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);

        $server = ServerLocator::locate($project->dir, 'my-ls --stdio');

        $this->assertSame(['my-ls', '--stdio'], $server['command']);
        $this->assertSame('my-ls', $server['name']);
    }

    public function testEmptyOverrideFallsThrough(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);

        $server = ServerLocator::locate($project->dir, '');

        $this->assertSame(['phpactor', 'language-server'], $server['command'], 'empty override is ignored');
    }

    public function testConfigArrayCommand(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['node', '/x/intelephense.js', '--stdio']])]);

        $server = ServerLocator::locate($project->dir);

        $this->assertSame(['node', '/x/intelephense.js', '--stdio'], $server['command']);
        $this->assertSame('intelephense', $server['name']);
    }

    public function testConfigWithoutLspFallsThrough(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['keymap' => []])]);
        putenv('PATH=/nonexistent');

        $this->assertNull(ServerLocator::locate($project->dir));
    }

    public function testMalformedConfigDoesNotCrash(): void
    {
        // A broken .porta.json must degrade gracefully, not throw.
        $project = new TempProject(['.porta.json' => '{not json']);
        putenv('PATH=/nonexistent');

        $this->assertNull(ServerLocator::locate($project->dir));
    }

    public function testEnvVariable(): void
    {
        $project = new TempProject();
        putenv('PORTA_LSP=custom-ls --stdio');

        $server = ServerLocator::locate($project->dir);

        $this->assertSame(['custom-ls', '--stdio'], $server['command']);
        $this->assertSame('custom-ls', $server['name']);
    }

    public function testVendorBinPhpactor(): void
    {
        $project = new TempProject();
        $bin = $project->write('vendor/bin/phpactor', "#!/bin/sh\necho hi\n");
        chmod($bin, permissions: 0o755);
        putenv('PATH=/nonexistent');

        $server = ServerLocator::locate($project->dir);

        $this->assertSame([$bin, 'language-server'], $server['command']);
        $this->assertSame('phpactor', $server['name']);
    }

    public function testPathLookupPhpactorThenIntelephense(): void
    {
        $project = new TempProject();
        $tools = new TempProject([], 'porta-bin-');

        $phpactor = $tools->write('phpactor', "#!/bin/sh\n");
        chmod($phpactor, permissions: 0o755);
        putenv('PATH='.$tools->dir);
        $this->assertSame('phpactor', ServerLocator::locate($project->dir)['name']);

        unlink($phpactor);
        $intelephense = $tools->write('intelephense', "#!/bin/sh\n");
        chmod($intelephense, permissions: 0o755);
        $server = ServerLocator::locate($project->dir);
        $this->assertSame([$intelephense, '--stdio'], $server['command']);
        $this->assertSame('intelephense', $server['name']);
    }

    public function testDisplayNameFallsBackWhenAllTokensAreInterpreters(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'php'])]);

        $this->assertSame('php', ServerLocator::locate($project->dir)['name']);
    }

    public function testNothingFound(): void
    {
        $project = new TempProject();
        putenv('PATH=/nonexistent');

        $this->assertNull(ServerLocator::locate($project->dir));
    }

    // --- locateAll ------------------------------------------------------------

    public function testLocateAllStringConfigIsTheCatchAll(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => 'phpactor language-server'])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['*'], array_keys($servers), 'a single command string serves every language');
        $this->assertSame(['phpactor', 'language-server'], $servers['*']['command']);
    }

    public function testLocateAllOverrideIsTheCatchAll(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['php' => 'phpactor language-server']])]);

        $servers = ServerLocator::locateAll($project->dir, 'my-ls --stdio');

        $this->assertSame(['*'], array_keys($servers), 'the --lsp override beats even a map config');
        $this->assertSame(['my-ls', '--stdio'], $servers['*']['command']);
    }

    public function testLocateAllMapConfigRoutesPerLanguage(): void
    {
        putenv('PATH=/nonexistent'); // no auto-detected servers to merge in
        $project = new TempProject(['.porta.json' => json_encode([
            'lsp' => [
                'php' => 'phpactor language-server',
                'typescript' => ['node', '/x/tsserver.js', '--stdio'],
                'python' => '', // empty command: dropped → auto-detect (none here)
                'yaml' => ['ls', '--stdio'], // argv-list value form
            ],
        ])]);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['php', 'typescript', 'yaml'], array_keys($servers));
        $this->assertSame(['phpactor', 'language-server'], $servers['php']['command']);
        $this->assertSame(['node', '/x/tsserver.js', '--stdio'], $servers['typescript']['command']);
        $this->assertSame('tsserver', $servers['typescript']['name']);
        $this->assertSame(['ls', '--stdio'], $servers['yaml']['command']);
    }

    public function testLocateAllMapMergesWithAutoDetect(): void
    {
        // A map that only overrides typescript leaves php on its auto-detected default.
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['typescript' => 'my-tsls --stdio']])]);
        $bin = $project->write('vendor/bin/phpactor', "#!/bin/sh\n");
        chmod($bin, permissions: 0o755);
        putenv('PATH=/nonexistent');

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame([$bin, 'language-server'], $servers['php']['command'], 'php keeps its auto-detected server');
        $this->assertSame(['my-tsls', '--stdio'], $servers['typescript']['command'], 'the override applies');
    }

    public function testAutoDetectedAndDescribeHelpers(): void
    {
        $project = new TempProject();
        $bin = $project->write('vendor/bin/phpactor', "#!/bin/sh\n");
        chmod($bin, permissions: 0o755);
        putenv('PATH=/nonexistent');

        $this->assertSame('phpactor', ServerLocator::autoDetected($project->dir)['php']['name']);
        $this->assertSame(['node', '/x/ls.js', '--stdio'], ServerLocator::describe('node /x/ls.js --stdio')['command']);
        $this->assertSame('ls', ServerLocator::describe('node /x/ls.js --stdio')['name']);
    }

    public function testLocateAllEnvVariableIsTheCatchAll(): void
    {
        $project = new TempProject();
        putenv('PORTA_LSP=custom-ls --stdio');

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['*'], array_keys($servers));
        $this->assertSame('custom-ls', $servers['*']['name']);
    }

    public function testLocateAllDefaultsDetectPerLanguageServers(): void
    {
        $project = new TempProject();
        $tools = new TempProject([], 'porta-bin-');
        foreach (['phpactor', 'typescript-language-server', 'pyright-langserver'] as $bin) {
            chmod($tools->write($bin, "#!/bin/sh\n"), permissions: 0o755);
        }
        putenv('PATH='.$tools->dir);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame('phpactor', $servers['php']['name']);
        $this->assertSame('typescript-language-server', $servers['javascript']['name']);
        $this->assertSame($servers['javascript'], $servers['typescript'], 'JS and TS share one server');
        $this->assertSame('pyright', $servers['python']['name']);
    }

    public function testLocateAllDefaultsFallBackToPylsp(): void
    {
        $project = new TempProject();
        $tools = new TempProject([], 'porta-bin-');
        chmod($tools->write('pylsp', "#!/bin/sh\n"), permissions: 0o755);
        putenv('PATH='.$tools->dir);

        $servers = ServerLocator::locateAll($project->dir);

        $this->assertSame(['python'], array_keys($servers));
        $this->assertSame('pylsp', $servers['python']['name']);
    }

    public function testLocateAllWithNothingInstalledIsEmpty(): void
    {
        $project = new TempProject();
        putenv('PATH=/nonexistent');

        $this->assertSame([], ServerLocator::locateAll($project->dir));
    }
}
