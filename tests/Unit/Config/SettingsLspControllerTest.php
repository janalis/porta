<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Config\IdeConfig;
use Porta\Config\SettingsLspController;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsLspControllerTest extends TestCase
{
    private string $originalPath;
    private int $reopened = 0;

    protected function setUp(): void
    {
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH=/nonexistent'); // deterministic auto-detect
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function controller(string $rootDir, StatusBarWidget $status, array $overrides = [], ?string $lspOverride = null): SettingsLspController
    {
        $this->reopened = 0;
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $pool = new LspSessionPool($rootDir, static fn (): ?string => null);

        return new SettingsLspController($rootDir, $pool, $overrides, $lspOverride, function (): void { ++$this->reopened; }, $status, $tui, new Translator('en'));
    }

    private function flashText(StatusBarWidget $status): string
    {
        $flash = new \ReflectionProperty($status, 'flash')->getValue($status);

        return (string) ($flash['text'] ?? '');
    }

    public function testRowShowsTheOverrideAndAnAutoHint(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), ['php' => 'phpactor language-server']);

        $php = $controller->rows('php');
        $this->assertSame('lspServer', $php[0]['key']);
        $this->assertSame('text', $php[0]['kind']);
        $this->assertSame('phpactor language-server', $php[0]['value']);

        $this->assertSame('auto-detect', $controller->rows('typescript')[0]['hint'], 'no server on PATH → generic hint');

        // With a detectable server, the hint names it.
        $bin = $project->write('vendor/bin/phpactor', "#!/bin/sh\n");
        chmod($bin, 0755);
        $this->assertSame('auto: phpactor', $controller->rows('php')[0]['hint']);
    }

    public function testChangingAServerPersistsReopensAndFlashes(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget());

        $controller->changeLspServer('typescript', 'typescript-language-server --stdio');

        $this->assertSame('typescript-language-server --stdio', IdeConfig::load($project->dir)->lspServers['typescript']);
        $this->assertSame(1, $this->reopened, 'buffers reopened so the server relaunches');
        $this->assertStringContainsString('TypeScript LSP: typescript-language-server --stdio', $this->flashText($status));
        // The row now reflects the new value.
        $this->assertSame('typescript-language-server --stdio', $controller->rows('typescript')[0]['value']);
    }

    public function testClearingAServerRevertsToAutoDetect(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['php' => 'phpactor language-server']])]);
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), ['php' => 'phpactor language-server']);

        $controller->changeLspServer('php', '   ');

        $this->assertArrayNotHasKey('lsp', json_decode((string) file_get_contents($project->path('.porta.json')), true), 'empty map pruned');
        $this->assertSame('', $controller->rows('php')[0]['value']);
        $this->assertStringContainsString('PHP LSP: auto-detect', $this->flashText($status));
    }

    public function testAnLspCliOverrideRefusesTheEdit(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), [], 'my-ls --stdio');

        $controller->changeLspServer('php', 'phpactor');

        $this->assertStringContainsString('set via --lsp', $this->flashText($status));
        $this->assertSame(0, $this->reopened, 'nothing reopened');
        $this->assertFalse(is_file($project->path('.porta.json')), 'nothing persisted');
    }

    public function testAFailedPersistIsFlagged(): void
    {
        $project = new TempProject();
        mkdir($project->path('.porta.json'));
        $controller = $this->controller($project->dir, $status = new StatusBarWidget());

        $controller->changeLspServer('php', 'phpactor');
        $this->assertStringContainsString('(not saved)', $this->flashText($status));
    }
}
