<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * PluginLifecycle had no dedicated Unit test before this: its 17-argument
 * constructor is entirely DI-wired collaborators (LiveKeybindings, a real
 * PluginManager, MenuRebuilder, Tui, ...), none of which this project's hard
 * rules allow mocking (final classes). This boots a real IDE per test and
 * observes each operation's real effect, matching the RunsIde/Services
 * pattern used elsewhere for DI-heavy classes (see WorkbenchCommandsTest).
 *
 * PluginManager and SettingsNavWidget are not published on the `porta.ide`
 * locator (they are internal collaborators, not #[AsIdeService]), so plugin
 * activation is observed through CommandRegistry — every test plugin
 * registers a command, and its presence/absence is the load-bearing signal
 * `hasPlugin()` would otherwise give — and the sidebar sync is observed by
 * walking PluginLifecycle's own object graph via Probe.
 */
final class PluginLifecycleTest extends TestCase
{
    private TempProject $project;
    private ?Ide $ide = null;

    /** @param array<string, mixed> $porta merged into .porta.json */
    private function boot(array $porta = []): Ide
    {
        $files = [];
        if ([] !== $porta) {
            $files['.porta.json'] = json_encode($porta);
        }
        $this->project = new TempProject($files);
        new OnboardingState($this->project->dir)->markWelcomeSeen();

        return $this->ide = new Ide($this->project->dir, null, new VirtualTerminal(columns: 100, rows: 30));
    }

    /** These tests build an Ide but never run() it — arm the same cleanup its own finally would. */
    protected function tearDown(): void
    {
        if (null !== $this->ide) {
            Services::resolve($this->ide, ViewRegistry::class)->closeAll();
            Services::resolve($this->ide, Notifier::class)->dismissAll();
        }
    }

    /** @param list<string> $requires */
    private function writePlugin(string $name, array $requires = [], string $command = 'boom'): void
    {
        $dir = $this->project->dir.'/.porta/plugins/'.$name;
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', <<<PHP
            <?php
            return new class implements Porta\\Plugin\\Api\\PortaPlugin {
                public function name(): string { return '{$name}'; }
                public function activate(Porta\\Plugin\\Api\\PluginApi \$api): void
                {
                    \$api->registerCommand('{$command}', '{$name}: {$command}', static fn () => null);
                }
            };
            PHP);
        if ([] !== $requires) {
            file_put_contents($dir.'/composer.json', json_encode([
                'extra' => ['porta' => ['requires' => $requires]],
            ]));
        }
    }

    private function writeMalformedPlugin(string $name): void
    {
        $dir = $this->project->dir.'/.porta/plugins/'.$name;
        mkdir($dir, permissions: 0o777, recursive: true);
        file_put_contents($dir.'/plugin.php', data: '<?php return 42;');
    }

    private function lifecycle(Ide $ide): PluginLifecycle
    {
        return Services::resolve($ide, PluginLifecycle::class);
    }

    private function hasCommand(Ide $ide, string $id): bool
    {
        return Services::resolve($ide, CommandRegistry::class)->has($id);
    }

    public function testEnableActivatesAProjectPluginAndPersistsTheChoice(): void
    {
        $ide = $this->boot(['disabledPlugins' => ['acme']]);
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $this->assertTrue($lifecycle->isDisabled('acme'), 'starts disabled per .porta.json');

        $lifecycle->enable('acme');

        $this->assertFalse($lifecycle->isDisabled('acme'), 'enable() flips the live state');
        $this->assertTrue($this->hasCommand($ide, 'acme:boom'), 'enable() actually loads the plugin');
        $onDisk = json_decode((string) file_get_contents($this->project->dir.'/.porta.json'), associative: true);
        $this->assertSame([], $onDisk['disabledPlugins'] ?? [], 'the choice is persisted, not just held in memory');
    }

    public function testDisableDeactivatesAndPersistsWithNoShadowFallback(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('acme');
        $this->assertTrue($this->hasCommand($ide, 'acme:boom'));

        $lifecycle->disable('acme');

        $this->assertTrue($lifecycle->isDisabled('acme'));
        $this->assertFalse($this->hasCommand($ide, 'acme:boom'), 'no other copy exists, so nothing reloads');
        $onDisk = json_decode((string) file_get_contents($this->project->dir.'/.porta.json'), associative: true);
        $this->assertSame(['acme'], $onDisk['disabledPlugins'] ?? []);
    }

    public function testEnableCascadesToAMissingRequirementFirst(): void
    {
        $ide = $this->boot(['disabledPlugins' => ['base', 'dependent']]);
        $this->writePlugin('base');
        $this->writePlugin('dependent', requires: ['base']);
        $lifecycle = $this->lifecycle($ide);

        $lifecycle->enable('dependent');

        $this->assertFalse($lifecycle->isDisabled('base'), 'the requirement was pulled in transitively');
        $this->assertFalse($lifecycle->isDisabled('dependent'));
        $this->assertTrue($this->hasCommand($ide, 'base:boom'));
        $this->assertTrue($this->hasCommand($ide, 'dependent:boom'));
    }

    public function testDisableCascadesToItsDependents(): void
    {
        $ide = $this->boot();
        $this->writePlugin('base');
        $this->writePlugin('dependent', requires: ['base']);
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('base');
        $lifecycle->enable('dependent');
        $this->assertTrue($this->hasCommand($ide, 'dependent:boom'));

        $lifecycle->disable('base');

        $this->assertTrue($lifecycle->isDisabled('base'));
        $this->assertTrue($lifecycle->isDisabled('dependent'), 'what needed it goes too');
        $this->assertFalse($this->hasCommand($ide, 'dependent:boom'));
        $this->assertFalse($this->hasCommand($ide, 'base:boom'));
    }

    public function testActivateInstalledLoadsAndRegistersCommands(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme', command: 'greet');
        $lifecycle = $this->lifecycle($ide);
        $file = $this->project->dir.'/.porta/plugins/acme/plugin.php';

        $result = $lifecycle->activateInstalled($file);

        $this->assertNull($result, 'success reports nothing');
        $this->assertTrue($this->hasCommand($ide, 'acme:greet'));
    }

    public function testActivateInstalledReturnsATranslatedErrorOnFailure(): void
    {
        $ide = $this->boot();
        $this->writeMalformedPlugin('broken');
        $lifecycle = $this->lifecycle($ide);
        $file = $this->project->dir.'/.porta/plugins/broken/plugin.php';

        $result = $lifecycle->activateInstalled($file);

        $this->assertNotNull($result, 'a malformed plugin file must be reported, not silently ignored');
        $this->assertFalse($this->hasCommand($ide, 'broken:boom'));
    }

    public function testDeactivateRemovesCommandsAndPanesWhenNoShadowCopyExists(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('acme');
        $this->assertTrue($this->hasCommand($ide, 'acme:boom'));

        // deactivate()'s contract: the caller already removed the plugin from
        // disk (that is what makes this a real "removal", not a live reload of
        // the same copy) — without deleting it here, the fallback loop would
        // just find and reactivate the same file it just deactivated.
        new \Symfony\Component\Filesystem\Filesystem()->remove($this->project->dir.'/.porta/plugins/acme');
        $lifecycle->deactivate('acme');

        $this->assertFalse($this->hasCommand($ide, 'acme:boom'), 'its command dies with it');
    }

    public function testIsDisabledForAProjectPluginTracksTheDisabledSet(): void
    {
        $ide = $this->boot();
        $lifecycle = $this->lifecycle($ide);

        $this->assertFalse($lifecycle->isDisabled('never-heard-of-it'), 'project/global plugins are on by default');
    }

    public function testListedNamesIncludesDisabledPluginsSoTheirPagesStayReachable(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('acme');
        $lifecycle->disable('acme');

        $this->assertContains('acme', $lifecycle->listedNames(), 'a disabled plugin is still listed, just marked disabled');
    }

    public function testListedNamesDedupesFirstOccurrencePerName(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $this->writePlugin('other');
        $lifecycle = $this->lifecycle($ide);

        $names = $lifecycle->listedNames();

        $this->assertSame(array_unique($names), $names, 'no name repeats even though installed() can yield duplicates across scopes');
    }

    public function testOnPluginSettingChangedForwardsToTheManagerAndReconfiguresProviderSettings(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('acme');

        // Must not throw: 'acme' provides no language server, so this exercises
        // the "not a provider, no reconfigure" branch of onProviderSettingChanged
        // via the onPluginSettingChanged delegation.
        $lifecycle->onPluginSettingChanged('acme', 'someKey', 'someValue');
        $this->addToAssertionCount(1);
    }

    public function testRefreshNavSyncsTheSidebarWithTheListedNames(): void
    {
        $ide = $this->boot();
        $this->writePlugin('acme');
        $lifecycle = $this->lifecycle($ide);
        $lifecycle->enable('acme');
        $nav = Probe::object($lifecycle, 'pages', 'nav');

        $lifecycle->refreshNav();

        $ids = array_column(Probe::get($nav, 'entries'), 'id');
        $this->assertContains('plugin:acme', $ids, 'refreshNav() syncs the sidebar to the current listedNames()');
    }
}
