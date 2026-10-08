<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Config\PortaJsonWriter;
use Porta\Debug\DebugAdapters;
use Porta\I18n\Translator;
use Porta\Settings\SettingsDebugController;
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsScope;
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

        // Every assertion below reads the project's .porta.json back, so the
        // session scope is pinned to Project; SettingsScope defaults to Global.
        $scope = new SettingsScope(ConfigFiles::for($this->project->dir));
        $scope->set(ConfigScope::Project);

        return new SettingsDebugController(
            $this->adapters,
            $notifier,
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($this->project->dir)),
            $scope,
            new SettingsProvenance(ConfigFiles::for($this->project->dir)),
        );
    }

    private function flashed(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode('', $this->statusBar->render(new RenderContext(100, 1))));
    }

    public function testRowsExposeTheConfiguredAdapterAndLaunchJson(): void
    {
        $rows = $this->controller()->rows('php');

        $this->assertSame('debugAdapter', $rows[0]->key);
        $this->assertSame('node a.js', $rows[0]->value);
        $this->assertSame('not configured', $rows[0]->hint);
        $this->assertFalse($rows[0]->restart, 'a debug-adapter change never needs a restart');
        $this->assertSame('debugLaunch', $rows[1]->key);
        $this->assertSame('{"env":{"X":"1"}}', $rows[1]->value);
        $this->assertFalse($rows[1]->restart, 'a launch-config change never needs a restart');

        $unset = $this->controller()->rows('python');
        $this->assertSame('', $unset[0]->value);
        $this->assertSame('', $unset[1]->value);
    }

    /**
     * json_encode() returns false (not a string) when it cannot encode its
     * input; the (string) cast is what keeps a non-empty $launch array from
     * ever putting a strict bool where the row's value must be a string.
     */
    public function testRowsCoerceAJsonEncodeFailureToAnEmptyStringRatherThanFalse(): void
    {
        $this->project = new TempProject();
        $this->adapters = new DebugAdapters(['php' => ['debug' => ['adapter' => 'node a.js', 'launch' => ['bad' => "\xB1\x31"]]]]);
        $this->statusBar = new StatusBarWidget();
        $notifier = new Notifier($this->statusBar, new Tui(terminal: new VirtualTerminal(columns: 100, rows: 20)));
        $controller = new SettingsDebugController(
            $this->adapters,
            $notifier,
            new Translator('en'),
            new PortaJsonWriter(ConfigFiles::for($this->project->dir)),
            new SettingsScope(ConfigFiles::for($this->project->dir)),
            new SettingsProvenance(ConfigFiles::for($this->project->dir)),
        );

        $rows = $controller->rows('php');

        $this->assertSame('', $rows[1]->value, 'a json_encode() failure must not leak a bare bool into the row value');
    }

    public function testAdapterChangePersistsUpdatesLiveAndBlankClears(): void
    {
        $controller = $this->controller();
        $controller->change('php', 'debugAdapter', ' node b.js ');

        $written = \json_decode((string) \file_get_contents($this->project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
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
        $this->assertTrue($controller->change('php', 'debugLaunch', '{"stopOnEntry": true}'), 'accepted');

        $written = \json_decode((string) \file_get_contents($this->project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame(['stopOnEntry' => true], $written['languages']['php']['debug']['launch']);
        $this->assertSame(['stopOnEntry' => true], $this->adapters->launchOverrides('php'));
        $this->assertStringContainsString('PHP: Launch config (JSON)', $this->flashed(), 'a successful launch change flashes, same as the adapter change does');

        $controller->change('php', 'debugLaunch', '{not json');
        $this->assertStringContainsString('Invalid JSON — not saved', $this->flashed());
        $this->assertSame(['stopOnEntry' => true], $this->adapters->launchOverrides('php'), 'invalid JSON changed nothing');

        $controller->change('php', 'debugLaunch', '["a","list"]');
        $this->assertStringContainsString('Invalid JSON — not saved', $this->flashed(), 'a JSON list is not a launch object');

        $controller->change('php', 'debugLaunch', '');
        $this->assertSame([], $this->adapters->launchOverrides('php'), 'blank clears the overrides');
        $written = \json_decode((string) \file_get_contents($this->project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('launch', $written['languages']['php']['debug'] ?? []);
    }

    /**
     * A whitespace-only value must trim() down to '' and take the "blank
     * clears the overrides" branch — untrimmed, `'' !== $json` still passes
     * (the string is non-empty), so json_decode() would be handed pure
     * whitespace and fail, wrongly flashing "Invalid JSON" instead of
     * clearing.
     */
    public function testLaunchChangeTreatsWhitespaceOnlyInputAsBlank(): void
    {
        $controller = $this->controller();

        $controller->change('php', 'debugLaunch', "   \n\t  ");

        $this->assertSame([], $this->adapters->launchOverrides('php'), 'whitespace-only trims to blank, which clears the overrides');
        $this->assertStringNotContainsString('Invalid JSON', $this->flashed());
    }

    /**
     * A refusal is now type-visible, which is what lets the form keep the
     * inline editor open with the text as typed instead of snapping the row
     * back to the persisted value and losing it.
     */
    public function testInvalidJsonIsRefusedRatherThanSilentlyDropped(): void
    {
        $controller = $this->controller();

        $this->assertFalse($controller->change('php', 'debugLaunch', '{not json'), 'the caller is told, not just the status bar');
        $this->assertStringContainsString('Invalid JSON', $this->flashed());
        $this->assertTrue($controller->change('php', 'debugAdapter', 'node dbg.js'), 'an adapter change has nothing to refuse');
    }
}
