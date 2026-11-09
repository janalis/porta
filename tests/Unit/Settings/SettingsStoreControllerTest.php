<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Config\ConfigFiles;
use Porta\Config\ConfigScope;
use Porta\Event\SettingsSectionRequested;
use Porta\Plugin\Lifecycle\InstallScope;
use Porta\Plugin\Runtime\PluginLoader;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreArt;
use Porta\Plugin\Store\StoreAssets;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Settings\SettingsScope;
use Porta\Settings\SettingsStoreController;
use Porta\Tests\Support\PngFixture;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StoreFixture;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** The store page's buttons: which one each plugin gets, and what pressing it does. */
final class SettingsStoreControllerTest extends TestCase
{
    use PumpsLoop;

    private StoreFixture $store;

    /** @var list<string> the settings pages asked for */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->store = new StoreFixture();
    }

    private function controller(?StoreFixture $store = null, ?PluginLoader $loader = null): SettingsStoreController
    {
        $store ??= $this->store;
        $scope = new SettingsScope(ConfigFiles::for($store->project->dir));
        $scope->set(ConfigScope::Global);
        $events = new EventDispatcher();
        $events->addListener(SettingsSectionRequested::class, function (SettingsSectionRequested $event): void {
            $this->requested[] = $event->section.'#'.$event->row;
        });

        $assets = new StoreAssets($store->http->fetch(), null);

        return new SettingsStoreController(
            $store->catalog,
            $assets,
            new StoreArt($store->http->fetch(), $assets, null),
            $store->updates(),
            $store->installer,
            $store->lifecycle,
            $store->notifier,
            $store->translator,
            $scope,
            $events,
            $loader,
        );
    }

    /** @return array<string, StoreAction> package => its button */
    private static function buttons(SettingsStoreController $controller): array
    {
        $buttons = [];
        foreach ($controller->rows() as $row) {
            $buttons[$row->entry->package] = $row->action;
        }

        return $buttons;
    }

    private function entry(SettingsStoreController $controller, string $package): StoreEntry
    {
        $row = \array_find($controller->rows(), static fn (StoreRow $row): bool => $package === $row->entry->package);
        $this->assertInstanceOf(StoreRow::class, $row, $package.' is listed');

        return $row->entry;
    }

    public function testEachPluginsArtIsLookedForAcrossTheCatalog(): void
    {
        $this->store->publish('acme/porta-rector', store: ['icon' => 'https://img.test/icon.png']);
        $this->store->publish('acme/porta-other');
        $this->store->http->bodies['https://img.test/icon.png'] = PngFixture::bytes(40, 40);
        $this->store->fill();
        $found = [];
        $this->controller()->art(static function (string $package, Art $art) use (&$found): void {
            $found[$package] = null !== $art->icon;
        });

        $this->assertSame(['acme/porta-other' => false, 'acme/porta-rector' => true], $found, 'every entry, in the order of the shelves');
    }

    public function testEachPluginGetsTheButtonForHowItStandsHere(): void
    {
        foreach (['acme/porta-new', 'acme/porta-current', 'janalis/porta-git', 'janalis/porta-todo', 'acme/porta-shadow'] as $package) {
            $this->store->publish($package);
        }
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->publish('acme/porta-future', api: 99);
        $this->store->publish('other/porta-current');
        $this->store->publish('acme/porta-git');
        $this->store->fill();
        $this->store->installed('acme/porta-current', 'v1.0.0');
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->store->bundled('git');
        $this->store->bundled('todo');
        $this->store->lifecycle->disabledNames = ['todo'];
        $controller = $this->controller();

        $buttons = self::buttons($controller);
        \ksort($buttons);
        $this->assertSame(
            [
                'acme/porta-current' => StoreAction::Open,
                'acme/porta-future' => StoreAction::Incompatible,
                'acme/porta-git' => StoreAction::Get,
                'acme/porta-new' => StoreAction::Get,
                'acme/porta-old' => StoreAction::Update,
                'acme/porta-shadow' => StoreAction::Get,
                'janalis/porta-git' => StoreAction::BuiltIn,
                'janalis/porta-todo' => StoreAction::Enable,
                'other/porta-current' => StoreAction::Get,
            ],
            $buttons,
            'another package of an installed name, or a takeover beside a bundled one, is Get',
        );

        $old = \array_find($controller->rows(), static fn (StoreRow $row): bool => 'acme/porta-old' === $row->entry->package);
        $this->assertSame('v1.0.0', $old?->installed);
        $this->assertSame('v1.1.0', $old->update?->to);
        $this->assertCount(1, $controller->outdated());
    }

    public function testAnUnverifiedPluginAsksTwiceThenInstallsAndSwitchesOn(): void
    {
        $this->store->publish('acme/porta-new', store: ['title' => 'New']);
        $this->store->fill();
        $controller = $this->controller();
        $entry = $this->entry($controller, 'acme/porta-new');

        $controller->press($entry);
        $this->assertStringContainsString('"acme/porta-new" is not verified by Porta', $this->store->toast());
        $this->assertSame([], $this->store->http->argvs[2] ?? [], 'nothing installed yet');

        $controller->press($entry);
        $this->assertSame('Installing New…', $this->store->toast());
        $this->assertSame(StoreAction::Working, self::buttons($controller)['acme/porta-new']);
        $this->await(fn (): bool => 'New installed' === $this->store->toast(), what: 'the install');
        $this->assertStringEndsWith('/.porta/plugins/new/porta.php', $this->store->lifecycle->activated[0], 'into the global scope the view is on, and switched on');
        $this->assertSame(StoreAction::Open, self::buttons($controller)['acme/porta-new']);
    }

    public function testASecondGetWhileOneInstallsLeavesTheFirstWorking(): void
    {
        $this->store->publish('acme/porta-a');
        $this->store->publish('acme/porta-b');
        $this->store->curate('{"acme/porta-a": {}, "acme/porta-b": {}}');
        $this->store->fill();
        $controller = $this->controller();

        $controller->press($this->entry($controller, 'acme/porta-a'));
        $controller->press($this->entry($controller, 'acme/porta-b')); // refused: one install at a time
        $this->assertSame('Another install is already running', $this->store->toast());
        $this->assertSame(StoreAction::Working, self::buttons($controller)['acme/porta-a'], 'the refusal is B\'s, not A\'s');
        $this->assertSame(StoreAction::Get, self::buttons($controller)['acme/porta-b']);
        $this->await(fn (): bool => [] !== $this->store->lifecycle->activated, what: 'A\'s install');
    }

    public function testAVerifiedPluginInstallsAtOnceAndAFailureIsSaid(): void
    {
        $failing = new StoreFixture(composerExit: 1);
        $failing->publish('acme/porta-new');
        $failing->curate('{"acme/porta-new": {}}');
        $failing->fill();
        $controller = $this->controller($failing);

        $controller->press($this->entry($controller, 'acme/porta-new'));
        $this->await(static fn (): bool => \str_contains($failing->toast(), 'composer require failed'), what: 'the failed install');
        $this->assertSame([], $failing->lifecycle->activated);
    }

    public function testOpenEnableAndUpdateGoWhereTheyShould(): void
    {
        $this->store->publish('acme/porta-current');
        $this->store->publish('janalis/porta-git');
        $this->store->publish('janalis/porta-todo');
        $this->store->publish('acme/porta-old', 'v1.1.0');
        $this->store->publish('acme/porta-future', api: 99);
        $this->store->fill();
        $this->store->installed('acme/porta-current', 'v1.0.0');
        $this->store->installed('acme/porta-old', 'v1.0.0');
        $this->store->bundled('git');
        $this->store->bundled('todo');
        $this->store->lifecycle->disabledNames = ['todo'];
        $controller = $this->controller();

        $controller->press($this->entry($controller, 'acme/porta-current'));
        $controller->press($this->entry($controller, 'janalis/porta-git'));
        $this->assertSame(['plugin:current#pluginEnabled', 'plugin:git#pluginEnabled'], $this->requested);

        $controller->press($this->entry($controller, 'janalis/porta-todo'));
        $this->assertSame([['todo', true]], $this->store->lifecycle->toggled);

        $controller->press($this->entry($controller, 'acme/porta-future'));
        $controller->press(new StoreEntry('nobody/porta-x', 'x', 'X', '', '', StoreCategory::Other, '1'));
        $this->assertSame('', $this->store->toast(), 'an inert button and an unlisted plugin do nothing');

        $controller->press($this->entry($controller, 'acme/porta-old'));
        $this->assertSame(StoreAction::Working, self::buttons($controller)['acme/porta-old']);
        $this->await(fn (): bool => \str_starts_with($this->store->toast(), 'Updated'), what: 'the update');

        $this->store->publish('acme/porta-current', 'v2.0.0');
        $this->store->fill();
        $controller->updateAll();
        $this->await(fn (): bool => \str_contains($this->store->toast(), 'current v2.0.0'), what: 'update all');
    }

    public function testShowingTheStoreRefetchesWhatIsOldAndSaysWhenItCannot(): void
    {
        $this->store->publish('acme/porta-new');
        $controller = $this->controller();

        $controller->shown();
        $this->assertSame(['acme/porta-new'], \array_map(static fn (StoreRow $row): string => $row->entry->package, $controller->rows()));
        $this->assertSame(1, $this->store->lifecycle->refreshed);
        $controller->shown();
        $this->assertCount(2, $this->store->http->batches, 'fresh: not asked again');
        $this->assertFalse($controller->refreshing());

        $empty = new StoreFixture();
        $offline = $this->controller($empty);
        $offline->shown();
        $this->assertSame('The plugin store could not reach Packagist: showing what it saw last', $empty->toast());
    }

    public function testAPageAsksForItsTextsAndPictures(): void
    {
        $this->store->http->bodies = ['https://t.test/a.md' => 'text', 'https://t.test/a.png' => "\x89PNG\r\n\x1a\n"];
        $controller = $this->controller();
        $answers = [];
        $collect = static function (string $url, ?string $body) use (&$answers): void {
            $answers[$url] = $body;
        };
        $controller->asset('https://t.test/a.md', false, $collect);
        $controller->asset('https://t.test/a.png', true, $collect);
        $controller->asset('https://t.test/a.md', true, $collect);

        $this->assertSame(['https://t.test/a.md' => 'text', 'https://t.test/a.png' => "\x89PNG\r\n\x1a\n"], $answers);
    }

    public function testAnUntrustedProjectsOwnCopyIsNotWhatTheStoreDescribes(): void
    {
        $this->store->publish('acme/porta-one');
        $this->store->fill();
        $this->store->installed('acme/porta-one', 'v1.0.0', InstallScope::Project);
        $loader = new PluginLoader($this->store->project->dir, $this->store->home->dir, new PluginSettings($this->store->project->dir, []), trusted: false);

        $this->assertSame(StoreAction::Get, self::buttons($this->controller(loader: $loader))['acme/porta-one']);
        $this->assertSame(StoreAction::Open, self::buttons($this->controller())['acme/porta-one']);
    }
}
