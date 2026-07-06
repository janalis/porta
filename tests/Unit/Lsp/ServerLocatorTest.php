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
        chmod($bin, 0755);
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
        chmod($phpactor, 0755);
        putenv('PATH='.$tools->dir);
        $this->assertSame('phpactor', ServerLocator::locate($project->dir)['name']);

        unlink($phpactor);
        $intelephense = $tools->write('intelephense', "#!/bin/sh\n");
        chmod($intelephense, 0755);
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
}
