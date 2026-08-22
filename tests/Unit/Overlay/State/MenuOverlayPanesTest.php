<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorPanes;
use Porta\Overlay\State\MenuOverlayPanes;
use Porta\Tests\Support\TestOverlayPane;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\Tui\Render\RenderContext;

final class MenuOverlayPanesTest extends TestCase
{
    public function testCurrentFallsBackToTheEditorPanesWhenNoViewIsOpen(): void
    {
        $editorPane = new TestOverlayPane();
        $registeredPane = new class extends EditorPaneWidget {
            #[\Override]
            public function paneId(): string
            {
                return 'test-pane';
            }

            #[\Override]
            public function show(string $path, mixed $payload): void {}

            #[\Override]
            public function clear(): void {}

            #[\Override]
            public function statusRight(): string
            {
                return '';
            }

            #[\Override]
            public function render(RenderContext $context): array
            {
                return [];
            }

            #[\Override]
            public function handleInput(string $data): void {}
        };
        $editorPanes = new EditorPanes();
        $editorPanes->register($registeredPane, static fn (string $path): string => '');

        $panes = new MenuOverlayPanes([$editorPane], static fn (): array => [], $editorPanes);

        // Without $editorPanes (or with the coalesce reversed), only $editor
        // itself would come back — the registered pane must ALSO be included.
        $this->assertSame([$editorPane, $registeredPane], $panes->current());
    }

    public function testCurrentPrefersTheOpenViewOverTheEditorPanes(): void
    {
        $editorPane = new TestOverlayPane();
        $viewPane = new TestOverlayPane();
        $panes = new MenuOverlayPanes([$editorPane], static fn (): array => [$viewPane]);

        $this->assertSame([$viewPane], $panes->current());
    }

    public function testAllCombinesTheEditorAndOpenViewPanesRegardlessOfRegisteredPanes(): void
    {
        $editorPane = new TestOverlayPane();
        $viewPane = new TestOverlayPane();
        $panes = new MenuOverlayPanes([$editorPane], static fn (): array => [$viewPane]);

        $this->assertSame([$editorPane, $viewPane], $panes->all());
    }
}
