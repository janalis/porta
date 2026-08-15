<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\PaneServices;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginMenuSpec;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\TestPane;
use Porta\Widget\EditorPaneWidget;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Plugin-provided viewer routes: registration, claim normalization, load
 * order, the fault barrier and live removal — the routing seam behind the
 * bundled image-viewer/graph-viewer plugins.
 */
final class PluginViewerTest extends TestCase
{
    private function manager(): PluginManager
    {
        return new PluginManager(new EventDispatcher(), new PluginSettings('/p', []));
    }

    private function api(PluginManager $manager, string $name): PluginApi
    {
        return new PluginApi($name, $manager, '/p', new PluginSettings('/p', []));
    }

    public function testExtensionListsClaimCaseInsensitively(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'image-viewer');
        $api->registerViewer('image', ['png', 'JPG']);
        $manager->commit($api);

        $this->assertSame('image', $manager->viewerFor('/p/photo.png'));
        $this->assertSame('image', $manager->viewerFor('/p/PHOTO.PNG'), 'extension match is case-insensitive');
        $this->assertSame('image', $manager->viewerFor('/p/pic.jpg'));
        $this->assertNull($manager->viewerFor('/p/readme.md'));
        $this->assertNull($manager->viewerFor('/p/png'), 'no extension, no claim');
    }

    public function testClosureClaimsAndLoadOrderFirstWins(): void
    {
        $manager = $this->manager();
        $one = $this->api($manager, 'one');
        $one->registerViewer('hex', static fn (string $path): bool => str_ends_with($path, '.bin'));
        $manager->commit($one);
        $two = $this->api($manager, 'two');
        $two->registerViewer('image', ['bin']);
        $manager->commit($two);

        $this->assertSame('hex', $manager->viewerFor('/p/data.bin'), 'first claiming plugin in load order wins');
    }

    public function testAThrowingClaimDeclinesAndReportsOnce(): void
    {
        $manager = $this->manager();
        $broken = $this->api($manager, 'broken');
        $broken->registerViewer('boom', static fn (string $path): bool => throw new \RuntimeException('claim exploded'));
        $manager->commit($broken);
        $sane = $this->api($manager, 'sane');
        $sane->registerViewer('image', ['png']);
        $manager->commit($sane);

        $this->assertSame('image', $manager->viewerFor('/p/a.png'), 'the throw declines, the next route still answers');
        $this->assertNull($manager->viewerFor('/p/b.txt'));
        $warnings = $manager->warnings();
        $this->assertCount(1, $warnings, 'reported once, not per open');
        $this->assertSame('broken', $warnings[0]->params['%name%']);
    }

    public function testDeactivateDropsTheRoutesLive(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'image-viewer');
        $api->registerViewer('image', ['png']);
        $manager->commit($api);
        $this->assertSame('image', $manager->viewerFor('/p/a.png'));

        $manager->deactivate('image-viewer', new CommandRegistry());
        $this->assertNull($manager->viewerFor('/p/a.png'), 'a disabled plugin routes nothing');
    }

    public function testAPaneRegistrationCommitsAndDeactivatesWithItsPlugin(): void
    {
        // The claim says which files are mine; the pane says what shows them.
        // Both must die together when the plugin is disabled, or a disabled
        // viewer still owns the editor slot for its extensions.
        $manager = $this->manager();
        $api = $this->api($manager, 'image-viewer');
        $api->registerViewer('image', ['png']);
        $api->registerPane('image', static fn (PaneServices $services): EditorPaneWidget => new TestPane('image'), static fn (string $path): string => 'bytes');
        $manager->commit($api);
        $this->assertCount(1, $manager->panes());
        $this->assertSame('image', $manager->panes()[0]['id']);
        $this->assertSame('image-viewer', $manager->panes()[0]['plugin']);

        $manager->deactivate('image-viewer', new CommandRegistry());

        $this->assertSame([], $manager->panes(), 'the pane goes with the claim');
    }

    public function testAPaneIdIsValidatedLikeEveryOtherId(): void
    {
        $api = $this->api($this->manager(), 'p');

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Invalid pane id/');
        $api->registerPane('BAD ID!', static fn (PaneServices $services): EditorPaneWidget => new TestPane(), static fn (string $path): string => '');
    }

    public function testRegistrationValidation(): void
    {
        $api = $this->api($this->manager(), 'p');
        try {
            $api->registerViewer('BAD ID!', ['png']);
            $this->fail('an invalid id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid viewer id', $e->getMessage());
        }
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/at least one file extension/');
        $api->registerViewer('ok', []);
    }

    public function testAPluginsActionsReachTheRegistryAndLeaveWithIt(): void
    {
        // A pane's keys are listed on Settings → Keymap, so they must appear
        // when the plugin loads and disappear when it is removed — a keymap row
        // for a pane that no longer exists is a rebind that binds nothing.
        $manager = $this->manager();
        $api = $this->api($manager, 'image-viewer');
        $api->registerActions(['image_zoom_in' => 'Image: Zoom In', 'image_pan_up' => 'Image: Pan Up']);
        $manager->commit($api);

        $other = $this->api($manager, 'graph-viewer');
        $other->registerActions(['graph_zoom_in' => 'Graph: Zoom In']);
        $manager->commit($other);

        $this->assertSame(
            [
                'image_zoom_in' => 'Image: Zoom In',
                'image_pan_up' => 'Image: Pan Up',
                'graph_zoom_in' => 'Graph: Zoom In',
            ],
            $manager->rebindableActions(),
            'load order, ids to labels',
        );

        $manager->deactivate('image-viewer', new CommandRegistry());

        $this->assertSame(['graph_zoom_in' => 'Graph: Zoom In'], $manager->rebindableActions());
    }

    public function testAnActionIdIsValidatedAndMayNotShadowACoreAction(): void
    {
        $api = $this->api($this->manager(), 'p');
        try {
            $api->registerActions(['BAD ID!' => 'Nope']);
            $this->fail('an invalid id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid action id', $e->getMessage());
        }

        // The catalog collision is refused at registration rather than resolved
        // later: a plugin that thinks it owns "save" should hear so at boot,
        // not silently lose to core on the keymap page.
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/"save" is a core action/');
        $api->registerActions(['save' => 'Demo: Save']);
    }

    public function testAPluginsListToolsReachTheDockGuardedAndLeaveWithThePlugin(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'demo');
        $api->registerListTool(
            'todo',
            'TODO',
            rerun: static function (): void {
                throw new \RuntimeException('scan exploded');
            },
            activate: static function (string $path, int $line): void {
                throw new \RuntimeException('jump exploded');
            },
        );
        $api->registerListTool('bare', 'Bare');
        $manager->commit($api);

        $tools = $manager->listTools();
        $this->assertSame(['demo:todo', 'demo:bare'], array_column($tools, 'id'), 'namespaced, in registration order');
        $this->assertSame(['TODO', 'Bare'], array_column($tools, 'title'));
        $this->assertNull($tools[1]['rerun'], 'an undeclared F5 stays null rather than becoming a no-op closure');
        $this->assertNull($tools[1]['activate']);

        // Both closures arrive guarded — a throwing tool must not kill the loop,
        // and the consumer cannot forget to wrap what it is handed.
        $this->assertNotNull($tools[0]['rerun']);
        $this->assertNotNull($tools[0]['activate']);
        $tools[0]['rerun']();
        $line = 1;
        $tools[0]['activate']('a.php', $line);
        $this->assertCount(2, $manager->warnings(), 'both faults were reported, neither escaped');

        $manager->deactivate('demo', new CommandRegistry());
        $this->assertSame([], $manager->listTools(), 'a removed plugin takes its tools with it');
    }

    public function testListToolRegistrationIsValidated(): void
    {
        $api = $this->api($this->manager(), 'p');
        try {
            $api->registerListTool('BAD ID!', 'Nope');
            $this->fail('an invalid id must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('Invalid list tool id', $e->getMessage());
        }
        try {
            $api->registerListTool('ok', '  ');
            $this->fail('a blank title must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('needs a title', $e->getMessage(), 'the title is the strip label — there is no fallback');
        }

        $api->registerListTool('ok', 'Ok');
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Duplicate list tool id "ok"/');
        $api->registerListTool('ok', 'Ok again');
    }

    public function testAdoptionTakesACoreIdCoreHasVacatedAndRefusesEverythingElse(): void
    {
        // What makes a core feature movable without breaking a user's keymap:
        // the plugin registers under core's BARE id, with core's label, and no
        // keys of its own — an adoptable id is IDE-scope, so its default key is
        // already in the map ActionCatalog::ideDefaults() builds.
        $manager = $this->manager();
        $api = $this->api($manager, 'todo');
        $api->adoptCommand('ide_todo', static function (): void {});
        $manager->commit($api);
        $registry = new CommandRegistry();
        $manager->registerCommands($registry);

        $this->assertSame('Show TODOs', $registry->label('ide_todo'), "core's label, not the plugin's");
        $this->assertContains('ide_todo', $registry->keyBoundIds(), 'and it dispatches under the bare id');
        $this->assertSame([], $manager->defaultKeys(), 'no plugin default keys: the catalog already binds alt+8');

        // The guarantee: only ids core has marked adoptable can be taken, so a
        // third-party plugin cannot quietly become "save".
        try {
            $this->api($manager, 'greedy')->adoptCommand('save', static function (): void {});
            $this->fail('a core-implemented id must not be adoptable');
        } catch (PluginException $e) {
            $this->assertStringContainsString('not adoptable', $e->getMessage());
        }
        try {
            $this->api($manager, 'greedy')->adoptCommand('no_such_action', static function (): void {});
            $this->fail('an id no catalog entry names must throw');
        } catch (PluginException $e) {
            $this->assertStringContainsString('not adoptable', $e->getMessage());
        }

        $twice = $this->api($manager, 'twice');
        $twice->adoptCommand('ide_todo', static function (): void {});
        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Duplicate command id "ide_todo"/');
        $twice->adoptCommand('ide_todo', static function (): void {});
    }

    public function testStatusSegmentsAreEvaluatedGuardedAndReportedOnce(): void
    {
        // This is pulled once per RENDER, which is what shapes the contract: a
        // throwing closure must not kill the loop and must not flash on every
        // frame, and an empty segment must not reserve space in the bar.
        $manager = $this->manager();
        $api = $this->api($manager, 'demo');
        $state = 'building';
        $api->registerStatusSegment(static function () use (&$state): ?string {
            return $state;
        });
        $api->registerStatusSegment(static fn (): ?string => null);
        $api->registerStatusSegment(static fn (): string => '');
        $api->registerStatusSegment(static fn (): string => throw new \RuntimeException('segment exploded'));
        $manager->commit($api);

        $this->assertSame(['building'], $manager->statusSegments(), 'null and empty contribute nothing');
        $this->assertCount(1, $manager->warnings(), 'the throwing one was reported');

        $state = 'done';
        $this->assertSame(['done'], $manager->statusSegments(), 'the closure is re-evaluated, not memoised');
        $this->assertCount(1, $manager->warnings(), 'and the same fault is not reported again');

        $manager->deactivate('demo', new CommandRegistry());
        $this->assertSame([], $manager->statusSegments(), 'a removed plugin takes its segment with it');
    }

    public function testAContributedMenuTravelsWithItsViewSafeListAndLeavesWithItsPlugin(): void
    {
        // A top-level group of its own, rather than an entry in the trailing
        // Plugins menu — the git group is the case that needed it. Both halves
        // are one call because a menu without its view-safe list greys out its
        // own items the moment its view opens.
        $manager = $this->manager();
        $api = $this->api($manager, 'vcs');
        $api->registerMenu('Git', 'Run', ['i:git_commit'], viewId: 'git', viewSafe: ['ide_quit']);
        $manager->commit($api);

        $menus = $manager->contributedMenus();
        self::assertCount(1, $menus);
        $this->assertSame('Git', $menus[0]->title);
        $this->assertSame('Run', $menus[0]->after);
        $this->assertSame(['ide_quit'], $menus[0]->viewSafe);

        $manager->deactivate('vcs', new CommandRegistry());
        $this->assertSame([], $manager->contributedMenus(), 'a removed plugin takes its menu with it');
    }

    public function testASettingChangeReachesOnlyThePluginThatOwnsIt(): void
    {
        // A plugin has no business seeing another's configuration, and a
        // listener that had to filter would be a listener that could forget
        // to. Without this hook a setting read once at activate() is
        // restart-required by construction.
        $manager = $this->manager();
        $mine = [];
        $theirs = [];
        $vcs = $this->api($manager, 'vcs');
        $vcs->onSettingChanged(static function (string $key, bool|int|string $value) use (&$mine): void {
            $mine[] = [$key, $value];
        });
        $manager->commit($vcs);
        $todo = $this->api($manager, 'todo');
        $todo->onSettingChanged(static function (string $key, bool|int|string $value) use (&$theirs): void {
            $theirs[] = [$key, $value];
        });
        $manager->commit($todo);

        $manager->notifySettingChanged('vcs', 'gutterMarks', false);

        $this->assertSame([['gutterMarks', false]], $mine);
        $this->assertSame([], $theirs, 'the other plugin saw nothing');
    }

    public function testAThrowingSettingListenerIsReportedAndDoesNotStopTheOthers(): void
    {
        // This runs from the Settings page, so a faulting listener must not
        // take the keystroke that changed the row down with it.
        $manager = $this->manager();
        $seen = 0;
        $api = $this->api($manager, 'vcs');
        $api->onSettingChanged(static fn (string $key, bool|int|string $value) => throw new \RuntimeException('listener exploded'));
        $api->onSettingChanged(static function () use (&$seen): void {
            ++$seen;
        });
        $manager->commit($api);

        $manager->notifySettingChanged('vcs', 'gutterMarks', false);

        $this->assertSame(1, $seen, 'the second listener still ran');
        $this->assertCount(1, $manager->warnings(), 'and the fault was reported');
    }

    public function testARemovedPluginTakesItsSettingListenerWithIt(): void
    {
        $manager = $this->manager();
        $seen = 0;
        $api = $this->api($manager, 'vcs');
        $api->onSettingChanged(static function () use (&$seen): void {
            ++$seen;
        });
        $manager->commit($api);

        $manager->deactivate('vcs', new CommandRegistry());
        $manager->notifySettingChanged('vcs', 'gutterMarks', false);

        $this->assertSame(0, $seen);
    }

    public function testATitlelessMenuIsRefused(): void
    {
        // The menu bar renders a title; an empty one is a group nothing can
        // open, sitting in the bar as a gap. Refused where it is declared
        // rather than skipped at render, so the plugin's own barrier reports it.
        $api = $this->api($this->manager(), 'vcs');

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/needs a title/');
        $api->registerMenu('', 'Run', ['i:git_commit']);
    }

    public function testASegmentMayBeAWholeCellAndIsGuardedTheSameWay(): void
    {
        // A tone and a right-click menu do not survive being joined into the
        // shared text cell, which is what the git branch indicator needs now
        // that git contributes it from outside src/. The text guard still
        // applies: a cell with nothing in it reserves no space either.
        $manager = $this->manager();
        $api = $this->api($manager, 'vcs');
        $api->registerStatusSegment(static fn (): StatusCell => new StatusCell('⎇ main', StatusTone::Accent));
        $api->registerStatusSegment(static fn (): StatusCell => new StatusCell(''));
        $manager->commit($api);

        $segments = $manager->statusSegments();
        self::assertCount(1, $segments, 'the empty cell contributes nothing');
        self::assertInstanceOf(StatusCell::class, $segments[0]);
        $this->assertSame('⎇ main', $segments[0]->text);
        $this->assertSame(StatusTone::Accent, $segments[0]->tone);
    }

    public function testDebugAdaptersResolveLikeLanguageServersDo(): void
    {
        $manager = $this->manager();
        $api = $this->api($manager, 'php-debug');
        $api->registerDebugAdapter('php', static fn (): array => ['php', '-dxdebug.mode=debug', 'adapter']);
        $api->registerDebugAdapter('rust', static fn (): ?array => null); // binary not installed
        $api->registerDebugAdapter('go', static fn (): array => []); // likewise, spelled the other way
        $api->registerDebugAdapter('ruby', static fn (): array => throw new \RuntimeException('resolver exploded'));
        $manager->commit($api);

        $second = $this->api($manager, 'other-debug');
        $second->registerDebugAdapter('php', static fn (): array => ['mine', 'instead']);
        $manager->commit($second);

        $this->assertSame(['php' => ['php', '-dxdebug.mode=debug', 'adapter']], $manager->debugAdapters(), 'unavailable and throwing resolvers contribute nothing; first plugin wins the language');
        $this->assertCount(1, $manager->warnings());

        $manager->deactivate('php-debug', new CommandRegistry());
        $this->assertSame(['php' => ['mine', 'instead']], $manager->debugAdapters(), 'removing the first hands the language to the next');
    }

    public function testADebugAdaptersLanguageIdIsValidated(): void
    {
        $api = $this->api($this->manager(), 'p');

        $this->expectException(PluginException::class);
        $this->expectExceptionMessageMatches('/Invalid language id/');
        $api->registerDebugAdapter('BAD ID!', static fn (): array => ['x']);
    }

    public function testAMenuItemCarriesItsSurfaceAndAnAdoptedIdStaysBare(): void
    {
        // Two facts in one: a spec knows which menu it is for, and an ADOPTED
        // id is registered bare (it is core's), so prefixing its spec would
        // name a command nothing answers to.
        $manager = $this->manager();
        $api = $this->api($manager, 'demo');
        $api->registerCommand('own', 'Own', static function (): void {});
        $api->addMenuItem('own');
        $api->adoptCommand('ide_todo', static function (): void {});
        $api->addMenuItem('ide_todo', surface: MenuSurface::EditorContext);
        $manager->commit($api);

        $this->assertSame(['i:demo:own'], array_map(static fn (PluginMenuSpec $s): string => $s->spec, $manager->menuSpecs()));
        $this->assertSame(['i:ide_todo'], array_map(static fn (PluginMenuSpec $s): string => $s->spec, $manager->menuSpecs(MenuSurface::EditorContext)), 'bare, because core owns the id');

        $manager->deactivate('demo', new CommandRegistry());
        $this->assertSame([], $manager->menuSpecs(MenuSurface::EditorContext), 'a removed plugin takes its context item with it');
    }
}
