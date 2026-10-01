<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\LayoutController;
use Porta\Layout\PreviewSplit;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Ui\CursorStyle;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ContributedViewSource;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistrar;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\ViewServices;
use Porta\Workbench\View\ViewWindow;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The drain that turns a plugin's registerView() into a live view — the whole
 * of it at boot, one plugin's slice on enable, and the retraction on disable.
 *
 * The `PaneRegistrar` shape, and its failure contract too: a factory that
 * throws costs its own plugin its view and leaves every other one registered.
 * Worth testing directly because a view that never registers is silent — the
 * command that would open it finds nothing under that id and the workspace
 * simply never swaps.
 */
final class ViewRegistrarTest extends TestCase
{
    /** @var list<array{plugin: string, error: \Throwable}> */
    private array $reported = [];

    private TempProject $project;
    private ContainerWidget $main;

    /** @param list<array{plugin: string, id: string, factory: \Closure}> $views */
    private function registrar(array $views, ViewRegistry $registry, ViewRegions $regions): ViewRegistrar
    {
        $this->project = new TempProject();
        $tui = TestTui::create(100, 24);
        $renderer = new Renderer(new StyleSheet());
        $translator = new Translator('en');
        $tree = new FileTreeWidget($this->project->dir);
        $this->main = new ContainerWidget();

        $reported = &$this->reported;
        $source = new class($views, $reported) implements ContributedViewSource {
            /** @param list<array{plugin: string, id: string, factory: \Closure}> $views */
            public function __construct(
                private readonly array $views,
                private array &$reported,
            ) {}

            public function contributedViews(): array
            {
                return $this->views;
            }

            public function reportViewFailure(string $plugin, \Throwable $error): void
            {
                $this->reported[] = ['plugin' => $plugin, 'error' => $error];
            }
        };

        return new ViewRegistrar(
            $source,
            $registry,
            $regions,
            new ViewHost(
                new ViewWindow($this->main),
                new MenuBarWidget(),
                new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $tui),
                $tui,
            ),
            new HitTester($renderer),
            Styles::dark(),
            $translator,
            new FakeScheduler(),
            new CursorStyle(),
            new LayoutController(
                new LayoutStore($this->project->dir),
                $tui,
                $renderer,
                $tree,
                TestBottomDock::create($tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
                new TabBarWidget(),
                $regions,
                $translator,
                new PreviewSplit(Styles::dark()),
            ),
            new ScreenDensity(),
        );
    }

    /** A view whose open state and close behaviour the test scripts. */
    private function view(string $id, bool $open = false, bool $throwOnClose = false): ContributedView
    {
        return new class($id, $open, $throwOnClose) implements ContributedView {
            public int $closes = 0;

            public function __construct(
                private readonly string $id,
                private bool $open,
                private readonly bool $throwOnClose,
            ) {}

            public function viewId(): string
            {
                return $this->id;
            }

            public function hostsWindow(): bool
            {
                return true;
            }

            public function title(): string
            {
                return '';
            }

            public function isOpen(): bool
            {
                return $this->open;
            }

            public function open(): void
            {
                $this->open = true;
            }

            public function close(): void
            {
                ++$this->closes;
                if ($this->throwOnClose) {
                    throw new \RuntimeException('close exploded');
                }
                $this->open = false;
            }

            public function toggle(): void
            {
                $this->open = !$this->open;
            }

            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                return new CodeEditorWidget();
            }

            public function pointer(): ?ViewPointer
            {
                return null;
            }

            public function contextMenus(): ?ViewMenuSource
            {
                return null;
            }

            public function cycleFocus(int $direction): bool
            {
                return false;
            }
        };
    }

    public function testAViewIsBuiltWithItsOwnRegionAlreadyMinted(): void
    {
        // Minted BEFORE the factory runs, because core's own two views fill
        // both panes while being constructed; a plugin's must be able to.
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());
        $seen = null;

        $this->registrar(
            [[
                'plugin' => 'acme',
                'id' => 'vcs',
                'factory' => function (ViewServices $services) use (&$seen): ContributedView {
                    $seen = $services;

                    return $this->view('vcs');
                },
            ]],
            $registry,
            $regions,
        )->register();

        $this->assertInstanceOf(ContributedView::class, $registry->find('vcs'), 'registered under the id it claimed');
        $this->assertInstanceOf(ViewServices::class, $seen);
        $this->assertSame($regions->mint('vcs'), $seen->region, 'and handed the region for that id');
    }

    public function testAThrowingFactoryCostsOnlyItsOwnPluginItsViewAndItsRegion(): void
    {
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());

        $this->registrar(
            [
                ['plugin' => 'broken', 'id' => 'bad', 'factory' => static fn (): ContributedView => throw new \RuntimeException('boom')],
                ['plugin' => 'acme', 'id' => 'good', 'factory' => fn (): ContributedView => $this->view('good')],
            ],
            $registry,
            $regions,
        )->register();

        $this->assertNull($registry->find('bad'));
        $this->assertNull($regions->find('bad'), 'the failed view\'s mint is rolled back — an empty region left mounted renders as a blank workspace');
        $this->assertInstanceOf(ContributedView::class, $registry->find('good'), 'one plugin\'s fault does not cost the others theirs');
        $this->assertCount(1, $this->reported);
        $this->assertSame('broken', $this->reported[0]['plugin'], 'reported through the registering plugin\'s own barrier');
    }

    public function testAFactoryReturningSomethingElseIsAFault(): void
    {
        // Not silently skipped: the plugin author gets told, through the same
        // barrier, rather than watching a command do nothing.
        $registry = new ViewRegistry();

        $this->registrar(
            [[
                'plugin' => 'acme',
                'id' => 'vcs',
                'factory' => static fn (): string => 'not a view',
            ]],
            $registry,
            new ViewRegions(Styles::dark()),
        )->register();

        $this->assertNull($registry->find('vcs'));
        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString('must return a ContributedView', $this->reported[0]['error']->getMessage());
    }

    public function testRegisterForBuildsOnlyTheNamedPluginAndOnlyOnce(): void
    {
        $registry = new ViewRegistry();
        $builds = 0;

        $registrar = $this->registrar(
            [
                [
                    'plugin' => 'acme',
                    'id' => 'vcs',
                    'factory' => function () use (&$builds): ContributedView {
                        ++$builds;

                        return $this->view('vcs');
                    },
                ],
                ['plugin' => 'other', 'id' => 'docs', 'factory' => fn (): ContributedView => $this->view('docs')],
            ],
            $registry,
            new ViewRegions(Styles::dark()),
        );

        $registrar->registerFor('acme');
        $registrar->registerFor('acme');

        $this->assertInstanceOf(ContributedView::class, $registry->find('vcs'));
        $this->assertNull($registry->find('docs'), 'a live enable builds one plugin, not everyone');
        $this->assertSame(1, $builds, 'the second drain is a no-op, not a rebuild');
    }

    public function testAPluginMayNotDisplaceAViewAnotherRegistrarOwns(): void
    {
        // `settings` and `welcome` register from their own constructors during
        // boot; a plugin claiming one of their ids live must be refused — and
        // forget() must never retract what it did not build.
        $registry = new ViewRegistry();
        $core = $this->view('settings');
        $registry->register($core);

        $registrar = $this->registrar(
            [['plugin' => 'acme', 'id' => 'settings', 'factory' => fn (): ContributedView => $this->view('settings')]],
            $registry,
            new ViewRegions(Styles::dark()),
        );
        $registrar->registerFor('acme');
        $this->assertSame($core, $registry->find('settings'), 'the core view kept its id');

        $registrar->forget('acme');
        $this->assertSame($core, $registry->find('settings'), 'and forget() cannot take it either');
    }

    public function testForgetClosesAnOpenViewAndRetractsEverything(): void
    {
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());
        $view = $this->view('vcs');

        $registrar = $this->registrar(
            [['plugin' => 'acme', 'id' => 'vcs', 'factory' => static fn (): ContributedView => $view]],
            $registry,
            $regions,
        );
        $registrar->register();
        $view->open();

        $registrar->forget('acme');

        $this->assertSame(1, $view->closes, 'the open view was closed — that is what returns the main area to the editor');
        $this->assertNull($registry->find('vcs'));
        $this->assertNull($regions->find('vcs'), 'the region is un-minted, so a re-enable mints fresh widgets');
        $this->assertFalse($view->isOpen());
    }

    public function testForgetOfAClosedViewDoesNotCallClose(): void
    {
        $registry = new ViewRegistry();
        $view = $this->view('vcs');

        $registrar = $this->registrar(
            [['plugin' => 'acme', 'id' => 'vcs', 'factory' => static fn (): ContributedView => $view]],
            $registry,
            new ViewRegions(Styles::dark()),
        );
        $registrar->register();

        $registrar->forget('acme');

        $this->assertSame(0, $view->closes, 'nothing to give back: close() runs plugin code and is not called idly');
        $this->assertNull($registry->find('vcs'));
    }

    public function testACloseThatThrowsStillRestoresTheEditorAndRetracts(): void
    {
        // The dying plugin's close() must not leave the workspace showing its
        // dead view: the host is restored by hand and the retraction finishes.
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());
        $view = $this->view('vcs', throwOnClose: true);

        $registrar = $this->registrar(
            [['plugin' => 'acme', 'id' => 'vcs', 'factory' => static fn (): ContributedView => $view]],
            $registry,
            $regions,
        );
        $registrar->register();
        $view->open();

        $registrar->forget('acme');

        $this->assertNull($registry->find('vcs'), 'retraction completed despite the throw');
        $this->assertNull($regions->find('vcs'));
        $this->assertCount(1, $this->reported);
        $this->assertSame('acme', $this->reported[0]['plugin']);
        $this->assertSame([], $this->main->all(), 'the window was emptied by hand');
    }

    public function testForgetRetractsAMatchThatFollowsAnotherPluginsView(): void
    {
        // A `continue`/`break` mix-up in the loop would stop scanning at the
        // first owner it must skip, silently keeping every later match.
        // Registering the OTHER plugin's view first is what exposes that.
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());

        $registrar = $this->registrar(
            [
                ['plugin' => 'other', 'id' => 'docs', 'factory' => fn (): ContributedView => $this->view('docs')],
                ['plugin' => 'acme', 'id' => 'vcs', 'factory' => fn (): ContributedView => $this->view('vcs')],
            ],
            $registry,
            $regions,
        );
        $registrar->register();

        $registrar->forget('acme');

        $this->assertNull($registry->find('vcs'), 'a match after a non-match must still be retracted');
        $this->assertNull($regions->find('vcs'));
        $this->assertInstanceOf(ContributedView::class, $registry->find('docs'), 'and the other plugin keeps its view');
        $this->assertInstanceOf(ViewRegion::class, $regions->find('docs'));
    }

    public function testReEnableMintsAFreshRegion(): void
    {
        // A reused region would still hold the dead view's children — the
        // freshness of the second mint is what disable→re-enable rides on.
        $registry = new ViewRegistry();
        $regions = new ViewRegions(Styles::dark());

        $registrar = $this->registrar(
            [['plugin' => 'acme', 'id' => 'vcs', 'factory' => fn (): ContributedView => $this->view('vcs')]],
            $registry,
            $regions,
        );
        $registrar->registerFor('acme');
        $first = $regions->find('vcs');
        $registrar->forget('acme');

        $registrar->registerFor('acme');

        $this->assertInstanceOf(ContributedView::class, $registry->find('vcs'));
        $this->assertInstanceOf(ViewRegion::class, $regions->find('vcs'));
        $this->assertNotSame($first, $regions->find('vcs'), 'a fresh region, not the dead one');
    }
}
