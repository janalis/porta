<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\FakeScheduler;
use Porta\Tests\Support\TestTui;
use Porta\Ui\CursorStyle;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ContributedViewSource;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewRegistrar;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\ViewServices;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The drain that turns a plugin's registerView() into a live view.
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

    /** @param list<array{plugin: string, id: string, factory: \Closure}> $views */
    private function registrar(array $views, ViewRegistry $registry, ViewRegions $regions): ViewRegistrar
    {
        $tui = TestTui::create(100, 24);
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
                new ContainerWidget(),
                new MenuBarWidget(),
                new class implements EditorAreaPanes {
                    public function orderedEditorPanes(): array
                    {
                        return [];
                    }
                },
                new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $tui),
                $tui,
            ),
            new HitTester(new Renderer(new StyleSheet())),
            Styles::dark(),
            new Translator('en'),
            new FakeScheduler(),
            new CursorStyle(),
        );
    }

    private function view(string $id): ContributedView
    {
        return new class($id) implements ContributedView {
            public function __construct(
                private readonly string $id,
            ) {}

            public function viewId(): string
            {
                return $this->id;
            }

            public function ownsMainArea(): bool
            {
                return true;
            }

            public function isOpen(): bool
            {
                return false;
            }

            public function open(): void {}

            public function close(): void {}

            public function toggle(): void {}

            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                return new CodeEditorWidget();
            }

            public function pointer(): ?\Porta\Workbench\View\ViewPointer
            {
                return null;
            }

            public function contextMenus(): ?\Porta\Workbench\Menu\ViewMenuSource
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

        $this->assertNotNull($registry->find('vcs'), 'registered under the id it claimed');
        $this->assertInstanceOf(ViewServices::class, $seen);
        $this->assertSame($regions->mint('vcs'), $seen->region, 'and handed the region for that id');
    }

    public function testAThrowingFactoryCostsOnlyItsOwnPluginItsView(): void
    {
        $registry = new ViewRegistry();

        $this->registrar(
            [
                ['plugin' => 'broken', 'id' => 'bad', 'factory' => static fn (): ContributedView => throw new \RuntimeException('boom')],
                ['plugin' => 'acme', 'id' => 'good', 'factory' => fn (): ContributedView => $this->view('good')],
            ],
            $registry,
            new ViewRegions(Styles::dark()),
        )->register();

        $this->assertNull($registry->find('bad'));
        $this->assertNotNull($registry->find('good'), 'one plugin\'s fault does not cost the others theirs');
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
}
