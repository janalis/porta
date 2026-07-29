<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Graph\GraphLayout;
use Porta\Graph\LayoutEngine;
use Porta\I18n\Translator;
use Porta\Image\ZoomPanModel;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\GraphRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The graph tab's pane: shows a Graphviz DOT document laid out by the
 * {@see LayoutEngine} and rasterized by {@see GraphRenderer}, owning zoom/pan
 * ({@see ZoomPanModel} with a finer ladder — graph fit scales sit far below
 * the image ladder's floor) and node selection. It replaces the code editor
 * as the editor-pane's content while a graph buffer is active, so the
 * tab-cycling / close / go-to-file keys are re-bound here and routed back
 * through {@see ViewerTabHandler} (the image viewer's seam, shared).
 *
 * The error contract: a failed layout KEEPS the last good graph on screen
 * under a one-row stderr banner; only a tab that never laid out shows the
 * full-pane message (actionable when the binary is missing). Layouts are
 * cached per DOT hash (LRU) so tab switches and unchanged regenerations
 * never re-run dot, and the per-path view state preserves zoom/pan across
 * regenerations — reset to fit only when the graph's extent changed.
 */
#[AsIdeService]
#[AsWidget('graph-viewer')]
final class GraphViewerWidget extends AbstractWidget implements FocusableInterface, LtrPane, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use StylesDefault;

    /** The graph zoom ladder: fit scales for wide graphs sit far below the image ladder's 12.5% floor. */
    private const array ZOOM_STEPS = [0.031_25, 0.062_5, 0.125, 0.25, 0.5, 1.0, 2.0, 4.0];

    private const int LAYOUT_CACHE_CAP = 8;

    private ?string $path = null;
    private ?string $dotHash = null;
    /** The last GOOD layout — kept on screen when a newer layout fails. */
    private ?GraphLayout $layout = null;
    private ?ZoomPanModel $view = null;
    /** dot's stderr (or the parse failure) — the banner over the last good render. */
    private ?string $error = null;
    private bool $layingOut = false;
    /** The selected node's _gvid. */
    private ?int $selected = null;
    /** @var list<int> node ids in (y, x) order — the n/p cycling order */
    private array $selectionOrder = [];
    /** @var array<string, GraphLayout> dot-hash => layout, LRU-capped; survives tab switches */
    private array $layoutCache = [];
    /** @var array<string, array{ZoomPanModel, int, int}> path => [view, extent w, extent h], preserved across regenerations */
    private array $viewCache = [];
    private int $lastCols = 80;
    private int $lastRows = 24;
    private ?ViewerTabHandler $handler = null;

    public function __construct(
        private readonly LayoutEngine $engine,
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
    }

    public function setHandler(ViewerTabHandler $handler): void
    {
        $this->handler = $handler;
    }

    /** Show a DOT document: adopt the cached layout for its hash, or run the engine. */
    public function show(string $path, string $dot): void
    {
        $hash = hash('xxh128', $dot);
        if ($path === $this->path && $hash === $this->dotHash) {
            return; // same tab, same content — nothing to do
        }
        $this->path = $path;
        $this->dotHash = $hash;
        $this->selected = null;
        $cached = $this->layoutCache[$hash] ?? null;
        if (null !== $cached) {
            $this->adopt($cached);

            return;
        }
        if (null === $this->engine->binary()) {
            $this->layout = null;
            $this->view = null;
            $this->error = 'missing-binary';
            $this->invalidate();

            return;
        }
        $this->layingOut = true;
        $this->invalidate();
        $this->engine->layout(
            $dot,
            function (GraphLayout $layout) use ($hash): void {
                $this->layingOut = false;
                $this->layoutCache[$hash] = $layout;
                if (\count($this->layoutCache) > self::LAYOUT_CACHE_CAP) {
                    array_shift($this->layoutCache);
                }
                $this->adopt($layout);
            },
            function (string $message): void {
                $this->layingOut = false;
                $this->error = $message; // the previous good layout/view stay on screen
                $this->invalidate();
                $this->handler?->viewChanged();
            },
        );
    }

    /** Detach the current tab (another buffer kind takes the pane); layout caches survive. */
    public function clear(): void
    {
        $this->path = null;
        $this->dotHash = null;
        $this->layout = null;
        $this->view = null;
        $this->error = null;
        $this->layingOut = false;
        $this->selected = null;
        $this->selectionOrder = [];
        $this->invalidate();
    }

    /** The tab closed for real: drop its remembered view state. */
    public function forget(string $path): void
    {
        unset($this->viewCache[$path]);
        if ($path === $this->path) {
            $this->clear();
        }
    }

    /** The status bar's zoom text, '' when nothing is shown. */
    public function zoomLabel(): string
    {
        return null === $this->view ? '' : $this->view->zoomLabel($this->lastCols, 2 * $this->lastRows);
    }

    /** The status bar's graph summary: node/edge counts plus the selected node's label. */
    public function statusInfo(): string
    {
        if (null === $this->layout) {
            return '';
        }
        $info = \sprintf('%d nodes · %d edges', $this->layout->nodeCount(), $this->layout->edgeCount());
        $selected = null !== $this->selected ? $this->layout->nodes[$this->selected] ?? null : null;

        return null !== $selected ? $info.' · '.strtr($selected->label, ["\n" => ' ']) : $info;
    }

    /** Ctrl+wheel: zoom one step anchored on the pane cell under the pointer. */
    public function zoomAt(int $dir, int $relCol, int $relRow): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->zoomAt($dir, $relCol, (2 * $relRow) + 1, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /** Pan by wheel/drag (in half-rows vertically, columns horizontally). */
    public function scrollBy(int $dxCells, int $dyHalfRows): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->panBy($dxCells, $dyHalfRows, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /** Click: select the node under the pane cell (deselects over empty space). */
    public function selectAt(int $relRow, int $relCol): void
    {
        if (null === $this->layout || null === $this->view) {
            return;
        }
        $this->selected = GraphRenderer::nodeAt($this->layout, $this->view, $this->lastCols, $this->lastRows, $relRow, $relCol);
        $this->afterViewChange();
    }

    /** A theme switch re-bakes the SGR codes cached in rendered lines. */
    public function refreshTheme(): void
    {
        $this->invalidate();
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = $context->getRows();
        if ($cols < 1 || $rows < 1) {
            return [];
        }
        $this->lastCols = $cols;
        $this->lastRows = $rows;
        $lines = $this->contentLines($cols, $rows);
        if ($this->isFocused() && $cols >= 3 && $rows >= 3) {
            $lines = $this->focusFrame($lines, $cols, $rows);
        }

        return $this->withMenuOverlay($lines, $cols);
    }

    public function handleInput(string $data): void
    {
        if ('+' === $data) { // KeyParser cannot express a bare '+' spec; alias it here.
            $this->zoom(1);

            return;
        }
        $kb = $this->getKeybindings();
        $pxRows = 2 * $this->lastRows;
        match (true) {
            $kb->matches($data, 'graph_zoom_in') => $this->zoom(1),
            $kb->matches($data, 'graph_zoom_out') => $this->zoom(-1),
            $kb->matches($data, 'graph_zoom_fit') => $this->withView(static fn (ZoomPanModel $v) => $v->fit()),
            $kb->matches($data, 'graph_zoom_actual') => $this->withView(fn (ZoomPanModel $v) => $v->actualSize($this->lastCols, $pxRows)),
            $kb->matches($data, 'graph_pan_left') => $this->pan(-2, 0, $data),
            $kb->matches($data, 'graph_pan_right') => $this->pan(2, 0, $data),
            $kb->matches($data, 'graph_pan_up') => $this->pan(0, -4, $data),
            $kb->matches($data, 'graph_pan_down') => $this->pan(0, 4, $data),
            $kb->matches($data, 'graph_select_next') => $this->cycleSelection(1),
            $kb->matches($data, 'graph_select_prev') => $this->cycleSelection(-1),
            $kb->matches($data, 'next_buffer') => $this->handler?->cycleBuffer(1),
            $kb->matches($data, 'prev_buffer') => $this->handler?->cycleBuffer(-1),
            $kb->matches($data, 'close_buffer') => $this->handler?->closeBuffer(),
            $kb->matches($data, 'goto_file') => $this->handler?->openGotoFile(),
            $kb->matches($data, 'save_all') => $this->handler?->saveAll(),
            default => null,
        };
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'graph_zoom_in' => ['='],
            'graph_zoom_out' => ['-'],
            'graph_zoom_fit' => ['0', 'f'],
            'graph_zoom_actual' => ['1'],
            'graph_pan_left' => [Key::LEFT, 'shift+left'],
            'graph_pan_right' => [Key::RIGHT, 'shift+right'],
            'graph_pan_up' => [Key::UP, 'shift+up'],
            'graph_pan_down' => [Key::DOWN, 'shift+down'],
            'graph_select_next' => ['n'],
            'graph_select_prev' => ['p'],
            // Re-bound to the editor's defaults so tab management keeps working
            // while the code editor is detached (see EditorKeymap).
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
            'save_all' => ['ctrl+alt+s'],
        ];
    }

    /** Adopt a layout: reuse the path's remembered view unless the extent changed (then fit fresh). */
    private function adopt(GraphLayout $layout): void
    {
        $this->layout = $layout;
        $this->error = null;
        $width = max(1, (int) ceil($layout->width));
        $height = max(1, (int) ceil($layout->height));
        $path = (string) $this->path;
        $remembered = $this->viewCache[$path] ?? null;
        $this->view =
            null !== $remembered && [$remembered[1], $remembered[2]] === [$width, $height]
                ? $remembered[0]
                : new ZoomPanModel($width, $height, self::ZOOM_STEPS);
        $this->viewCache[$path] = [$this->view, $width, $height];
        $order = [];
        foreach ($layout->nodes as $node) {
            $order[] = [$node->y, $node->x, $node->id];
        }
        sort($order);
        $this->selectionOrder = array_map(static fn (array $entry): int => (int) $entry[2], $order);
        $this->invalidate();
        $this->handler?->viewChanged();
    }

    private function zoom(int $dir): void
    {
        $this->withView(fn (ZoomPanModel $v) => $dir > 0 ? $v->zoomIn($this->lastCols, 2 * $this->lastRows) : $v->zoomOut($this->lastCols, 2 * $this->lastRows));
    }

    /** Shift+arrow pans a 5× larger step. */
    private function pan(int $dx, int $dy, string $data): void
    {
        $factor = str_starts_with($data, "\x1b[1;2") ? 5 : 1; // CSI 1;2 = shift-modified arrow
        if (null !== $this->view) {
            $this->view->panBy($dx * $factor, $dy * $factor, $this->lastCols, 2 * $this->lastRows);
            $this->afterViewChange();
        }
    }

    /** n/p: walk the (y, x)-ordered node list, panning the selection into view. */
    private function cycleSelection(int $dir): void
    {
        if ([] === $this->selectionOrder || null === $this->layout || null === $this->view) {
            return;
        }
        $count = \count($this->selectionOrder);
        $index = $dir > 0 ? -1 : 0;
        if (null !== $this->selected) {
            $index = (int) array_search($this->selected, $this->selectionOrder, strict: true);
        }
        $this->selected = $this->selectionOrder[((($index + $dir) % $count) + $count) % $count];
        $node = $this->layout->nodes[$this->selected];
        // Pan just enough that the selected node's center is inside the viewport.
        $scale = $this->view->effectiveScale($this->lastCols, 2 * $this->lastRows);
        $rect = $this->view->visibleRect($this->lastCols, 2 * $this->lastRows);
        $dx = (int) round(($node->x - ($rect['srcX'] + (($this->lastCols / $scale) / 2))) * $scale);
        $dy = (int) round(($node->y - ($rect['srcY'] + ($this->lastRows / $scale))) * $scale);
        $this->view->panBy($dx, $dy, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /** @param \Closure(ZoomPanModel): mixed $mutate */
    private function withView(\Closure $mutate): void
    {
        if (null !== $this->view) {
            $mutate($this->view);
            $this->afterViewChange();
        }
    }

    private function afterViewChange(): void
    {
        $this->invalidate();
        $this->handler?->viewChanged();
    }

    /** @return list<string> */
    private function contentLines(int $cols, int $rows): array
    {
        if (null !== $this->layout && null !== $this->view) {
            $lines = GraphRenderer::render($this->layout, $this->view, $this->selected, $cols, $rows, $this->styles);
            if (null !== $this->error) {
                $banner = ' ✗ '.explode("\n", $this->error)[0].' ';
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::DiagError)->apply($banner), $cols, pad: true);
            }
            if ($this->layingOut) {
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::Dim)->apply(' ⏳ '.$this->translator->trans('layout…').' '), $cols, pad: true);
            }

            return $lines;
        }
        $blank = array_fill(0, max(1, $rows), str_repeat(' ', max(1, $cols)));
        $message = match (true) {
            'missing-binary' === $this->error => $this->translator->trans('graphviz not found — install graphviz, or set the "dotPath" setting'),
            null !== $this->error => explode("\n", $this->error)[0],
            $this->layingOut => '⏳ '.$this->translator->trans('layout…'),
            default => '',
        };
        if ('' === $message) {
            return $blank;
        }
        $row = intdiv($rows, num2: 2);
        $text = AnsiUtils::truncateToWidth($message, $cols);
        $pad = max(0, intdiv($cols - AnsiUtils::visibleWidth($text), num2: 2));
        $style = 'missing-binary' === $this->error || null !== $this->error && !$this->layingOut ? Role::DiagError : Role::Dim;
        $blank[$row] = str_repeat(' ', $pad).$this->styles->of($style)->apply($text).str_repeat(' ', max(0, $cols - $pad - AnsiUtils::visibleWidth($text)));

        return $blank;
    }

    /**
     * The image viewer's focus cue, same shape: a box-drawing frame over the
     * outer ring while focused, every line kept exactly $cols wide.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function focusFrame(array $lines, int $cols, int $rows): array
    {
        $accent = $this->styles->of(Role::TabAccent);
        $span = max(0, $cols - 2);
        $lines[0] = $accent->apply(Glyphs::FRAME_TOP_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_TOP_RIGHT);
        $lines[$rows - 1] = $accent->apply(Glyphs::FRAME_BOTTOM_LEFT.str_repeat(Glyphs::FRAME_HORIZONTAL, $span).Glyphs::FRAME_BOTTOM_RIGHT);
        $rail = $accent->apply(Glyphs::FRAME_VERTICAL);
        for ($r = 1; $r < ($rows - 1); ++$r) {
            $lines[$r] = $rail.AnsiUtils::sliceByColumn($lines[$r], 1, $cols - 2).$rail;
        }

        return $lines;
    }
}
