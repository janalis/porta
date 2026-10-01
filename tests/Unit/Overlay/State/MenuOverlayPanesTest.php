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
    public function testTheRegisteredPanesJoinTheEditorsAndTheChrome(): void
    {
        $editorPane = new TestOverlayPane();
        $rule = new TestOverlayPane();
        $registeredPane = self::registeredPane();
        $editorPanes = new EditorPanes();
        $editorPanes->register($registeredPane, static fn (string $path): string => '');

        $panes = new MenuOverlayPanes([$editorPane], $editorPanes, [$rule]);

        // Without $editorPanes (or with the coalesce reversed), only $editor
        // itself would come back — the registered pane must ALSO be included,
        // or a dropdown opened over an image viewer stays painted after it closes.
        $this->assertSame([$editorPane, $registeredPane, $rule], $panes->current());
    }

    public function testNoRegisteredPanesIsJustTheEditorsAndTheChrome(): void
    {
        $editorPane = new TestOverlayPane();

        $this->assertSame([$editorPane], new MenuOverlayPanes([$editorPane])->current());
    }

    private static function registeredPane(): EditorPaneWidget
    {
        return new class extends EditorPaneWidget {
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
    }
}
