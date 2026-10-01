<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Ide;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\NavigatesSettings;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A plugin-contributed full-screen view and top-level menu across a LIVE
 * enable/disable round-trip — no restart anywhere.
 *
 * The three formerly boot-only surfaces in one flow: a first-time enable mints
 * the region, builds the view, recompiles the layout and shows the menu; a
 * disable while the view is OPEN returns the main area to the editor, retracts
 * the view, un-mints the region and drops the menu; a re-enable builds a fresh
 * region and the view opens again.
 */
final class IdeLiveViewPluginsTest extends TestCase
{
    use ClearsPortaLsp;
    use NavigatesSettings;
    use PreservesPath;
    use RunsIde;

    private string $originalEmbedded;

    protected function setUp(): void
    {
        $this->originalEmbedded = (string) \getenv('PORTA_EMBEDDED_PLUGINS');
        \putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        \putenv('PORTA_EMBEDDED_PLUGINS='.$this->originalEmbedded);
    }

    /** An embedded fixture plugin contributing a view, a top-level menu and the toggle verb. */
    private function embeddedViewFixture(): TempProject
    {
        return new TempProject(['demo/plugin.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                use Porta\Plugin\Api\AbstractPlugin;
                use Porta\Plugin\Api\PluginApi;
                use Porta\Workbench\View\ContributedView;
                use Porta\Workbench\View\ViewServices;
                use Symfony\Component\Tui\Render\RenderContext;
                use Symfony\Component\Tui\Widget\AbstractWidget;
                use Symfony\Component\Tui\Widget\FocusableInterface;
                use Symfony\Component\Tui\Widget\FocusableTrait;
                use Symfony\Component\Tui\Widget\KeybindingsTrait;

                return new class('demo') extends AbstractPlugin {
                    public function activate(PluginApi $api): void
                    {
                        $api->registerCommand('toggle_view', 'Demo: Toggle View', static function () use ($api): void {
                            $api->toggleView('demo-view');
                        });
                        $api->registerMenu(
                            title: 'Demo',
                            after: 'Run',
                            items: ['i:demo:toggle_view'],
                            viewId: 'demo-view',
                            viewSafe: ['demo:toggle_view'],
                        );
                        $api->registerView('demo-view', static function (ViewServices $services): ContributedView {
                            $content = new class extends AbstractWidget implements FocusableInterface {
                                use FocusableTrait;
                                use KeybindingsTrait;

                                public function render(RenderContext $context): array
                                {
                                    $cols = $context->getColumns();
                                    $lines = [substr(str_pad('DEMO VIEW CONTENT', $cols), 0, $cols)];
                                    for ($row = 1; $row < $context->getRows(); ++$row) {
                                        $lines[] = str_repeat(' ', $cols);
                                    }

                                    return $lines;
                                }

                                public function handleInput(string $data): void {}
                            };
                            $content->setId('demo-view-content');
                            $services->region->content()->add($content);

                            return new class($services, $content) implements ContributedView {
                                private bool $open = false;

                                public function __construct(
                                    private readonly ViewServices $services,
                                    private readonly AbstractWidget&FocusableInterface $content,
                                ) {}

                                public function viewId(): string { return 'demo-view'; }
                                public function hostsWindow(): bool { return true; }
                                public function title(): string { return 'Demo'; }
                                public function isOpen(): bool { return $this->open; }

                                public function open(): void
                                {
                                    $this->open = true;
                                    $this->services->host->show($this->services->region, 'demo-view');
                                    $this->services->host->focus($this->content);
                                }

                                public function close(): void
                                {
                                    if (!$this->open) { return; }
                                    $this->open = false;
                                    $this->services->host->restore();
                                }

                                public function toggle(): void
                                {
                                    $this->open ? $this->close() : $this->open();
                                }

                                public function focusTarget(): AbstractWidget&FocusableInterface { return $this->content; }
                                public function pointer(): ?\Porta\Workbench\View\ViewPointer { return null; }
                                public function contextMenus(): ?\Porta\Workbench\Menu\ViewMenuSource { return null; }
                                public function cycleFocus(int $direction): bool { return false; }
                            };
                        });
                    }
                };
                PHP]);
    }

    /** @return list<string> the menu bar's top-level titles */
    private function menuTitles($tui): array
    {
        return \array_column(Probe::get($tui->getById('menu'), 'menus'), 'title');
    }

    public function testTogglingFromTheRealSettingsScreenBuildsAndRetractsTheView(): void
    {
        // The same lifecycle as the test below, but driven the way a USER
        // does it: keystrokes into Settings → Plugins → the plugin's own page
        // → the Enabled row. What this adds is the wiring between the form and
        // the lifecycle — a view that only appears when the API is called
        // directly would pass every other test in this file and still be dead
        // on the screen the user actually touches.
        $embedded = $this->embeddedViewFixture();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => (string) \json_encode(['enabledPlugins' => []])]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $views = Services::resolve($ide, ViewRegistry::class);
                    $regions = Services::resolve($ide, ViewRegions::class);
                    $this->frame($tui);
                    $this->assertNull($views->find('demo-view'), 'off at boot');

                    // Settings → Plugins → the demo plugin's own sub-item.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $this->frame($tui); // the view's widgets attach on the next frame
                    $tui->handleInput("\t"); // form → nav
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugin:demo');
                    $tui->handleInput("\t"); // nav → form, landing on the Enabled row
                    $this->frame($tui);

                    // Enter on the Enabled row is the whole gesture.
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Plugin "demo" enabled');

                    $this->assertNotNull($views->find('demo-view'), 'the view was built from the Settings screen');
                    $this->assertInstanceOf(ViewRegion::class, $regions->find('demo-view'), 'and its region minted');
                    $config = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true);
                    $this->assertSame(['demo'], \is_array($config) ? $config['enabledPlugins'] ?? null : null, 'and the choice persisted');

                    // Leave the settings view — through the same command that
                    // opened it, which is what actually toggles it; Escape is
                    // the form's key, not the view's — and confirm the
                    // contributed group reached the bar.
                    $settings = static fn (): mixed => Services::resolve($ide, CommandRegistry::class)->run('ide_settings');
                    $settings();
                    $this->frame($tui);
                    $this->assertFalse($views->isOpen('settings'), 'back to the editor');
                    $this->assertContains('Demo', $this->menuTitles($tui), 'the contributed menu is on the bar');

                    // Back to the same row and toggle it off.
                    $settings();
                    $this->frame($tui);
                    $tui->handleInput("\t");
                    $this->selectSettingsSection($tui->getById('settings-nav'), 'plugin:demo');
                    $tui->handleInput("\t");
                    $tui->handleInput("\r");
                    $this->awaitPainted($tui, $terminal, 'Plugin "demo" disabled');

                    $this->assertNull($views->find('demo-view'), 'and retracted from the same screen');
                    $this->assertNull($regions->find('demo-view'));
                    $settings();
                    $this->frame($tui);
                    $this->assertNotContains('Demo', $this->menuTitles($tui));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAViewPluginTogglesLiveThroughItsWholeLifecycle(): void
    {
        $embedded = $this->embeddedViewFixture();
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => (string) \json_encode(['enabledPlugins' => []])]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function ($tui, VirtualTerminal $terminal, Ide $ide): void {
                    $views = Services::resolve($ide, ViewRegistry::class);
                    $regions = Services::resolve($ide, ViewRegions::class);
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $commands = Services::resolve($ide, CommandRegistry::class);

                    // Disabled at boot: none of the three surfaces exists.
                    $this->frame($tui);
                    $this->assertNull($views->find('demo-view'));
                    $this->assertNull($regions->find('demo-view'));
                    $this->assertNotContains('Demo', $this->menuTitles($tui));

                    // ENABLE: view, region, menu and command all arrive live.
                    $lifecycle->enable('demo');
                    $this->frame($tui);
                    $this->assertNotNull($views->find('demo-view'), 'a first-time enable builds the view');
                    $firstRegion = $regions->find('demo-view');
                    $this->assertNotNull($firstRegion, 'and mints its region');
                    $this->assertContains('Demo', $this->menuTitles($tui), 'the top-level menu arrived');

                    // Open it: the region renders, which proves the layout
                    // recompiled — an unmounted region is a blank workspace.
                    $commands->run('demo:toggle_view');
                    $this->awaitPainted($tui, $terminal, 'DEMO VIEW CONTENT');
                    $this->assertTrue($views->isOpen('demo-view'));

                    // DISABLE while the view is open: the editor gets the main
                    // area back, and every surface retracts.
                    $lifecycle->disable('demo');
                    $this->frame($tui);
                    $this->assertNull($views->find('demo-view'), 'the view is retracted');
                    $this->assertNull($regions->find('demo-view'), 'the region is un-minted');
                    $this->assertNotContains('Demo', $this->menuTitles($tui), 'the menu died with it');
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the editor got the main area and the focus back');

                    // RE-ENABLE: a fresh region, and the view opens again.
                    $lifecycle->enable('demo');
                    $this->frame($tui);
                    $this->assertNotNull($views->find('demo-view'));
                    $this->assertNotSame($firstRegion, $regions->find('demo-view'), 'a fresh region, not the dead one');
                    $commands->run('demo:toggle_view');
                    $this->awaitPainted($tui, $terminal, 'DEMO VIEW CONTENT');
                    $this->assertTrue($views->isOpen('demo-view'), 're-enable round-trips to a working view');
                },
            ],
        );
    }

    public function testAThrowingViewFactoryLeavesNoOrphanRegion(): void
    {
        // The mint happens BEFORE the factory runs (a view fills its panes
        // while being constructed), so a failed factory used to leak a minted,
        // empty region — a blank workspace with no error anywhere.
        $embedded = new TempProject(['demo/plugin.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                use Porta\Plugin\Api\AbstractPlugin;
                use Porta\Plugin\Api\PluginApi;
                use Porta\Workbench\View\ContributedView;
                use Porta\Workbench\View\ViewServices;

                return new class('demo') extends AbstractPlugin {
                    public function activate(PluginApi $api): void
                    {
                        $api->registerView('demo-view', static fn (ViewServices $services): ContributedView => throw new \RuntimeException('no view today'));
                    }
                };
                PHP]);
        \putenv('PORTA_EMBEDDED_PLUGINS='.$embedded->dir);
        $project = new TempProject(['a.php' => "<?php\n", '.porta.json' => (string) \json_encode(['enabledPlugins' => []])]);

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    Services::resolve($ide, PluginLifecycle::class)->enable('demo');

                    $this->awaitPainted($tui, $terminal, 'Plugin "demo" failed');
                    $this->assertNull(Services::resolve($ide, ViewRegistry::class)->find('demo-view'));
                    $this->assertNull(Services::resolve($ide, ViewRegions::class)->find('demo-view'), 'the failed mint is rolled back');
                },
            ],
        );
    }
}
