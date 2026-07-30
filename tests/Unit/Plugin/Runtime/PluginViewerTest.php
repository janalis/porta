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
}
