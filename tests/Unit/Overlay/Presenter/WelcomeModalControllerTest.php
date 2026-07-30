<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\Presenter;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
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
use Porta\Onboarding\WelcomeSteps;
use Porta\Overlay\Presenter\OverlayDelivery;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Settings\SettingsHighlightController;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Tests\Support\RendersRoot;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Ui\BidiTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Workbench\ChromeInvalidator;
use Porta\Workbench\ChromeRefreshListener;
use Porta\Workbench\EditorArea;
use Porta\Workbench\WorkspaceView;
use Porta\Workbench\WorkspaceViews;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The Welcome modal's lifecycle and geometry: the WorkspaceViews interlock
 * (a floating modal, so the editor layout is NOT "detached"), the span push
 * into the pane it floats over, click routing on the box (×, title-bar drag,
 * card select, outside swallow), and the per-frame re-sync that lays the box
 * out once rects exist.
 */
final class WelcomeModalControllerTest extends TestCase
{
    use RendersRoot;

    private WelcomeWidget $welcome;
    private WorkspaceViews $views;
    private AbstractWidget&MenuOverlayHost $pane;

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

        $this->welcome = new WelcomeWidget(new Translator('en'));
        $this->views = new WorkspaceViews();

        $editArea = new EditorArea(
            new BufferManager(),
            new CodeEditorWidget(),
            new EditorPanes(),
            $this->tui,
        );

        return new WelcomeModalController(
            new OverlayDelivery($this->renderer, new MenuOverlayPanes([$this->pane], [], [], static fn (): bool => false, static fn (): bool => false), $this->tui),
            $this->welcome,
            new WelcomeSteps(new Translator('en'), $source ?? new NoHighlightPlugins(), $lifecycle, new CommandRegistry(), new MagoDetector('/nonexistent')),
            new MenuBarWidget(),
            $editArea,
            $this->tui,
            $this->views,
            frameHooks: $this->tui,
        );
    }

    public function testAttachesItselfSoTheWorkspaceCanReachIt(): void
    {
        $controller = $this->controller();

        $this->assertFalse($this->views->isOpen(WorkspaceView::Welcome));
        $this->views->toggle(WorkspaceView::Welcome);
        $this->assertTrue($this->views->isOpen(WorkspaceView::Welcome));
        $this->assertTrue($controller->isOpen());
    }

    /** The modal floats over the editor layout — nothing detaches, so no view is "open". */
    public function testTheEditorLayoutStaysAttachedWhileOpen(): void
    {
        $this->controller()->open();

        $this->assertTrue($this->views->isOpen(WorkspaceView::Welcome));
        $this->assertFalse($this->views->isAnyOpen(), 'the modal must not read as a detached main area');
    }

    public function testOpenPushesSpansOntoThePaneAndCloseClearsThem(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        $controller->open();
        $this->assertNotSame([], $this->pane->modalSpans, 'the framed box was composited onto the pane');
        $this->assertNotNull($controller->region());
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
        $this->assertFalse($this->views->isOpen(WorkspaceView::Welcome));
    }

    /** The first-run entry point opens without toggling an already-open modal shut. */
    public function testOpenWelcomeOpensAndIsIdempotent(): void
    {
        $controller = $this->controller();

        $this->views->open(WorkspaceView::Welcome);
        $this->views->open(WorkspaceView::Welcome);

        $this->assertTrue($this->views->isOpen(WorkspaceView::Welcome));
        $this->assertTrue($controller->isOpen());
    }

    public function testCloseAllClosesIt(): void
    {
        $this->controller()->open();

        $this->views->closeAll();

        $this->assertFalse($this->views->isOpen(WorkspaceView::Welcome));
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
            [[SettingsHighlightController::BUNDLED_ENGINE, true]],
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
            [[SettingsHighlightController::BUNDLED_ENGINE, true]],
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
            [[WelcomeSteps::PHP_LANGUAGE_SERVER, true]],
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
            [[WelcomeSteps::PHP_LANGUAGE_SERVER, true]],
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
        $this->assertNotNull($region);

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
        $this->assertNotNull($region);

        // Press on the title bar (off the ×), drag down-and-left one column
        // (the 76-wide, 22-row box on an 80×24 pane leaves little room).
        $this->assertTrue($controller->clickAt($region->top, $region->col + 2));
        $this->assertTrue($controller->continueDragTo($region->top + 2, $region->col + 1, release: false));
        $moved = $controller->region();
        $this->assertNotNull($moved);
        $this->assertSame($region->top + 2, $moved->top, 'the box followed the pointer');
        $this->assertSame($region->col - 1, $moved->col);

        // Dragging far past the bottom-right pins to the pane union's edge.
        $this->assertTrue($controller->continueDragTo(500, 500, release: true));
        $pinned = $controller->region();
        $this->assertNotNull($pinned);
        $this->assertSame(24 - $pinned->count, $pinned->top, 'clamped to the pane bottom');
        $this->assertSame(80 - $pinned->width, $pinned->col, 'clamped to the pane right edge');
        $this->assertFalse($controller->continueDragTo(0, 0, release: false), 'the release disarmed the drag');
    }

    /** A corner drag resizes the box, floored at 20×5 and reset on reopen. */
    public function testACornerDragResizesTheBoxAndReopenResets(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertNotNull($region);
        $box = ['top' => $region->top, 'col' => $region->col, 'width' => $region->width - 1, 'rows' => $region->count - 1];

        // Press the bottom-right corner, drag up-left to shrink, release.
        $corner = [$box['top'] + $box['rows'] - 1, $box['col'] + $box['width'] - 1];
        $this->assertTrue($controller->clickAt($corner[0], $corner[1]));
        $this->assertTrue($controller->continueDragTo($corner[0] - 4, $corner[1] - 10, release: false));
        $shrunk = $controller->region();
        $this->assertNotNull($shrunk);
        $this->assertSame($box['width'] - 10, $shrunk->width - 1, 'the width followed the pointer');
        $this->assertSame($box['rows'] - 4, $shrunk->count - 1, 'the height followed the pointer');

        // Dragging into the box's top-left corner pins at the 20×5 floors.
        $this->assertTrue($controller->continueDragTo($box['top'], $box['col'], release: true));
        $floor = $controller->region();
        $this->assertNotNull($floor);
        $this->assertSame(20 + 4 + 1, $floor->width, 'inner width floored at 20 (+ chrome + shadow)');
        $this->assertSame(5 + 4 + 1, $floor->count, 'inner height floored at 5 (+ chrome + shadow)');
        $this->assertFalse($controller->continueDragTo(0, 0, release: false), 'the release disarmed the resize');

        // Reopening re-derives the size — overrides are session-only.
        $controller->close();
        $controller->open();
        $reopened = $controller->region();
        $this->assertNotNull($reopened);
        $this->assertSame($region->width, $reopened->width, 'the reopen dropped the user size');
    }

    public function testHoveringTheResizeCornerHeaviesIt(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertNotNull($region);

        $this->assertTrue($controller->hoverAt($region->top + $region->count - 2, $region->col + $region->width - 2), 'onto the corner (inside the shadow-widened region)');
        $spans = $this->pane->modalSpans;
        $joined = implode('', array_column($spans, 'segment'));
        $this->assertStringContainsString(\Porta\Ui\Glyphs::MODAL_RESIZE_GRAB, $joined, 'the corner heavied');
        $this->assertTrue($controller->hoverAt(0, 0), 'off it again');
        $this->assertStringNotContainsString(\Porta\Ui\Glyphs::MODAL_RESIZE_GRAB, implode('', array_column($this->pane->modalSpans, 'segment')));
    }

    public function testAFreshPressCancelsAnArmedDrag(): void
    {
        $controller = $this->controller();
        $this->frame(80);
        $controller->open();
        $region = $controller->region();
        $this->assertNotNull($region);

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
        $this->assertNotNull($region);

        // Box rows: title bar 0, pad 1, then content (hero 2-4, first card
        // 5-6, spacer 7, second card 8-9 — the window is ≥ HERO_MIN_ROWS).
        $this->assertTrue($controller->clickAt($region->top + 8, $region->col + 4));
        $controller->handleKey("\r");
        $this->assertSame([[WelcomeSteps::PHP_LANGUAGE_SERVER, true]], $lifecycle->toggled, 'the click selected the second card');

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
        $this->assertNotNull($region);

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
        $this->assertNotNull($region);

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

        new ChromeRefreshListener(new ChromeInvalidator(), $controller, $this->tui)->onSettingApplied(new ThemeChanged('dark'));

        $this->assertNotSame([], $this->pane->modalSpans, 'the modal recomposed');
    }

    public function testAThemeChangeLeavesAClosedModalAlone(): void
    {
        $controller = $this->controller();
        $this->frame(80);

        new ChromeRefreshListener(new ChromeInvalidator(), $controller, $this->tui)->onSettingApplied(new ThemeChanged('dark'));

        $this->assertSame([], $this->pane->modalSpans);
    }

    /** A source with one real backend, i.e. highlighting is configured. */
    private function loadedEngine(): SyntaxHighlightProviderSource
    {
        return new class implements SyntaxHighlightProviderSource {
            public function highlightProviders(): array
            {
                return [
                    new class implements SyntaxHighlightProvider {
                        public function id(): string
                        {
                            return 'stub';
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
