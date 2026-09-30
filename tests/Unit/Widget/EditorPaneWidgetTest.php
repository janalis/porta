<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RecordingViewerTabHandler;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\NavigablePaneWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What the pane bases give a pane for free — the defaults a plugin does NOT
 * have to write. The double below overrides only the abstract members, so the
 * inherited bodies are the ones under test.
 */
final class EditorPaneWidgetTest extends TestCase
{
    private function pane(): NavigablePaneWidget
    {
        return new class extends NavigablePaneWidget {
            public int $painted = 0;

            public function paneId(): string
            {
                return 'bare';
            }

            public function show(string $path, mixed $payload): void {}

            public function clear(): void {}

            public function statusRight(): string
            {
                return '';
            }

            public function scrollBy(int $dxCells, int $dyHalfRows): void
            {
                $this->afterViewChange();
            }

            public function zoomAt(int $dir, int $relCol, int $relRow): void {}

            public function magnifyAt(float $factor, int $relCol, int $relRow): void {}

            public function handleInput(string $data): void {}

            public function render(RenderContext $context): array
            {
                ++$this->painted;

                return [\str_repeat(' ', $context->getColumns())];
            }
        };
    }

    public function testTheViewChangeFunnelRepaintsAndTellsTheIde(): void
    {
        $pane = $this->pane();
        $handler = new RecordingViewerTabHandler();
        $pane->setHandler($handler);

        $pane->scrollBy(1, 2);

        $this->assertSame(['view'], $handler->events, 'the status bar is told the view moved');
    }

    public function testAPaneWithoutAHandlerStillMoves(): void
    {
        // Every handler call is `?->`: a pane constructed in a test, or one
        // registered before the IDE wires its tab handler, must not fatal.
        $pane = $this->pane();
        $this->assertNull(Probe::get($pane, 'handler'));

        $pane->scrollBy(1, 2);

        $this->addToAssertionCount(1);
    }

    public function testTheInheritedDefaultsAreInertRatherThanAbstract(): void
    {
        // A pane with no per-path state, nothing selectable and nothing to do
        // with a press inherits all three — that is what keeps a simple pane a
        // dozen lines long.
        $pane = $this->pane();

        $pane->forget('/p/anything.demo');
        $pane->selectAt(3, 4);
        $revision = $pane->getRenderRevision();
        $pane->followSource(5, 2);

        $this->assertFalse($pane->press(1, 2), 'a press takes focus and arms no drag');
        $this->assertSame($revision, $pane->getRenderRevision(), 'nothing to follow, nothing redrawn');
    }

    public function testAPaneThatDoesNotScrollIgnoresTheWheel(): void
    {
        // The bare base: `wheelBy` is a no-op, which is what a pane showing a
        // single fitted picture wants. NavigablePaneWidget overrides it to pan,
        // and the help pane to scroll whole rows.
        $pane = new class extends EditorPaneWidget {
            public function paneId(): string
            {
                return 'inert';
            }

            public function show(string $path, mixed $payload): void {}

            public function clear(): void {}

            public function statusRight(): string
            {
                return '';
            }

            public function handleInput(string $data): void {}

            public function render(RenderContext $context): array
            {
                return [];
            }
        };

        $pane->wheelBy(3);

        $this->addToAssertionCount(1);
    }

    public function testATiredThemeRepaints(): void
    {
        $pane = $this->pane();
        $pane->render(new RenderContext(10, 2));
        $before = Probe::get($pane, 'renderRevision');

        $pane->refreshTheme();

        $this->assertNotSame($before, Probe::get($pane, 'renderRevision'), 'the cached lines are dropped so baked SGR codes are re-made');
    }
}
