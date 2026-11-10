<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Event\SettingsSectionRequested;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Settings\SettingsPluginsController;
use Porta\Settings\SettingsProvenance;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsScope;
use Porta\Settings\SettingsScopeRow;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StoreFixture;
use Porta\Tests\Support\TestTools;
use Porta\Widget\Settings\PluginStoreWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** What the store adds to a plugin's own page: its Update, and a bundled plugin's takeover offer. */
final class SettingsPluginsStoreRowsTest extends TestCase
{
    use PumpsLoop;

    private StoreFixture $store;

    protected function setUp(): void
    {
        $this->store = new StoreFixture();
    }

    /** @var list<SettingsSectionRequested> */
    private array $requested = [];

    private function controller(): SettingsPluginsController
    {
        $events = new EventDispatcher();
        $events->addListener(SettingsSectionRequested::class, function (SettingsSectionRequested $event): void {
            $this->requested[] = $event;
        });
        $files = ConfigFiles::for($this->store->project->dir);
        $scope = new SettingsScope($files);
        $scope->set(ConfigScope::Global);
        $pluginSettings = new PluginSettings($this->store->project->dir, [], null, $scope);
        $tools = TestTools::bare($this->store->project->dir, $pluginSettings);

        return new SettingsPluginsController(
            $this->store->installer,
            $pluginSettings,
            $this->store->notifier,
            $this->store->translator,
            $this->store->lifecycle,
            $tools->catalog,
            $tools->jobs,
            $tools->updates,
            new SettingsScopeRow($scope, $files, $this->store->translator),
            $scope,
            new SettingsProvenance($files),
            storeUpdates: $this->store->updates(),
            catalog: $this->store->catalog,
            events: $events,
        );
    }

    /** @return array<string, SettingsRow> */
    private static function rows(SettingsPluginsController $controller, string $name): array
    {
        return \array_column($controller->pluginRows($name), null, 'key');
    }

    public function testAnOutdatedPluginsPageOffersItsUpdate(): void
    {
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->publish('acme/porta-current');
        $this->store->fill();
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->store->installed('acme/porta-current', 'v1.0.0');
        $controller = $this->controller();

        $row = self::rows($controller, 'old')['pluginUpdate:global:old'] ?? null;
        $this->assertInstanceOf(SettingsRow::class, $row);
        $this->assertSame('v1.0.0 → v1.1.0 available', $row->label);
        $this->assertSame('Update', $row->value);
        $this->assertArrayNotHasKey('pluginUpdate:global:current', self::rows($controller, 'current'), 'current: nothing to offer');

        $controller->changePlugin('old', 'pluginUpdate:global:old', true);
        $this->assertSame('Working…', self::rows($controller, 'old')['pluginUpdate:global:old']->value);
        $this->assertTrue(self::rows($controller, 'old')['pluginUpdate:global:old']->disabled);
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the update');
        // The fake composer records no new version, so the row stays — but no longer busy.
        $this->assertFalse(self::rows($controller, 'old')['pluginUpdate:global:old']->disabled);

        $controller->changePlugin('old', 'pluginUpdate:project:old', true);
        $this->assertStringStartsWith('Updated', $this->store->toast(), 'a key no row has does nothing');
    }

    public function testABundledPluginTakenOverOffersItsMaintainersPackage(): void
    {
        $this->store->publish('acme/porta-mago');
        $this->store->curate('{"acme/porta-mago": {"replaces": "mago"}}');
        $this->store->fill();
        $this->store->bundled('mago');
        $this->store->bundled('git');
        $controller = $this->controller();

        $offer = self::rows($controller, 'mago')['pluginTakeover'] ?? null;
        $this->assertInstanceOf(SettingsRow::class, $offer);
        $this->assertSame('Its maintainers publish acme/porta-mago', $offer->label);
        $this->assertSame('Get', $offer->value);
        $this->assertArrayNotHasKey('pluginTakeover', self::rows($controller, 'git'), 'nobody took git over');

        $controller->changePlugin('mago', 'pluginTakeover', true);
        $this->await(fn (): bool => [] !== $this->store->lifecycle->activated, what: 'the install');
        $this->assertStringEndsWith('/.porta/plugins/mago/porta.php', $this->store->lifecycle->activated[0], 'installed under the bundled name, which it shadows');
        $this->assertArrayNotHasKey('pluginTakeover', self::rows($controller, 'mago'), 'installed: the copy that loads is no longer the bundled one');

        $controller->changePlugin('git', 'pluginTakeover', true);
        $this->assertCount(1, $this->store->lifecycle->activated, 'no package replaces git: nothing installs');
    }

    public function testATakeoverThatDoesNotRunHereIsNotOffered(): void
    {
        $this->store->publish('acme/porta-mago', api: 99);
        $this->store->curate('{"acme/porta-mago": {"replaces": "mago"}}');
        $this->store->fill();
        $this->store->bundled('mago');
        $controller = $this->controller();

        $this->assertArrayNotHasKey('pluginTakeover', self::rows($controller, 'mago'), 'installed, it would shadow the working built-in copy and be declined');
        $controller->changePlugin('mago', 'pluginTakeover', true);
        $this->assertSame([], $this->store->lifecycle->activated);
        $this->assertSame('', $this->store->toast(), 'nothing started');
    }

    /** A plugin's own page leads to its page in the store — the verified one, when two share its name. */
    public function testAPluginsPageLinksToItsPageInTheStore(): void
    {
        $this->store->publish('acme/porta-lint');
        $this->store->publish('janalis/porta-lint');
        $this->store->curate('{"janalis/porta-lint": {}}');
        $this->store->fill();
        $controller = $this->controller();

        $row = self::rows($controller, 'lint')['pluginStore'] ?? null;
        $this->assertInstanceOf(SettingsRow::class, $row);
        $this->assertSame(['In the store', 'Open'], [$row->label, $row->value]);
        $this->assertArrayNotHasKey('pluginStore', self::rows($controller, 'unlisted'), 'the store does not list it: no row');

        $controller->changePlugin('lint', 'pluginStore', true);
        $this->assertEquals([new SettingsSectionRequested('plugins', PluginStoreWidget::PAGE_PREFIX.'janalis/porta-lint')], $this->requested);
        $controller->changePlugin('unlisted', 'pluginStore', true);
        $this->assertCount(1, $this->requested, 'a stale row asks for nothing');
    }
}
