<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Graph\GraphLayout;
use Porta\Graph\GraphRenderMode;
use Porta\Graph\LayoutEngine;
use Porta\I18n\Translator;
use Porta\Image\HalfBlockRasterizer;
use Porta\Image\ImageData;
use Porta\Image\ImageDecoder;
use Porta\Image\ScaledSource;
use Porta\Image\ZoomPanModel;
use Porta\Support\DebouncedRequest;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\GraphRenderer;
use Porta\Widget\Render\GraphSelectionOverlay;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The graph tab's pane: shows a Graphviz DOT document as the PICTURE dot itself
 * renders — `dot -Tpng` decoded into truecolor pixels and painted as half-blocks,
 * the image viewer's path — over the geometry `dot -Tjson0` provides, which is
 * what still answers "which node is under the pointer" and "how many edges".
 * It owns zoom/pan ({@see ZoomPanModel} with a finer ladder — graph fit scales
 * sit far below the image ladder's floor) and node selection.
 *
 * Until the bitmap arrives — and forever, on a graphviz built without libgd,
 * whose `dot` cannot emit PNG at all — {@see GraphRenderer}'s cell rendering
 * paints instead. That is not a degraded mode so much as a different one: it
 * draws node labels as real text, which stays readable at a pane size where the
 * bitmap's own type is a few pixels tall. It replaces the code editor
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

    /** 72 dpi = one point per pixel, so the bitmap shares the layout's coordinates. */
    private const int BITMAP_DPI = 72;

    /** How long a zoom has to settle before dot is asked for a sharper bitmap. */
    private const float RENDER_DEBOUNCE = 0.15;

    private ?string $path = null;
    private ?string $dotHash = null;
    /** The DOT source of the shown tab — kept because a bitmap is re-rendered from it. */
    private ?string $dot = null;
    /** The picture dot rendered, or null while none has arrived for this graph. */
    private ?ImageData $bitmap = null;
    /** The dpi the shown bitmap was rendered at — its pixels per layout point. */
    private int $bitmapDpi = self::BITMAP_DPI;
    /** Coalesces and debounces the re-render the current zoom wants. */
    private readonly DebouncedRequest $pending;
    /** Which rendering paints: the persisted default, flipped live by its key. */
    private GraphRenderMode $mode;
    /** The laid-out extent in points, for the resolution cap. */
    private int $extentW = 1;
    private int $extentH = 1;
    /** Memoises the area-averaged copy the rasterizer samples while shrinking. */
    private readonly ScaledSource $scaled;
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
    private readonly ImageDecoder $decoder;

    public function __construct(
        private readonly LayoutEngine $engine,
        private readonly Translator $translator,
        // Defaulted like $styles (the StylesDefault pattern): a decoder is pure,
        // so the widget-tree tests need not thread one through.
        ?ImageDecoder $decoder = null,
        ?Scheduler $scheduler = null,
        // The `graphRender` setting; anything unrecognized reads as the picture.
        #[Autowire('%env(default::PORTA_GRAPH_RENDER)%')]
        ?string $renderMode = null,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
        $this->mode = GraphRenderMode::fromId($renderMode);
        $this->decoder = $decoder ?? new ImageDecoder();
        $this->pending = new DebouncedRequest($scheduler ?? new RevoltScheduler(), self::RENDER_DEBOUNCE);
        $this->scaled = new ScaledSource();
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
        $this->dot = $dot;
        // The previous tab's picture must not paint under this tab's geometry;
        // the cell rendering covers the gap until the new bitmap lands.
        $this->bitmap = null;
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
        $this->dot = null;
        $this->bitmap = null;
        $this->pending->reset();
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

    /**
     * The continuous half of the same gesture: zoom by a factor on the same
     * anchor, for the burst of notches a trackpad's Ctrl+scroll delivers
     * ({@see \Porta\Input\ZoomGesture}).
     */
    public function magnifyAt(float $factor, int $relCol, int $relRow): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->magnifyAt($factor, $relCol, (2 * $relRow) + 1, $this->lastCols, 2 * $this->lastRows);
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
        // The fit scale depends on the pane size, which is only known here; the
        // wanted-dpi guard makes this a float compare on every other frame.
        $this->scheduleBitmap();
        $lines = $this->contentLines($cols, $rows);
        if ($this->isFocused() && $cols >= 3 && $rows >= 3) {
            $lines = $this->focusFrame($lines, $cols, $rows);
        }
        // The banner/progress row outranks the focus frame's top border — an
        // error (or in-flight hint) must never hide behind focus chrome.
        if (null !== $this->layout) {
            if (null !== $this->error) {
                $banner = ' ✗ '.explode("\n", $this->error)[0].' ';
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::DiagError)->apply($banner), $cols, pad: true);
            }
            if ($this->layingOut) {
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::Dim)->apply(' ⏳ '.$this->translator->trans('layout…').' '), $cols, pad: true);
            }
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
            $kb->matches($data, 'graph_render_mode') => $this->toggleRenderMode(),
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
            'graph_render_mode' => ['v'],
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
        $this->extentW = $width;
        $this->extentH = $height;
        $this->pending->reset(); // re-decide the resolution for this graph
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
        $this->scheduleBitmap();
    }

    /**
     * The dpi the picture needs to look sharp at $scale, capped so a huge graph
     * cannot ask for a bitmap the decoder would refuse. 72 dpi is 1:1 with the
     * layout, so magnifying 2× wants 144 and nothing below 1:1 wants more than
     * 72 (shrinking is the {@see ScaledSource} filter's job, not dot's).
     */
    private function dpiFor(float $scale): int
    {
        // CEIL, not round: a continuous zoom (the Ctrl+scroll glide) would
        // otherwise ask dot for a different dpi at every nudge, where ceiling to
        // the next whole multiple asks once per doubling and never undersamples.
        $wanted = self::BITMAP_DPI * (int) ceil(max(1.0, $scale));
        // pixels = extent × dpi/72 per axis, so the ceiling scales with 1/√area.
        $cap = (int) floor(self::BITMAP_DPI * sqrt(ImageDecoder::MAX_PIXELS / ($this->extentW * $this->extentH)));

        return max(1, min($wanted, $cap));
    }

    /**
     * Ask dot for a bitmap at the resolution the current zoom wants, once the
     * zoom has settled. Debounced and coalesced on the WANTED dpi, so a pan (or
     * any zoom that lands on the same resolution) costs nothing, and a spin of
     * the wheel costs one process rather than one per notch.
     */
    private function scheduleBitmap(): void
    {
        if (GraphRenderMode::Cells === $this->mode || null === $this->view || null === $this->dot) {
            return; // the cell rendering needs no process
        }
        $dpi = $this->dpiFor($this->view->effectiveScale($this->lastCols, 2 * $this->lastRows));
        $this->pending->want((float) $dpi, fn (float $value) => $this->requestBitmap((int) $value));
    }

    /**
     * Ask dot for the picture. One bitmap at a time, deliberately: a big graph
     * decodes to tens of megabytes of truecolor pixels, so caching one per tab
     * would be a memory trap — and re-rendering is one fast process, while the
     * expensive half (the layout) is still cached per DOT hash.
     */
    private function requestBitmap(int $dpi): void
    {
        $dot = $this->dot;
        // Only reachable through adopt(), which only runs after show() stored the
        // source it laid out.
        \assert(null !== $dot, description: 'a bitmap is only ever requested for a shown document');
        $hash = $this->dotHash;
        $this->engine->render(
            $dot,
            $dpi,
            function (string $png) use ($hash, $dpi): void {
                if ($hash !== $this->dotHash) {
                    return; // the tab moved on while dot was working
                }
                $image = $this->decoder->decodeBytes($png);
                if (null === $image) {
                    // Undecodable: keep whatever is on screen (a re-render must
                    // not blank a good picture) and say why it did not change.
                    $this->error = $this->translator->trans('the graph bitmap could not be decoded');
                    $this->invalidate();

                    return;
                }
                $this->bitmap = $image;
                $this->bitmapDpi = $dpi;
                $this->invalidate();
                $this->handler?->viewChanged();
            },
            function (string $message) use ($hash): void {
                if ($hash === $this->dotHash) {
                    // No picture: the cell rendering keeps the pane usable and
                    // the banner says why (a dot without libgd lands here).
                    $this->error = $message;
                    $this->invalidate();
                }
            },
        );
    }

    /**
     * Swap the picture for the readable cells and back. Switching TO the picture
     * asks for one if this pane never had reason to render it (the persisted
     * default was the cells), which is why it is not just an invalidate.
     */
    private function toggleRenderMode(): void
    {
        $this->mode = $this->mode->toggled();
        $this->invalidate();
        $this->scheduleBitmap();
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
        $this->scheduleBitmap();
    }

    /** @return list<string> */
    private function contentLines(int $cols, int $rows): array
    {
        // A bitmap only exists after a layout was adopted, so the overlay always
        // has the geometry it needs to frame the selection.
        if (GraphRenderMode::Bitmap === $this->mode && null !== $this->bitmap && null !== $this->view && null !== $this->layout) {
            $lines = HalfBlockRasterizer::render(
                $this->scaled->forScale(
                    $this->bitmap,
                    $this->view->effectiveScale($cols, 2 * $rows),
                    ratio: $this->bitmapDpi / self::BITMAP_DPI,
                ),
                $this->view,
                $cols,
                $rows,
            );

            return GraphSelectionOverlay::apply($lines, $this->layout, $this->view, $this->selected, $cols, $rows, $this->styles);
        }
        if (null !== $this->layout && null !== $this->view) {
            return GraphRenderer::render($this->layout, $this->view, $this->selected, $cols, $rows, $this->styles);
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
