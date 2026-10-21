<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Tool;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\PluginInstaller;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolRequirement;
use Porta\Plugin\Tool\ToolRole;
use Porta\Plugin\Tool\ToolSource;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTools;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** What each plugin runs and how it stands — the manifest's tools and the ones registered in code. */
final class ToolCatalogTest extends TestCase
{
    private TempProject $project;

    private PluginManager $plugins;

    /** @var list<string> */
    private array $probed = [];

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->plugins = new PluginManager(new EventDispatcher(), new PluginSettings($this->project->dir, []));
    }

    /** @param array<string, string> $path */
    private function tools(array $path = []): TestTools
    {
        return new TestTools(
            $this->project->dir,
            new PluginInstaller($this->project->dir, null, new Translator('en'), null),
            $this->plugins,
            new Notifier(new StatusBarWidget(), TestTui::create(80, 24)),
            new FakePluginLifecycle(),
            path: $path,
            run: function (array $argv, string $cwd, \Closure $onExit): void {
                $this->probed[] = $argv[0];
                $answer = 'x 3.2.1';
                $onExit(0, $answer);
            },
        );
    }

    /** @param array<string, mixed> $manifest */
    private function plugin(string $name, array $manifest): void
    {
        $this->project->write('.porta/plugins/'.$name.'/plugin.php', "<?php\n");
        $this->project->write('.porta/plugins/'.$name.'/composer.json', (string) \json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    public function testTheManifestIsReadAndKeptUntilItChanges(): void
    {
        $this->plugin('p', ['description' => 'Does p things', 'extra' => ['porta' => ['tools' => [['name' => 'a']]]]]);
        $tools = $this->tools();

        $this->assertSame('Does p things', $tools->catalog->manifest('p')?->description);
        $this->assertSame(['a'], \array_map(static fn (ToolRequirement $tool): string => $tool->name, $tools->catalog->declared('p')));
        $this->assertNull($tools->catalog->manifest('nope'));
        $this->assertSame([], $tools->catalog->declared('nope'), 'not installed, nothing declared');

        $this->plugin('p', ['extra' => ['porta' => ['tools' => [['name' => 'a'], ['name' => 'b']]]]]);
        \touch($this->project->path('.porta/plugins/p/composer.json'), \time() + 10);
        \clearstatcache();
        $this->assertCount(2, $tools->catalog->declared('p'), 'a changed manifest is read again');
    }

    public function testAPluginWithoutAManifestDeclaresNothing(): void
    {
        $this->project->write('.porta/plugins/bare/plugin.php', "<?php\n");

        $this->assertSame([], $this->tools()->catalog->declared('bare'));
    }

    public function testAStatusSaysWhereAndWhatAndWhoManagesIt(): void
    {
        $this->plugin('p', ['extra' => ['porta' => ['tools' => [['name' => 'a', 'sources' => [['brew', 'a']]], ['name' => 'b', 'role' => 'suggested']]]]]);
        $bin = $this->project->write('Cellar/a/1/bin/a', "#!/bin/sh\n");
        \chmod($bin, permissions: 0o755);
        $tools = $this->tools(['a' => $bin]);

        [$a, $b] = $tools->catalog->statuses('p');
        $this->assertTrue($a->available);
        $this->assertSame($bin, $a->path);
        $this->assertSame('', $a->version, 'not probed yet: the cache never spawns');
        $this->assertSame(ToolManager::Brew, $a->manager?->manager);
        $this->assertFalse($a->blocks());
        $this->assertFalse($b->available);
        $this->assertFalse($b->blocks(), 'suggested: it costs one feature, not the plugin');
        $this->assertSame(ToolRole::Suggested, $b->tool->role);

        $done = 0;
        $tools->catalog->probe(['p'], static function () use (&$done): void {
            ++$done;
        });
        $this->assertSame(1, $done, 'once, after every installed tool answered');
        $this->assertSame([$bin], $this->probed, 'a missing tool is not asked');
        $this->assertSame('3.2.1', $tools->catalog->statuses('p')[0]->version);
    }

    public function testProbingNothingStillCallsBack(): void
    {
        $done = 0;
        $this->tools()->catalog->probe(['nope'], static function () use (&$done): void {
            ++$done;
        });

        $this->assertSame(1, $done);
    }

    /** A tool registered in code says only whether it is there, so only its plugin's own installer can update it. */
    public function testACodeRegisteredToolIsListedAfterTheManifestsOnes(): void
    {
        $this->plugin('p', ['extra' => ['porta' => ['tools' => [['name' => 'a']]]]]);
        $available = false;
        $asked = 0;
        $api = new PluginApi('p', $this->plugins, $this->project->dir, new PluginSettings($this->project->dir, []));
        $api->registerCommand('install', 'Install it', static function (): void {});
        $api->registerInstaller(
            'srv',
            static function () use (&$available): bool {
                return $available;
            },
            [
                ['brew',                  'srv'],
                [PluginApi::INSTALL_SELF, 'install'],
            ],
            static function () use (&$asked): string {
                ++$asked;

                return '0.21.0';
            },
        );
        $api->registerInstaller('other', static fn (): bool => true, [['brew', 'other']]);
        $this->plugins->commit($api);
        $tools = $this->tools();

        [, $srv, $other] = $tools->catalog->statuses('p');
        $this->assertSame('srv', $srv->tool->name);
        $this->assertSame([ToolManager::Brew, ToolManager::Plugin], \array_map(static fn (ToolSource $source): ToolManager => $source->manager, $srv->tool->sources));
        $this->assertFalse($srv->available);
        $this->assertSame('', $srv->version);
        $this->assertNull($srv->manager);
        $this->assertSame(0, $asked, 'a describer is not asked about a tool that is not there');

        $available = true;
        $srv = $tools->catalog->statuses('p')[1];
        $this->assertSame('0.21.0', $srv->version);
        $this->assertSame(ToolManager::Plugin, $srv->manager?->manager, 'the plugin that installs it updates it');
        $this->assertNull($other->manager, 'no installer of its own: managed outside Porta');
        $this->assertSame('', $other->version, 'no describer, nothing to say');
    }
}
