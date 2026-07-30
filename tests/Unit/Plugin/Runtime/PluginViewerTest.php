<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Runtime;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\PaneServices;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Api\PluginException;
use Porta\Plugin\Runtime\PluginEvents;
use Porta\Plugin\Runtime\PluginManager;
use Porta\Plugin\Runtime\PluginSettings;
use Porta\Tests\Support\TestPane;
use Porta\Widget\EditorPaneWidget;

/**
 * Plugin-provided viewer routes: registration, claim normalization, load
 * order, the fault barrier and live removal — the routing seam behind the
 * bundled image-viewer/graph-viewer plugins.
 */
final class PluginViewerTest extends TestCase
{
    private function manager(): PluginManager
    {
        return new PluginManager(new PluginEvents(), new PluginSettings('/p', []));
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
        $this->assertStringContainsString('broken', $warnings[0]);
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
}
