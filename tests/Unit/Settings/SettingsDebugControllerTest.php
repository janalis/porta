<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DebugAdapters;
use Porta\I18n\Translator;
use Porta\Settings\SettingsDebugController;
use Porta\Tests\Support\TempProject;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SettingsDebugControllerTest extends TestCase
{
    private TempProject $project;
    private DebugAdapters $adapters;
    private StatusBarWidget $statusBar;

    private function controller(): SettingsDebugController
    {
        $this->project = new TempProject();
        $this->adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'node a.js', 'launch' => ['env' => ['X' => '1']]]]]);
        $this->statusBar = new StatusBarWidget();
        $notifier = new Notifier($this->statusBar, new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20)));

        return new SettingsDebugController($this->project->dir, $this->adapters, $notifier, new Translator('en'));
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    public function testRowsExposeTheConfiguredAdapterAndLaunchJson(): void
    {
        $rows = $this->controller()->rows('php');

        $this->assertSame('debugAdapter', $rows[0]->key);
        $this->assertSame('node a.js', $rows[0]->value);
        $this->assertSame('not configured', $rows[0]->hint);
        $this->assertSame('debugLaunch', $rows[1]->key);
        $this->assertSame('{"env":{"X":"1"}}', $rows[1]->value);

        $unset = $this->controller()->rows('python');
        $this->assertSame('', $unset[0]->value);
        $this->assertSame('', $unset[1]->value);
    }

    public function testAdapterChangePersistsUpdatesLiveAndBlankClears(): void
    {
        $controller = $this->controller();
        $controller->change('php', 'debugAdapter', ' node b.js ');

        $written = json_decode((string) file_get_contents($this->project->path('.porta.json')), associative: true);
        $this->assertSame('node b.js', $written['languages']['php']['debug']['adapter']);
        $this->assertSame(['node', 'b.js'], $this->adapters->adapterFor('php')?->adapterArgv, 'live registry updated');
        $this->assertSame(['env' => ['X' => '1']], $this->adapters->adapterFor('php')?->launch, 'launch overrides preserved');
        $this->assertStringContainsString('PHP: node b.js', $this->flashed());

        $controller->change('php', 'debugAdapter', '');
        $this->assertNull($this->adapters->adapterFor('php'));
        $this->assertStringContainsString('not configured', $this->flashed());
    }

    public function testLaunchChangeValidatesJson(): void
    {
        $controller = $this->controller();
        $controller->change('php', 'debugLaunch', '{"stopOnEntry": true}');

        $written = json_decode((string) file_get_contents($this->project->path('.porta.json')), associative: true);
        $this->assertSame(['stopOnEntry' => true], $written['languages']['php']['debug']['launch']);
        $this->assertSame(['stopOnEntry' => true], $this->adapters->launchOverrides('php'));

        $controller->change('php', 'debugLaunch', '{not json');
        $this->assertStringContainsString('Invalid JSON — not saved', $this->flashed());
        $this->assertSame(['stopOnEntry' => true], $this->adapters->launchOverrides('php'), 'invalid JSON changed nothing');

        $controller->change('php', 'debugLaunch', '["a","list"]');
        $this->assertStringContainsString('Invalid JSON — not saved', $this->flashed(), 'a JSON list is not a launch object');

        $controller->change('php', 'debugLaunch', '');
        $this->assertSame([], $this->adapters->launchOverrides('php'), 'blank clears the overrides');
        $written = json_decode((string) file_get_contents($this->project->path('.porta.json')), associative: true);
        $this->assertArrayNotHasKey('launch', $written['languages']['php']['debug'] ?? []);
    }
}
