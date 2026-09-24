<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\RecommendedPlugins;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\ThemeChanged;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\HighlightSink;
use Porta\Highlight\NoHighlightPlugins;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Onboarding\MagoDetector;
use Porta\Onboarding\SymfonyDetector;
use Porta\Onboarding\WelcomeSteps;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\Region\DropdownRegion;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\CompactScreen;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\FakeToolInstallOffers;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Ui\Glyphs;
use Porta\Ui\Theme\Theme;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Workbench\ChromeInvalidator;
use Porta\Workbench\ChromeRefreshListener;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Menu\Menu;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The Welcome modal's lifecycle and geometry: the view registry's interlock
 * (a floating modal, so the editor layout is NOT "detached"), the span push
 * into the pane it floats over, click routing on the box (×, title-bar drag,
 * card select, outside swallow), and the per-frame re-sync that lays the box
 * out once rects exist.
 */
final class WelcomeModalControllerTest extends TestCase
{
    use RendersRoot;

    private WelcomeWidget $welcome;
    private ViewRegistry $views;
    private AbstractWidget&MenuOverlayHost $pane;
    private MenuBarWidget $menuBar;
    private EditorArea $editArea;

    private function controller(): WelcomeModalController
    {
        return $this->controllerWith(new FakePluginLifecycle());
    }

    private function controllerWith(FakePluginLifecycle $lifecycle, ?SyntaxHighlightProviderSource $source = null): WelcomeModalController
    {
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(
            terminal: new VirtualTerminal(columns: 80, rows: 24),
            keybindings: new Keybindings([]),
            renderer: $this->renderer,
        );
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);

        $this->welcome = new WelcomeWidget(new Translator('en'), density: CompactScreen::density());
        $this->views = new ViewRegistry();
        $this->menuBar = new MenuBarWidget();

        $this->editArea = new EditorArea(
            new BufferManager(),
            new CodeEditorWidget(),
            new EditorPanes(),
            $this->tui,
        );

        return new WelcomeModalController(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui),
            $this->welcome,
            new WelcomeSteps(
                new Translator('en'),
                $source ?? new NoHighlightPlugins(),
                $lifecycle,
                new CommandRegistry(),
                new MagoDetector('/nonexistent'),
                new SymfonyDetector('/nonexistent'),
                new FakeToolInstallOffers(),
            ),
            $this->menuBar,
            $this->editArea,
            $this->tui,
            $this->views,
            frameHooks: $this->tui,
        );
    }

    public function testAttachesItselfSoTheWorkspaceCanReachIt(): void
    {
        $controller = $this->controller();

        $this->assertFalse($this->views->isOpen('welcome'));
        $this->views->toggle('welcome');
        $this->assertTrue($this->views->isOpen('welcome'));
        $this->assertTrue($controller->isOpen());
    }

    /** The modal floats over the editor layout — nothing detaches, so no view is "open". */
    public function testTheEditorLayoutStaysAttachedWhileOpen(): void
    {
        $this->controller()->open();

        $this->assertTrue($this->views->isOpen('welcome'));
        $this->assertFalse($this->views->isAnyOpen(), 'the modal must not read as a detached main area');
    }

    public function testOpenPushesSpansOntoThePaneAndCloseClearsThem(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $controller->open();
        $this->assertNotSame([], $this->pane->modalSpans, 'the framed box was composited onto the pane');
        $this->assertInstanceOf(DropdownRegion::class, $controller->region());
        $this->assertSame($this->welcome, $this->tui->getFocus());

        $controller->close();
        $this->assertSame([], $this->pane->modalSpans, 'closing cleared the overlay');
        $this->assertNull($controller->region());
    }

    public function testOpenAndCloseAreIdempotent(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $controller->open();
        $spans = $this->pane->modalSpans;
        $controller->open();
        $this->assertSame($spans, $this->pane->modalSpans, 'a second open is a no-op');

        $controller->close();
        $controller->close();
        $this->assertSame([], $this->pane->modalSpans);
        $this->assertSame($this->editArea->widget(), $this->tui->getFocus());

        // A redundant close() while already closed must be a true no-op —
        // not just "spans stay empty" (already true), but "focus is left
        // wherever it legitimately is now", not yanked back to the editor.
        $this->tui->setFocus($this->menuBar);
        $controller->close();
        $this->assertSame($this->menuBar, $this->tui->getFocus(), 'closing an already-closed modal must not steal focus back');
    }

    public function testToggleFlipsBothWays(): void
    {
        $controller = $this->controller();

        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $controller->toggle();
        $this->assertFalse($controller->isOpen());
    }

    public function testFocusTargetIsTheStepList(): void
    {
        $controller = $this->controller();

        $this->assertSame($this->welcome, $controller->focusTarget());
    }

    /** The interlock: focus routes to the modal; the ring is free (keys are swallowed upstream). */
    public function testTheWorkspaceRoutesFocusWhileOpen(): void
    {
        $this->controller()->open();

        $this->assertSame($this->welcome, $this->views->focusTarget());
        $this->assertFalse($this->views->cycleFocus(1), 'no ring branch: the dispatcher swallows keys first');
    }

    public function testTheWorkspaceReportsNothingOpenOnceClosed(): void
    {
        $controller = $this->controller();
        $controller->open();
        $controller->close();

        $this->assertNull($this->views->focusTarget());
        $this->assertFalse($this->views->isOpen('welcome'));
    }

    /** The first-run entry point opens without toggling an already-open modal shut. */
    public function testOpenWelcomeOpensAndIsIdempotent(): void
    {
        $controller = $this->controller();

        $this->views->open('welcome');
        $this->views->open('welcome');

        $this->assertTrue($this->views->isOpen('welcome'));
        $this->assertTrue($controller->isOpen());
    }

    public function testCloseAllClosesIt(): void
    {
        $this->controller()->open();

        $this->views->closeAll();

        $this->assertFalse($this->views->isOpen('welcome'));
    }

    /**
     * Opening populates the steps, and Enter on one reaches the action it
     * delegates to — the dispatcher-to-controller-to-owner path, end to end.
     */
    public function testOpeningPopulatesTheStepsAndEnterRunsOne(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);

        $controller->open();
        $controller->handleKey("\r");

        $this->assertSame(
            [[RecommendedPlugins::HIGHLIGHTER, true]],
            $lifecycle->toggled,
            'the first step delegated to the lifecycle',
        );
    }

    /**
     * The widget outlives the modal, so a reopen (menu or first-run — one code
     * path) must not resume wherever the last visit parked the selection.
     */
    public function testReopeningResetsTheSelectionToTheFirstPendingStep(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);

        $controller->open();
        $controller->handleKey("\x1b[B"); // park on the second step
        $controller->close();
        $controller->open();
        $controller->handleKey("\r");

        $this->assertSame(
            [[RecommendedPlugins::HIGHLIGHTER, true]],
            $lifecycle->toggled,
            'Enter ran the first step again, not the parked one',
        );
    }

    /** The reset lands on the first step still *pending*, skipping the done ones. */
    public function testReopeningSkipsStepsAlreadyDone(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle, $this->loadedEngine()); // highlighting reads done

        $controller->open();
        $controller->handleKey("\r");

        $this->assertSame(
            [[RecommendedPlugins::LANGUAGE_SERVER, true]],
            $lifecycle->toggled,
            'the selection skipped the done highlighting step',
        );
    }

    /** Refreshing after a step ran must NOT reset — the user is mid-list. */
    public function testRefreshDoesNotResetTheSelection(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);

        $controller->open();
        $controller->handleKey("\x1b[B"); // on the second step
        $controller->refresh();
        $controller->handleKey("\r");

        $this->assertSame(
            [[RecommendedPlugins::LANGUAGE_SERVER, true]],
            $lifecycle->toggled,
            'the selection stayed where the user put it',
        );
    }

    public function testTheCloseButtonClosesTheModal(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        // The region is one column wider than the box (the shadow band), so
        // the × sits at region width − 3.
        $consumed = $controller->clickAt($region->top, $region->col + $region->width - 3);

        $this->assertTrue($consumed);
        $this->assertFalse($controller->isOpen(), 'the × closed the modal');
    }

    public function testATitleBarDragMovesAndClampsTheBox(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        // Press on the title bar (off the ×), drag down-and-left one column
        // (the 76-wide, 22-row box on an 80×24 pane leaves little room).
        $this->assertTrue($controller->clickAt($region->top, $region->col + 2));
        $this->assertTrue($controller->continueDragTo($region->top + 2, $region->col + 1, release: false));
        $moved = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $moved);
        $this->assertSame($region->top + 2, $moved->top, 'the box followed the pointer');
        $this->assertSame($region->col - 1, $moved->col);

        // Dragging far past the bottom-right pins to the pane union's edge.
        $this->assertTrue($controller->continueDragTo(500, 500, release: true));
        $pinned = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $pinned);
        $this->assertSame(24 - $pinned->count, $pinned->top, 'clamped to the pane bottom');
        $this->assertSame(80 - $pinned->width, $pinned->col, 'clamped to the pane right edge');
        // The two lines above re-derive their expected value from the same
        // $pinned object being checked (shadowClamped()'s own count/width
        // are computed FROM $top/$col), so a clamp() bound that's wrong in
        // the same direction the shadow math folds back would still pass
        // them. Pin the literal, independently-known numbers too.
        $this->assertSame(2, Probe::get($controller, 'top'), 'literal clamped row: boundBottom(24) - box rows(22)');
        $this->assertSame(4, Probe::get($controller, 'col'), 'literal clamped column: boundRight(80) - box width(76)');
        $this->assertFalse($controller->continueDragTo(0, 0, release: false), 'the release disarmed the drag');
    }

    /** A corner drag resizes the box, floored at 20×5 and reset on reopen. */
    public function testACornerDragResizesTheBoxAndReopenResets(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $box = ['top' => $region->top, 'col' => $region->col, 'width' => $region->width - 1, 'rows' => $region->count - 1];

        // Press the bottom-right corner, drag up-left to shrink, release.
        $corner = [$box['top'] + $box['rows'] - 1, $box['col'] + $box['width'] - 1];
        $this->assertTrue($controller->clickAt($corner[0], $corner[1]));
        $this->assertTrue($controller->continueDragTo($corner[0] - 4, $corner[1] - 10, release: false));
        $shrunk = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $shrunk);
        $this->assertSame($box['width'] - 10, $shrunk->width - 1, 'the width followed the pointer');
        $this->assertSame($box['rows'] - 4, $shrunk->count - 1, 'the height followed the pointer');

        // Dragging into the box's top-left corner pins at the 20×5 floors.
        $this->assertTrue($controller->continueDragTo($box['top'], $box['col'], release: true));
        $floor = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $floor);
        $this->assertSame(20 + 4 + 1, $floor->width, 'inner width floored at 20 (+ chrome + shadow)');
        $this->assertSame(5 + 4 + 1, $floor->count, 'inner height floored at 5 (+ chrome + shadow)');
        $this->assertFalse($controller->continueDragTo(0, 0, release: false), 'the release disarmed the resize');

        // Reopening re-derives the size — overrides are session-only.
        $controller->close();
        $controller->open();
        $reopened = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $reopened);
        $this->assertSame($region->width, $reopened->width, 'the reopen dropped the user size');
    }

    public function testHoveringTheResizeCornerHeaviesIt(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        $this->assertTrue($controller->hoverAt($region->top + $region->count - 2, $region->col + $region->width - 2), 'onto the corner (inside the shadow-widened region)');
        $spans = $this->pane->modalSpans;
        $joined = \implode('', \array_column($spans, 'segment'));
        $this->assertStringContainsString(Glyphs::MODAL_RESIZE_GRAB, $joined, 'the corner heavied');
        $this->assertTrue($controller->hoverAt(0, 0), 'off it again');
        $this->assertStringNotContainsString(Glyphs::MODAL_RESIZE_GRAB, \implode('', \array_column($this->pane->modalSpans, 'segment')));
    }

    public function testAFreshPressCancelsAnArmedDrag(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        $controller->clickAt($region->top, $region->col + 2); // arm
        $controller->cancelDrag();

        $this->assertFalse($controller->continueDragTo(10, 10, release: false), 'the drag was disarmed');
    }

    public function testAContentClickSelectsItsCardAndAnOutsideClickIsSwallowed(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        // Box rows: title bar 0, pad 1, then content (hero 2-4, first card
        // 5-6, spacer 7, second card 8-9 — the window is ≥ HERO_MIN_ROWS).
        $this->assertTrue($controller->clickAt($region->top + 8, $region->col + 4));
        $controller->handleKey("\r");
        $this->assertSame([[RecommendedPlugins::LANGUAGE_SERVER, true]], $lifecycle->toggled, 'the click selected the second card');

        $this->assertTrue($controller->clickAt(0, 0), 'an outside click is swallowed');
        $this->assertTrue($controller->isOpen(), 'and does not dismiss');
    }

    public function testClicksAreNotConsumedWhileClosed(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $this->assertFalse($controller->clickAt(5, 5));
        $this->assertFalse($controller->hoverAt(5, 5));
        $this->assertFalse($controller->wheelAt(5, 5, 3));
    }

    public function testHoveringTheCloseRestylesAndAMissRestores(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        $this->assertTrue($controller->hoverAt($region->top, $region->col + $region->width - 3), 'onto the ×'); // region width − 3: the shadow band
        $this->assertTrue($controller->hoverAt($region->top + 10, $region->col - 1), 'off the box again');
        $this->assertFalse($controller->hoverAt($region->top + 10, $region->col - 1), 'no change → no repaint');
    }

    public function testWheelOverTheBoxScrollsAndOutsideIsStillConsumed(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        $this->assertTrue($controller->wheelAt($region->top + 4, $region->col + 4, 3), 'over the box');
        $this->assertTrue($controller->wheelAt(0, 79, 3), 'outside: consumed all the same (modal)');
    }

    /**
     * The before-frame re-sync: opened before any frame there is nothing to
     * lay out against ({@see WelcomeModalController} keeps asking for frames);
     * the first frame with rects places the box, and a later rect change
     * (a terminal resize) re-clamps it.
     */
    public function testTheBeforeFrameSyncPlacesTheBoxOnceRectsExist(): void
    {
        $controller = $this->controller();

        $controller->open();
        $this->assertNull($controller->region(), 'no rects yet — nothing to place against');

        // Frame 1 records the rects (the hook runs before the render, so it
        // still sees none and re-requests); frame 2's hook places the box.
        $this->tui->requestRender();
        $this->tui->processRender();
        $this->assertNull($controller->region(), 'the hook runs before the first render');
        $this->tui->processRender();
        $this->assertNotNull($controller->region(), 'the next frame placed the box');

        // Shrink the pane (a resize): the next frame re-composes to the new union.
        $before = $controller->region();
        $this->assertNotNull($before);
        $this->frame(columns: 40);
        $this->tui->requestRender();
        $this->tui->processRender();
        $after = $controller->region();
        $this->assertNotNull($after);
        $this->assertLessThan($before->width, $after->width, 'the box re-laid out for the narrower pane');
    }

    /** The workspace dims beneath the modal and brightens again on close. */
    public function testOpenSetsTheBackdropAndCloseClearsIt(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $controller->open();
        $this->assertStringContainsString("\x1b[2m", $this->pane->render(new RenderContext(80, 24))[10], 'the pane renders faint behind the box');

        $controller->close();
        $this->assertStringNotContainsString("\x1b[2m", $this->pane->render(new RenderContext(80, 24))[10], 'closing restores the pane');
    }

    /** A theme/locale change re-composes the open modal (its spans are not tree caches). */
    public function testAThemeChangeRecomposesTheOpenModal(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $this->pane->modalSpans = []; // wiped: only the listener can bring them back

        new ChromeRefreshListener(new ChromeInvalidator(), $controller, $this->tui)->onSettingApplied(new ThemeChanged(Theme::Dark));

        $this->assertNotSame([], $this->pane->modalSpans, 'the modal recomposed');
    }

    public function testAThemeChangeLeavesAClosedModalAlone(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        new ChromeRefreshListener(new ChromeInvalidator(), $controller, $this->tui)->onSettingApplied(new ThemeChanged(Theme::Dark));

        $this->assertSame([], $this->pane->modalSpans);
    }

    public function testEscapeInsideTheModalClosesIt(): void
    {
        $controller = $this->controller();
        $controller->open();

        $controller->handleKey("\x1b");

        $this->assertFalse($controller->isOpen(), 'Esc runs the widget\'s exit hook, which closes the modal');
    }

    public function testConstructedWithoutFrameHooksDoesNotThrow(): void
    {
        // frameHooks is nullable — the nullsafe call must guard against a
        // truly absent scheduler, not just a happy-path presence.
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $this->welcome = new WelcomeWidget(new Translator('en'), density: CompactScreen::density());
        $this->views = new ViewRegistry();
        $editArea = new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $this->tui);

        new WelcomeModalController(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui),
            $this->welcome,
            new WelcomeSteps(
                new Translator('en'),
                new NoHighlightPlugins(),
                new FakePluginLifecycle(),
                new CommandRegistry(),
                new MagoDetector('/nonexistent'),
                new SymfonyDetector('/nonexistent'),
                new FakeToolInstallOffers(),
            ),
            new MenuBarWidget(),
            $editArea,
            $this->tui,
            $this->views,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * WelcomeSteps::all() re-detects mago on every call (it is rebuilt on
     * each open — see its own docblock), so the fixer/analyzer step's action
     * label switches from php-cs-fixer/phpstan to mago the moment it becomes
     * available. refresh() only shows that switch if it really rebuilds the
     * steps rather than repainting the ones already held.
     */
    public function testRefreshPicksUpMagoBecomingAvailable(): void
    {
        $project = new TempProject();
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new BidiTui(terminal: new VirtualTerminal(columns: 80, rows: 24), keybindings: new Keybindings([]), renderer: $this->renderer);
        $this->pane = new TestOverlayPane();
        $this->tui->add($this->pane);
        $this->welcome = new WelcomeWidget(new Translator('en'), density: CompactScreen::density());
        $this->views = new ViewRegistry();
        $editArea = new EditorArea(new BufferManager(), new CodeEditorWidget(), new EditorPanes(), $this->tui);
        $controller = new WelcomeModalController(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], static fn (): array => []), $this->tui),
            $this->welcome,
            new WelcomeSteps(
                new Translator('en'),
                new NoHighlightPlugins(),
                new FakePluginLifecycle(),
                new CommandRegistry(),
                new MagoDetector($project->dir),
                new SymfonyDetector($project->dir),
                new FakeToolInstallOffers(),
            ),
            new MenuBarWidget(),
            $editArea,
            $this->tui,
            $this->views,
            frameHooks: $this->tui,
        );
        $this->frame(80);

        $controller->open();
        $before = \implode('', \array_column($this->pane->modalSpans, 'segment'));
        $this->assertStringContainsString('php-cs-fixer', $before);
        $this->assertStringNotContainsString('mago', $before);

        $magoBinary = $project->write('vendor/bin/mago', '');
        \chmod($magoBinary, permissions: 0o755);
        $controller->refresh();

        $after = \implode('', \array_column($this->pane->modalSpans, 'segment'));
        $this->assertStringContainsString('mago', $after, 'refresh() re-detected mago and rebuilt the step label');
    }

    public function testOpeningAgainWhileAlreadyOpenIsANoOp(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);

        $controller->open();
        $controller->handleKey("\x1b[B"); // park on the second step
        $controller->open(); // already open — must leave the parked selection alone
        $controller->handleKey("\r");

        $this->assertSame(
            [[RecommendedPlugins::LANGUAGE_SERVER, true]],
            $lifecycle->toggled,
            'the redundant open() did not reset the selection back to the first step',
        );
    }

    public function testOpeningTheModalClosesAnyOpenMenuDropdown(): void
    {
        $controller = $this->controller();
        $this->menuBar->setMenus([new Menu('File', [])]);
        $this->menuBar->open();
        $this->assertTrue($this->menuBar->isOpen());

        $controller->open();

        $this->assertFalse($this->menuBar->isOpen(), 'opening the modal drops any open dropdown so its tracked hit region cannot go stale');
    }

    public function testClosingTheModalClosesAnyOpenMenuDropdownToo(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->menuBar->setMenus([new Menu('File', [])]);
        $this->menuBar->open();
        $this->assertTrue($this->menuBar->isOpen());

        $controller->close();

        $this->assertFalse($this->menuBar->isOpen(), 'closing the modal drops any open dropdown too');
    }

    /**
     * Opened before any frame, the box stays null even though $this->open is
     * true ({@see testTheBeforeFrameSyncPlacesTheBoxOnceRectsExist}). Every
     * pointer entry point must swallow the click rather than dereference a
     * null box — the `||` in each guard is load-bearing, not `&&`.
     */
    public function testActionsOnAnOpenModalWithNoBoxYetAreNoOps(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertFalse($controller->clickAt(5, 5));
        $this->assertFalse($controller->hoverAt(5, 5));
        $this->assertFalse($controller->wheelAt(5, 5, 3));
    }

    /**
     * Fixture geometry (80x24, default steps): box.count=18, and
     * WelcomeWidget's row-owner map is one entry longer than the visible
     * window ({@see \Porta\Widget\Onboarding\WelcomeWidget::render()} records
     * every body() row before the final clip), so row 18 and row 17 own the
     * *same* step — only the `< $box->count` boundary tells them apart.
     */
    public function testHoverContentRowRespectsTheWindowBoundary(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $box = Probe::get($controller, 'box');
        $this->assertSame(18, $box->count, 'pinning the fixture this test depends on');

        $lastValidRow = $box->windowTop() + 17;
        $onePastRow = $box->windowTop() + 18;

        $this->assertTrue($controller->hoverAt($lastValidRow, $box->col + 2), 'the last row inside the window registers a hover');
        $this->assertTrue($controller->hoverAt(0, 0), 'off the box entirely clears it');
        $this->assertFalse($controller->hoverAt($onePastRow, $box->col + 2), 'one row past the window must not register, even though the same step owns that dead row');
    }

    /**
     * Until a drag freezes an absolute position, top/col stay null and the
     * box re-centers fresh every compose — including across a pane resize.
     * If compose ever persisted the *computed* value back into $this->top/
     * $this->col unconditionally, the box would freeze after its first
     * placement and stop re-centering.
     */
    public function testAnUndraggedBoxKeepsAutoCenteringAcrossAResize(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $before = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $before);

        // Narrow enough to floor innerWidth at 20 (24-8=16 < 20), which is
        // the one regime where the auto-centered column isn't coincidentally
        // 2 regardless of pane width.
        $this->frame(columns: 24);
        $controller->refresh();
        $afterNarrower = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $afterNarrower);
        $this->assertNotSame($before->col, $afterNarrower->col, 'still auto-centering: a narrower pane re-centers the column, not frozen at the old one');

        $this->frame(80);
        $controller->refresh();
        $afterBack = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $afterBack);
        $this->assertSame($before->col, $afterBack->col, 'and un-resizing lands back on the original center, not a frozen value');
    }

    public function testClosingMovesFocusOffTheWidget(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $this->assertSame($this->welcome, $this->tui->getFocus());

        $controller->close();

        $this->assertSame($this->editArea->widget(), $this->tui->getFocus(), 'focus moved back to the editor, not left on the closed widget');
    }

    public function testArrowKeysRepaintTheSelectionImmediately(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $before = $this->pane->modalSpans;

        $controller->handleKey("\x1b[B"); // move down a step

        $this->assertNotEquals($before, $this->pane->modalSpans, 'handleKey repainted the moved selection, not just the widget-internal state');
    }

    public function testTheCloseButtonDoesNotAlsoArmADrag(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        $consumed = $controller->clickAt($region->top, $region->col + $region->width - 3);
        $this->assertTrue($consumed);
        $this->assertFalse($controller->isOpen());

        $this->assertFalse($controller->continueDragTo(10, 10, release: false), 'closing via the × left no drag armed behind it');
    }

    public function testAFreshDragAfterTheBoxHasAlreadyMovedUsesTheNewOffset(): void
    {
        // The first drag moves the box off startRow=0; re-arming a SECOND
        // drag from the new position must recompute the pointer offset
        // against the box's CURRENT startRow, not the original one — a
        // rowOffset sign flip is invisible while startRow is still 0.
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        // The box is 22 of 24 rows tall, leaving only 2 rows of vertical
        // room to move in before clamping — small, deliberate deltas.
        $this->assertTrue($controller->clickAt($region->top, $region->col + 2));
        $this->assertTrue($controller->continueDragTo($region->top + 1, $region->col, release: true));
        $moved = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $moved);
        $this->assertSame($region->top + 1, $moved->top, 'the box really did move off row 0');

        $this->assertTrue($controller->clickAt($moved->top, $moved->col + 2));
        $this->assertTrue($controller->continueDragTo($moved->top + 1, $moved->col, release: true));
        $movedAgain = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $movedAgain);
        $this->assertSame($moved->top + 1, $movedAgain->top, 'the second drag tracked the pointer from its new starting row');
    }

    public function testClickingAContentRowFocusesTheWidgetAndRepaintsImmediately(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $this->tui->setFocus($this->menuBar); // move focus away first

        $before = $this->pane->modalSpans;
        $controller->clickAt($region->top + 8, $region->col + 4); // the second card, per the existing content-click test

        $this->assertSame($this->welcome, $this->tui->getFocus(), 'the click focused the widget');
        $this->assertNotEquals($before, $this->pane->modalSpans, 'the click repainted the new selection immediately, before any key press');
    }

    /**
     * HERO_MIN_ROWS=10 drops the hero on a short pane, so content row 0 is a
     * real step row rather than a blank hero line — the only fixture where
     * the `>= 0` boundary and the `-1` "nothing hovered" fallback are
     * distinguishable from genuine rowOwner[0] data (with the hero present,
     * both read as null either way).
     */
    public function testHoveringTheFirstContentRowRegistersWhenTheHeroIsDropped(): void
    {
        $controller = $this->controller();
        $this->frame(80, rows: 12);
        $controller->open();
        // resetView() auto-selects the first pending step, which IS row 0 —
        // selection outranks hover styling, so move the selection off it
        // first or the hover tint has nothing to add to compare against.
        $controller->handleKey("\x1b[B");
        $box = Probe::get($controller, 'box');

        $baseline = $this->pane->modalSpans;
        $controller->hoverAt($box->windowTop(), $box->col + 2); // contentRow = 0

        $this->assertNotEquals($baseline, $this->pane->modalSpans, 'the first content row is in range and must tint');
    }

    public function testHoveringOneRowAboveContentNeverTints(): void
    {
        $controller = $this->controller();
        $this->frame(80, rows: 12);
        $controller->open();
        // Same reason as the sibling test: row 0 starts out selected.
        $controller->handleKey("\x1b[B");
        $box = Probe::get($controller, 'box');

        $baseline = $this->pane->modalSpans;
        $controller->hoverAt($box->windowTop() - 1, $box->col + 2); // contentRow = -1, the title bar

        $this->assertSame($baseline, $this->pane->modalSpans, 'one row above the content window must not tint anything');
    }

    /**
     * `contains() && contentRow>=0 && contentRow<count` has a hovering spot
     * that a `||` in the WRONG place between the first two clauses can't
     * distinguish from the real thing unless the hover is truly outside the
     * box's columns (contains() false) while the row alone would otherwise
     * read as valid content.
     */
    public function testHoveringOutsideTheBoxColumnsNeverTintsContent(): void
    {
        $controller = $this->controller();
        $this->frame(80, rows: 12);
        $controller->open();
        $controller->handleKey("\x1b[B");
        $box = Probe::get($controller, 'box');

        $baseline = $this->pane->modalSpans;
        // A row that owns a real step, but a column left of the box entirely.
        $this->assertGreaterThan(0, $box->col, 'sanity: column 0 really is left of the box');
        $controller->hoverAt($box->windowTop() + 1, 0);

        $this->assertSame($baseline, $this->pane->modalSpans, 'outside the box columns must not tint content just because the row would qualify');
    }

    public function testWheelOutsideTheBoxDoesNotScrollOrRepaint(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $before = $this->pane->modalSpans;

        $controller->wheelAt(0, 79, 3); // outside the box: swallowed but must not scroll/repaint

        $this->assertSame($before, $this->pane->modalSpans, 'a wheel outside the box neither scrolled nor repainted');
    }

    public function testWheelOverTheBoxRepaintsImmediately(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $before = $this->pane->modalSpans;

        $controller->wheelAt($region->top + 4, $region->col + 4, 3);

        $this->assertNotEquals($before, $this->pane->modalSpans, 'the scroll repainted immediately');
    }

    public function testResizingRecomputesTheUserSizeFromTheOriginalAnchor(): void
    {
        // The resize offset is measured against $this->col/$this->top (the
        // anchor frozen when the resize armed) — a sign flip there is
        // invisible while the box still sits at its auto-centered position,
        // since anchor and pointer move together. Moving the box first,
        // THEN resizing, is what pulls the two apart.
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        // Only 1 row / 4 columns of room to move in (box is 22x76 of 24x80),
        // so a small move, then shrink (plenty of floor room at 20x5) rather
        // than grow (which would immediately re-clamp back to the max size).
        $this->assertTrue($controller->clickAt($region->top, $region->col + 2));
        $this->assertTrue($controller->continueDragTo($region->top + 1, $region->col + 1, release: true));
        $moved = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $moved);

        $box = ['top' => $moved->top, 'col' => $moved->col, 'width' => $moved->width - 1, 'rows' => $moved->count - 1];
        $corner = [$box['top'] + $box['rows'] - 1, $box['col'] + $box['width'] - 1];
        $this->assertTrue($controller->clickAt($corner[0], $corner[1]));
        $this->assertTrue($controller->continueDragTo($corner[0] - 5, $corner[1] - 5, release: true));

        $resized = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $resized);
        $this->assertSame($box['width'] - 5, $resized->width - 1, 'the width shrank by exactly the pointer delta from the anchored corner');
        $this->assertSame($box['rows'] - 5, $resized->count - 1, 'the height shrank by exactly the pointer delta from the anchored corner');
    }

    public function testTheBeforeFrameSyncIsANoOpWhileClosed(): void
    {
        // syncBeforeFrame's own open-guard: invoking the real hook while
        // closed must not throw or place a box.
        $controller = $this->controller();
        $this->frame(80);

        $this->tui->processRender();

        $this->assertNull($controller->region());
    }

    public function testASecondFrameWithUnchangedBoundsDoesNotRecompose(): void
    {
        // syncBeforeFrame only recomposes when the box is still null or the
        // bounds actually changed — pin that a settled frame is a true no-op
        // by checking the pane spans are byte-identical (not just "some box
        // still exists"), which only compose() actually producing the exact
        // same render also happens to satisfy — so pair it with a spy-free
        // proxy: the modal's own top/col identity survives untouched.
        $controller = $this->controller();
        $controller->open();
        $this->tui->requestRender();
        $this->tui->processRender(); // the hook runs before the render sees any rects yet
        $this->tui->processRender(); // this frame's hook places the box
        $before = $this->pane->modalSpans;

        $this->tui->requestRender();
        $this->tui->processRender(); // bounds unchanged: should be a no-op recompose (same output)

        $this->assertSame($before, $this->pane->modalSpans);
    }

    public function testEveryMutatingActionRequestsARender(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        Probe::set($this->tui, 'renderRequested', false);
        $controller->open();
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'open() requests a render');

        Probe::set($this->tui, 'renderRequested', false);
        $controller->refresh();
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'refresh() requests a render');

        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);

        Probe::set($this->tui, 'renderRequested', false);
        $controller->wheelAt($region->top + 4, $region->col + 4, 3);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'a wheel over the box requests a render');

        $controller->clickAt($region->top, $region->col + 2); // arm a title-bar drag
        Probe::set($this->tui, 'renderRequested', false);
        $controller->continueDragTo($region->top + 1, $region->col + 1, release: true);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'continuing a drag requests a render');

        // The drag moved the box — re-read its geometry before arming the
        // resize corner, or the click lands on stale coordinates.
        $moved = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $moved);
        $box = ['top' => $moved->top, 'col' => $moved->col, 'width' => $moved->width - 1, 'rows' => $moved->count - 1];
        $corner = [$box['top'] + $box['rows'] - 1, $box['col'] + $box['width'] - 1];
        $this->assertTrue($controller->clickAt($corner[0], $corner[1]), 'the corner click was consumed (armed the resize)');
        Probe::set($this->tui, 'renderRequested', false);
        $controller->continueDragTo($corner[0] - 1, $corner[1] - 1, release: false);
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'continuing a resize requests a render');

        Probe::set($this->tui, 'renderRequested', false);
        $controller->close();
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'close() requests a render');
    }

    /**
     * 80x24 union → boundTop=0, boundLeft=0, boundBottom=24, boundRight=80.
     * Every number below is ground truth read straight off the real objects
     * (not re-derived from the same formula the source uses, which would be
     * tautological), so sign/operand drift anywhere in the centring, sizing
     * or clamping arithmetic shows up as a wrong literal.
     */
    public function testComposePlacesTheAutoCenteredBoxAtTheExactGeometry(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $controller->open();

        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $this->assertSame(0, $region->top, 'auto-centered row');
        $this->assertSame(2, $region->col, 'auto-centered column');
        $this->assertSame(77, $region->width, 'shadow-inclusive width (box width 76 + 1)');
        $this->assertSame(23, $region->count, 'shadow-inclusive row count (box rows 22 + 1)');

        $box = Probe::get($controller, 'box');
        $this->assertSame(0, $box->startRow);
        $this->assertSame(2, $box->col);
        $this->assertSame(76, $box->width);
        $this->assertSame(22, $box->rows);
        $this->assertSame(2, $box->headerRows);
        $this->assertSame(0, $box->windowStart, 'the window starts at the top of the content, not offset by one');
        $this->assertSame(18, $box->count);
    }

    /**
     * On the 80x24 fixture, boundTop and boundLeft are both 0, which makes
     * several sign/divisor mistakes in the centring formula coincidentally
     * land on the same number (0 either added or subtracted, or a small
     * intdiv numerator truncating to 0 under more than one divisor). A much
     * larger union (200x60) still has boundTop=boundLeft=0 (one full-width
     * pane), but the OTHER operands are big enough that a wrong divisor or a
     * flipped sign produces a genuinely different number.
     */
    public function testComposeCentersCorrectlyOnAMuchLargerUnion(): void
    {
        $controller = $this->controller();
        $this->frame(200, rows: 60);

        $controller->open();

        $region = $controller->region();
        $this->assertInstanceOf(DropdownRegion::class, $region);
        $this->assertSame(12, $region->top, 'boundTop(0) + intdiv(boundBottom(60)-boundTop(0)-rows(22), 3) = 12');
        $this->assertSame(60, $region->col, 'boundLeft(0) + intdiv(boundRight(200)-boundLeft(0)-width(80), 2) = 60');
    }

    /** A source with one real backend, i.e. highlighting is configured. */
    private function loadedEngine(): SyntaxHighlightProviderSource
    {
        return new class implements SyntaxHighlightProviderSource {
            public function highlightProviders(): array
            {
                return [
                    new class implements SyntaxHighlightProvider {
                        public string $id {
                            get => 'stub';
                        }

                        public function supports(string $language): bool
                        {
                            return true;
                        }

                        public function highlight(HighlightRequest $request, HighlightSink $sink): void
                        {
                            $sink->deliver($request->documentId, $request->version, $request->text);
                        }
                    },
                ];
            }
        };
    }
}
