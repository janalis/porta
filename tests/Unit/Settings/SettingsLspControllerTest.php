<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\IdeConfig;
use Porta\Config\PortaJsonWriter;
use Porta\Event\SettingsRowsStale;
use Porta\I18n\Translator;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Settings\SettingsLspController;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsScope;
use Porta\Tests\Support\FixedActiveFile;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsLspControllerTest extends TestCase
{
    use PreservesPath;
    use StatusReads;

    private int $reopened = 0;

    protected function setUp(): void
    {
        putenv('PATH=/nonexistent'); // deterministic auto-detect
    }

    /**
     * @param array<string, string> $overrides
     */
    private function controller(string $rootDir, StatusBarWidget $status, array $overrides = [], ?string $lspOverride = null): SettingsLspController
    {
        $this->reopened = 0;
        $tui = new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24));
        $pool = new LspSessionPool($rootDir, new FixedActiveFile(), new Translator('en'));

        $reopened = &$this->reopened;
        $reopener = new class($reopened) implements BufferReopener {
            public function __construct(
                private int &$reopened,
            ) {}

            public function reopenAllInLsp(): void
            {
                ++$this->reopened;
            }
        };
        $provider = new class implements LanguageServerProvider {
            public function languageServers(): array
            {
                return [];
            }
        };
        $reconfigurer = new LspReconfigurer($pool, $reopener, $provider, $rootDir, $lspOverride);

        // Every assertion below reads the project's .porta.json back, so the
        // session scope is pinned to Project; SettingsScope defaults to Global.
        $scope = new SettingsScope(ConfigFiles::for($rootDir));
        $scope->set(ConfigScope::Project);

        return new SettingsLspController(
            $rootDir,
            $overrides,
            $reconfigurer,
            new Notifier($status, $tui),
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($rootDir)),
            $scope,
        );
    }

    public function testRowShowsTheOverrideAndAnAutoHint(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget(), ['php' => 'phpactor language-server']);

        $php = $controller->rows('php');
        $this->assertSame('lspServer', $php[0]->key);
        $this->assertSame(SettingsRowKind::Text, $php[0]->kind);
        $this->assertSame('phpactor language-server', $php[0]->value);
        $this->assertFalse($php[0]->restart, 'the LSP server command applies live');

        $this->assertSame('auto-detect', $controller->rows('typescript')[0]->hint, 'no server on PATH → generic hint');
        $this->assertSame('auto-detect', $controller->rows('php')[0]->hint, 'PHP is not auto-detected — it comes from a plugin');

        // With a detectable server on PATH, the hint names it. A FRESH
        // controller, because the probe is memoized — see the test below for
        // what refreshes it.
        $tools = $this->toolsOnPath('typescript-language-server');
        $this->assertSame('auto: typescript-language-server', $this->controller($project->dir, new StatusBarWidget())->rows('typescript')[0]->hint);
        $this->assertDirectoryExists($tools->dir);
    }

    /**
     * The probe walks every PATH entry for servers that are usually absent, and
     * the settings nav rebuilds the page on every arrow press — thirteen
     * language pages cost ~110ms of pure PATH scanning before this. So it is
     * resolved once and dropped when something might have installed a tool.
     */
    public function testTheAutoDetectProbeIsMemoizedUntilTheRowsGoStale(): void
    {
        $project = new TempProject();
        $controller = $this->controller($project->dir, new StatusBarWidget());
        $this->assertSame('auto-detect', $controller->rows('typescript')[0]->hint, 'nothing on PATH to begin with');

        // A server appears mid-session. The memoized answer stands: the page is
        // rebuilt per keypress and must not pay for a PATH walk each time.
        $tools = $this->toolsOnPath('typescript-language-server');
        $this->assertSame('auto-detect', $controller->rows('typescript')[0]->hint, 'the probe is not repeated per call');

        // Installing a tool is exactly what raises this, and it is what makes
        // the new server visible without a restart.
        $controller->onSettingsRowsStale(new SettingsRowsStale());

        $this->assertSame('auto: typescript-language-server', $controller->rows('typescript')[0]->hint, 'the stale event drops the probe');
        $this->assertDirectoryExists($tools->dir);
    }

    /** A throwaway bin dir on PATH holding one executable stub. */
    private function toolsOnPath(string $binary): TempProject
    {
        $tools = new TempProject([], 'porta-bin-');
        chmod($tools->write($binary, "#!/bin/sh\n"), permissions: 0o755);
        putenv('PATH='.$tools->dir);

        return $tools;
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
        $this->assertSame('typescript-language-server --stdio', $controller->rows('typescript')[0]->value);
    }

    public function testClearingAServerRevertsToAutoDetect(): void
    {
        $project = new TempProject(['.porta.json' => json_encode(['lsp' => ['php' => 'phpactor language-server']])]);
        $controller = $this->controller($project->dir, $status = new StatusBarWidget(), ['php' => 'phpactor language-server']);

        $controller->changeLspServer('php', '   ');

        $this->assertArrayNotHasKey('lsp', json_decode((string) file_get_contents($project->path('.porta.json')), associative: true), 'empty map pruned');
        $this->assertSame('', $controller->rows('php')[0]->value);
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
