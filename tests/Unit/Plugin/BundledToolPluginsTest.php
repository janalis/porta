<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPluginRuntime;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The bundled plugins with no tests of their own, read off the plugin itself
 * — no IDE booted: what each hands the tool it runs.
 */
final class BundledToolPluginsTest extends TestCase
{
    use PreservesPath;

    private PluginManager $manager;
    private PluginSettings $settings;

    /** @param array<string, bool|int|string> $values seeded, never persisted: a write would land in the suite's shared home */
    private function activate(string $name, array $values = []): AbstractPlugin
    {
        \putenv('PATH=/nonexistent'); // nothing discovered: only a configured binary is found
        $project = new TempProject([]);
        $this->settings = new PluginSettings($project->dir, [$name => $values]);
        $this->manager = new PluginManager(new EventDispatcher(), $this->settings);
        $this->manager->wireRuntime(TestPluginRuntime::create(new CodeEditorWidget(), new StatusBarWidget()));
        $api = $this->manager->api($name, $project->dir, $this->settings);
        $plugin = require \dirname(__DIR__, levels: 3).'/plugins/'.$name.'/porta.php';
        $this->assertInstanceOf(AbstractPlugin::class, $plugin);
        $plugin->activate($api);
        $this->manager->commit($api, $plugin);

        return $plugin;
    }

    /** phpactor serves PHP as its own `language-server` subcommand — and, with no binary, is not there to serve it. */
    public function testPhpactorIsRunAsItsLanguageServer(): void
    {
        $bin = new TempProject(['phpactor' => "#!/bin/sh\n"]);
        \chmod($bin->path('phpactor'), permissions: 0o755);
        $this->activate('phpactor', ['binary' => $bin->path('phpactor')]);
        $this->assertSame([$bin->path('phpactor'), 'language-server'], $this->manager->languageServers()['php'][0]['command'] ?? null);

        $this->activate('phpactor');
        $this->assertArrayNotHasKey('php', $this->manager->languageServers(), 'not found: no command to run');
    }

    /** chroma is asked for JSON tokens by lexer name, and claims the languages core does not highlight itself. */
    public function testChromaIsAskedForJsonByLexer(): void
    {
        $plugin = $this->activate('chroma');

        $this->assertSame(['-f', 'json', '--lexer', 'rust'], Probe::invoke($plugin, 'lexerArgs', 'rust'));
        $languages = \array_find($this->settings->rows('chroma'), static fn (object $row): bool => 'pluginSetting:languages' === $row->key);
        $this->assertSame('rust,go,ruby,elixir,zig', $languages?->default);
    }
}
