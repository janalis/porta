<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\EditorPaneWidget;
use Porta\Widget\NavigablePaneWidget;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * A minimal third-party pane: what a plugin's `registerPane()` factory returns,
 * with every abstract member answered as cheaply as possible and every call
 * recorded. `TestOverlayPane` is the model — it renders real rows so a Renderer
 * pass gives it a rect, and composites through the production overlay trait.
 */
final class TestPane extends NavigablePaneWidget
{
    /** @var list<string> every pane call, in order */
    public array $calls = [];

    public ?string $shownPath = null;
    public mixed $shownPayload = null;

    /** @param EditorPaneWidget|null $flankPane what {@see flank()} seats on the editor's other side */
    public function __construct(
        private readonly string $id = 'test-pane',
        private readonly ?EditorPaneWidget $flankPane = null,
    ) {}

    public function flank(): ?EditorPaneWidget
    {
        return $this->flankPane;
    }

    /**
     * A pane binds its own keys, which is exactly what the keymap page has to
     * be able to see — one of its own actions and one of core's.
     *
     * @return array<string, list<string>>
     */
    public static function getDefaultKeybindings(): array
    {
        return ['test_pane_step' => ['n'], 'save' => ['g']];
    }

    public function paneId(): string
    {
        return $this->id;
    }

    public function show(string $path, mixed $payload): void
    {
        $this->calls[] = 'show:'.$path;
        $this->shownPath = $path;
        $this->shownPayload = $payload;
        $this->invalidate();
    }

    public function clear(): void
    {
        $this->calls[] = 'clear';
        $this->shownPath = null;
        $this->shownPayload = null;
    }

    public function followSource(int $line, int $byteCol): void
    {
        $this->calls[] = \sprintf('follow:%d,%d', $line, $byteCol);
    }

    public function statusRight(): string
    {
        return null === $this->shownPath ? '' : 'test pane  '.\basename($this->shownPath);
    }

    /** A status cell of its own: the step its key makes, and nothing else. */
    public function runStatusAction(string $action): bool
    {
        $this->calls[] = 'status:'.$action;

        return 'test_pane_step' === $action;
    }

    public function forget(string $path): void
    {
        $this->calls[] = 'forget:'.$path;
    }

    public function refreshTheme(): void
    {
        $this->calls[] = 'theme';
        parent::refreshTheme();
    }

    public function scrollBy(int $dxCells, int $dyHalfRows): void
    {
        $this->calls[] = \sprintf('scroll:%d,%d', $dxCells, $dyHalfRows);
        $this->afterViewChange();
    }

    public function zoomAt(int $dir, int $relCol, int $relRow): void
    {
        $this->calls[] = \sprintf('zoom:%d@%d,%d', $dir, $relCol, $relRow);
        $this->afterViewChange();
    }

    public function magnifyAt(float $factor, int $relCol, int $relRow): void
    {
        $this->calls[] = \sprintf('magnify:%.2f@%d,%d', $factor, $relCol, $relRow);
        $this->afterViewChange();
    }

    /** A navigable pane arms a drag on every press, as the graph pane does. */
    public function press(int $relRow, int $relCol): bool
    {
        $this->calls[] = \sprintf('press:%d,%d', $relRow, $relCol);

        return true;
    }

    public function selectAt(int $relRow, int $relCol): void
    {
        $this->calls[] = \sprintf('select:%d,%d', $relRow, $relCol);
    }

    public function handleInput(string $data): void
    {
        $this->calls[] = 'key:'.$data;
    }

    public function render(RenderContext $context): array
    {
        $columns = \max(0, $context->getColumns());
        $body = null === $this->shownPath ? 'empty' : 'showing '.\basename($this->shownPath);
        $lines = [\substr(\str_pad($body, $columns), offset: 0, length: $columns)];
        for ($row = 1; $row < $context->getRows(); ++$row) {
            $lines[] = \str_repeat(' ', $columns);
        }

        return $this->withMenuOverlay($lines, $context->getColumns());
    }
}
