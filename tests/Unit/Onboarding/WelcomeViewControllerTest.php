<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Config\SettingsHighlightController;
use Porta\Editor\BufferManager;
use Porta\Highlight\NoHighlightPlugins;
use Porta\I18n\Translator;
use Porta\Layout\EditorAreaPanes;
use Porta\Onboarding\WelcomeSteps;
use Porta\Onboarding\WelcomeViewController;
use Porta\Tests\Support\FakePluginLifecycle;
use Porta\Ui\EditorArea;
use Porta\Ui\WorkspaceViews;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\Onboarding\WelcomeWidget;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The Welcome view swapping the main area, and the WorkspaceViews interlock —
 * one full view owns the main area at a time.
 */
final class WelcomeViewControllerTest extends TestCase
{
    private Tui $tui;
    private ContainerWidget $main;
    private WelcomeWidget $welcome;
    private ContainerWidget $editorPane;
    private WorkspaceViews $views;

    private function controller(): WelcomeViewController
    {
        return $this->controllerWith(new FakePluginLifecycle());
    }

    private function controllerWith(FakePluginLifecycle $lifecycle): WelcomeViewController
    {
        $this->tui = new Tui(
            terminal: new VirtualTerminal(columns: 80, rows: 24),
            keybindings: new Keybindings([]),
            renderer: new Renderer(new StyleSheet()),
        );
        $this->main = new ContainerWidget();
        $this->main->setId('main');
        $this->editorPane = new ContainerWidget();
        $this->editorPane->setId('editor-pane');
        $this->main->add($this->editorPane);

        $this->welcome = new WelcomeWidget(new Translator('en'));
        $this->views = new WorkspaceViews();

        $editArea = new class($this->editorPane) implements EditorAreaPanes {
            public function __construct(
                private readonly ContainerWidget $pane,
            ) {}

            public function orderedEditorPanes(): array
            {
                return [$this->pane];
            }
        };

        return new WelcomeViewController(
            $this->welcome,
            new WelcomeSteps(new Translator('en'), new NoHighlightPlugins(), $lifecycle),
            $this->main,
            new MenuBarWidget(),
            $editArea,
            $this->tui,
            new EditorArea(new BufferManager(), new CodeEditorWidget(), new ImageViewerWidget(), new HelpViewerWidget(), $this->tui),
            $this->views,
        );
    }

    /**
     * The main area's children by identity, not id: `#[AsWidget]` ids are applied
     * by the container, so a directly-constructed widget has none.
     *
     * @return list<object>
     */
    private function mainPanes(): array
    {
        return array_values($this->main->all());
    }

    public function testAttachesItselfSoTheWorkspaceCanReachIt(): void
    {
        $controller = $this->controller();

        $this->assertFalse($this->views->isWelcomeOpen());
        $this->views->toggleWelcome();
        $this->assertTrue($this->views->isWelcomeOpen());
        $this->assertTrue($controller->isOpen());
    }

    public function testOpenSwapsTheMainAreaAndCloseRestoresIt(): void
    {
        $controller = $this->controller();

        $controller->open();
        $this->assertSame([$this->welcome], $this->mainPanes());
        $this->assertSame($this->welcome, $this->tui->getFocus());

        $controller->close();
        $this->assertSame([$this->editorPane], $this->mainPanes(), 'the editor panes came back');
    }

    public function testOpenAndCloseAreIdempotent(): void
    {
        $controller = $this->controller();

        $controller->open();
        $controller->open();
        $this->assertSame([$this->welcome], $this->mainPanes(), 'not added twice');

        $controller->close();
        $controller->close();
        $this->assertSame([$this->editorPane], $this->mainPanes());
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

    /** The interlock: the workspace routes focus and the F6 ring to the open view. */
    public function testTheWorkspaceRoutesFocusAndTheRingWhileOpen(): void
    {
        $this->controller()->open();

        $this->assertSame($this->welcome, $this->views->focusTarget());
        $this->assertTrue($this->views->cycleFocus(1), 'the ring must not fall through to the detached editor');
        $this->assertTrue($this->views->isAnyOpen());
    }

    public function testTheWorkspaceReportsNothingOpenOnceClosed(): void
    {
        $controller = $this->controller();
        $controller->open();
        $controller->close();

        $this->assertNull($this->views->focusTarget());
        $this->assertFalse($this->views->cycleFocus(1));
        $this->assertFalse($this->views->isAnyOpen());
    }

    /** The first-run entry point opens without toggling an already-open view shut. */
    public function testOpenWelcomeOpensAndIsIdempotent(): void
    {
        $this->controller();

        $this->views->openWelcome();
        $this->views->openWelcome();

        $this->assertTrue($this->views->isWelcomeOpen());
        $this->assertSame([$this->welcome], $this->mainPanes());
    }

    public function testCloseAllClosesIt(): void
    {
        $this->controller()->open();

        $this->views->closeAll();

        $this->assertFalse($this->views->isWelcomeOpen());
    }

    /**
     * Opening populates the steps, and Enter on one reaches the action it
     * delegates to — the widget-to-controller-to-owner path, end to end.
     */
    public function testOpeningPopulatesTheStepsAndEnterRunsOne(): void
    {
        $lifecycle = new FakePluginLifecycle();
        $controller = $this->controllerWith($lifecycle);

        $controller->open();
        $this->welcome->handleInput("\r");

        $this->assertSame(
            [[SettingsHighlightController::BUNDLED_ENGINE, true]],
            $lifecycle->toggled,
            'the first step delegated to the lifecycle',
        );
    }
}
