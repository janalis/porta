<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Porta\GraphViewer\Graph\DiagramEngine;
use Porta\GraphViewer\Graph\DiagramKind;
use Porta\GraphViewer\Graph\GraphLayout;
use Porta\GraphViewer\Graph\GraphRenderMode;
use Porta\GraphViewer\Graph\LayoutEngine;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\GraphViewer\Render\GraphSelectionOverlay;
use Porta\I18n\Translator;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\ImageViewer\ZoomPanPane;
use Porta\Support\DebouncedRequest;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Support\Text;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\NavigablePaneWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;

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
final class GraphViewerWidget extends NavigablePaneWidget
{
    use ZoomPanPane;

    /** The graph zoom ladder: fit scales for wide graphs sit far below the image ladder's 12.5% floor. */
    private const array ZOOM_STEPS = [0.031_25, 0.062_5, 0.125, 0.25, 0.5, 1.0, 2.0, 4.0];

    private const int LAYOUT_CACHE_CAP = 8;

    /** 72 dpi = one point per pixel, so the bitmap shares the layout's coordinates. */
    private const int BITMAP_DPI = 72;

    private ?string $path = null;
    private ?string $dotHash = null;
    /** The source of the shown tab — kept because a bitmap is re-rendered from it. */
    private ?string $dot = null;
    /** Which tool draws it; a PlantUML tab has a picture and no geometry at all. */
    private DiagramKind $kind = DiagramKind::Dot;
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
    private readonly ImageDecoder $decoder;

    public function __construct(
        private readonly LayoutEngine $engine,
        private readonly Translator $translator,
        // Optional so the widget-tree tests need not thread one through; a null
        // one simply means this instance cannot draw PlantUML.
        private readonly ?DiagramEngine $plantUml = null,
        // Defaulted like $styles (the StylesDefault pattern): a decoder is pure,
        // so the widget-tree tests need not thread one through.
        ?ImageDecoder $decoder = null,
        ?Scheduler $scheduler = null,
        // The `renderMode` setting, read by the plugin file and passed in;
        // anything unrecognized reads as the picture. A later Settings edit
        // arrives through {@see setRenderMode()}.
        ?string $renderMode = null,
        ?Styles $styles = null,
        // Trailing and optional, the way a dispatcher is: the widget knows the
        // key was pressed and which nodes exist, and nothing else. Opening an
        // overlay is the PLUGIN's to do — it holds the api — and the widget
        // tests need not thread one through to exercise everything else.
        private readonly ?\Closure $onFindNode = null,
    ) {
        $this->initStyles($styles);
        // Hand-built (a plugin class cannot be a container service), so the id
        // the #[AsWidget] attribute used to apply is set here — tests and the
        // hit-tester both look widgets up by it.
        $this->setId('graph-viewer');
        $this->mode = GraphRenderMode::fromId($renderMode);
        $this->decoder = $decoder ?? new ImageDecoder();
        $this->pending = new DebouncedRequest($scheduler ?? new RevoltScheduler(), self::RENDER_DEBOUNCE);
        $this->scaled = new ScaledSource();
    }

    /** Show a DOT document: adopt the cached layout for its hash, or run the engine. */
    public function paneId(): string
    {
        return 'graph';
    }

    /** @param mixed $payload the DOT (or PlantUML) source this pane's loader read */
    public function show(string $path, mixed $payload): void
    {
        $dot = (string) $payload;
        $hash = \hash('xxh128', $dot);
        if ($path === $this->path && $hash === $this->dotHash) {
            return; // same tab, same content — nothing to do
        }
        $this->path = $path;
        $this->dotHash = $hash;
        $this->dot = $dot;
        $this->kind = DiagramKind::forPath($path) ?? DiagramKind::Dot;
        // The previous tab's picture must not paint under this tab's geometry;
        // the cell rendering covers the gap until the new bitmap lands.
        $this->bitmap = null;
        $this->selected = null;
        if (!$this->kind->hasLayout()) {
            // Nothing to lay out: the picture is the whole tab, and its first
            // render at 1:1 is what establishes the extent.
            $this->layout = null;
            $this->view = null;
            $this->selectionOrder = [];
            $this->error = null === $this->plantUml?->binary() ? 'missing-plantuml' : null;
            $this->pending->reset();
            $this->invalidate();
            $this->scheduleRefine();

            return;
        }
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
                    \array_shift($this->layoutCache);
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

    /** The status bar's graph summary: node/edge counts plus the selected node's label. */
    public function statusInfo(): string
    {
        if (null === $this->layout) {
            return '';
        }
        $info = \sprintf('%d nodes · %d edges', $this->layout->nodeCount(), $this->layout->edgeCount());
        $selected = null !== $this->selected ? $this->layout->nodes[$this->selected] ?? null : null;

        return null !== $selected ? $info.' · '.\strtr($selected->label, ["\n" => ' ']) : $info;
    }

    /** This pane's status-segment key hints (n/p select, unlike the image pane's). */
    protected function keysHint(): string
    {
        return $this->translator->trans('+/- zoom  0 fit  n/p select  / find  Ctrl+Q quit');
    }

    /**
     * A press arms the drag-pan: the world moves with the pointer, and a release
     * that never moved is the click that selects a node.
     */
    public function press(int $relRow, int $relCol): bool
    {
        return true;
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
        $this->scheduleRefine();
        $lines = $this->contentLines($cols, $rows);
        if ($this->isFocused() && $cols >= 3 && $rows >= 3) {
            $lines = $this->focusFrame($lines, $cols, $rows);
        }
        // The banner/progress row outranks the focus frame's top border — an
        // error (or in-flight hint) must never hide behind focus chrome.
        if (null !== $this->layout) {
            if (null !== $this->error) {
                $lines[0] = $this->errorBanner($this->error, $cols);
            }
            if ($this->layingOut) {
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::Dim)->apply(' ⏳ '.$this->translator->trans('layout…').' '), $cols, pad: true);
            }
        }

        return $lines;
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
            $kb->matches($data, 'graph_find_node') => $this->findNode(),
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
            'graph_find_node' => ['/'],
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
        $width = \max(1, (int) \ceil($layout->width));
        $height = \max(1, (int) \ceil($layout->height));
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
        \sort($order);
        $this->selectionOrder = \array_map(static fn (array $entry): int => (int) $entry[2], $order);
        $this->invalidate();
        $this->handler?->viewChanged();
        $this->scheduleRefine();
    }

    /** The tool that draws the shown tab, or null when this instance has none for it. */
    private function drawingEngine(): ?DiagramEngine
    {
        return $this->kind->hasLayout() ? $this->engine : $this->plantUml;
    }

    /**
     * The dpi the picture needs to look sharp at $scale, capped so a huge graph
     * cannot ask for a bitmap the decoder would refuse. 72 dpi is 1:1 with the
     * layout, so magnifying 2× wants 144 and nothing below 1:1 wants more than
     * 72 (shrinking is the {@see ScaledSource} filter's job, not dot's).
     */
    private function dpiFor(float $scale): int
    {
        // The tools disagree about 1:1 — 72 for dot (one point per pixel), 96
        // for PlantUML — so the multiples are of whichever is drawing.
        $base = $this->drawingEngine()?->baseDpi() ?? self::BITMAP_DPI;
        // CEIL, not round: a continuous zoom (the Ctrl+scroll glide) would
        // otherwise ask for a different dpi at every nudge, where ceiling to
        // the next whole multiple asks once per doubling and never undersamples.
        $wanted = $base * (int) \ceil(\max(1.0, $scale));
        // pixels = extent × dpi/base per axis, so the ceiling scales with 1/√area.
        $cap = (int) \floor($base * \sqrt(ImageDecoder::MAX_PIXELS / ($this->extentW * $this->extentH)));

        return \max(1, \min($wanted, $cap));
    }

    /**
     * Ask dot for a bitmap at the resolution the current zoom wants, once the
     * zoom has settled. Debounced and coalesced on the WANTED dpi, so a pan (or
     * any zoom that lands on the same resolution) costs nothing, and a spin of
     * the wheel costs one process rather than one per notch.
     */
    protected function scheduleRefine(): void
    {
        if (null === $this->dot || null === $this->drawingEngine()) {
            return;
        }
        if (GraphRenderMode::Cells === $this->mode && $this->kind->hasLayout()) {
            return; // the cell rendering needs no process (and only dot has one)
        }
        // A tab with no geometry has no view until its first render lands, and
        // that render is what creates one — at the tool's own 1:1.
        $dpi = null === $this->view
            ? $this->dpiFor(1.0)
            : $this->dpiFor($this->view->effectiveScale($this->lastCols, 2 * $this->lastRows));
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
        $engine = $this->drawingEngine();
        \assert(null !== $engine, description: 'a bitmap is only ever requested for a tool that exists');
        $engine->render(
            $dot,
            $dpi,
            function (string $png) use ($hash, $dpi, $engine): void {
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
                // With no layout there is nothing else to size the zoom model
                // from, so the first render does it — and only that one may, for
                // the reason the image viewer's probe records: a re-render at a
                // higher dpi must not redefine its own coordinate space.
                if (null === $this->view) {
                    $base = $engine->baseDpi();
                    $this->extentW = \max(1, (int) \round(($image->width * $base) / $dpi));
                    $this->extentH = \max(1, (int) \round(($image->height * $base) / $dpi));
                    $this->view = new ZoomPanModel($this->extentW, $this->extentH, self::ZOOM_STEPS);
                }
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
        if (!$this->kind->hasLayout()) {
            return; // there is no cell rendering of a picture
        }
        $this->apply($this->mode->toggled());
    }

    /**
     * The Settings page wrote `renderMode`. Unlike the in-pane toggle this
     * does not consult {@see GraphKind::hasLayout()}: the setting is the
     * standing preference for every document this pane will show, and a
     * picture that cannot honour it now still should when a DOT file arrives.
     */
    public function setRenderMode(?string $id): void
    {
        $mode = GraphRenderMode::fromId($id);
        if ($mode !== $this->mode) {
            $this->apply($mode);
        }
    }

    /** Adopt $mode and repaint through it: invalidate, refine, tell the tab. */
    private function apply(GraphRenderMode $mode): void
    {
        $this->mode = $mode;
        $this->invalidate();
        $this->scheduleRefine();
        $this->handler?->viewChanged();
    }

    /** n/p: walk the (y, x)-ordered node list, panning the selection into view. */
    private function cycleSelection(int $dir): void
    {
        if ([] === $this->selectionOrder) {
            return;
        }
        $count = \count($this->selectionOrder);
        $index = $dir > 0 ? -1 : 0;
        if (null !== $this->selected) {
            $index = (int) \array_search($this->selected, $this->selectionOrder, strict: true);
        }
        $this->focusNode($this->selectionOrder[((($index + $dir) % $count) + $count) % $count]);
    }

    /**
     * Ask for the node picker. A picture with no layout has no nodes, and a
     * pane built without the closure simply has no picker.
     */
    private function findNode(): void
    {
        $find = $this->onFindNode;
        if (null === $this->layout || null === $find) {
            return;
        }
        $find();
    }

    /**
     * Select $id and pan just enough that it is on screen.
     *
     * Shared by `n`/`p` and the node picker deliberately: "which node is
     * selected" and "where the viewport is" are one fact, and a picker that
     * selected a node three screens away without moving would be a search that
     * finds nothing you can see.
     */
    public function focusNode(int $id): void
    {
        $node = $this->layout->nodes[$id] ?? null;
        if (null === $node || null === $this->view) {
            return;
        }
        $this->selected = $id;
        $scale = $this->view->effectiveScale($this->lastCols, 2 * $this->lastRows);
        $rect = $this->view->visibleRect($this->lastCols, 2 * $this->lastRows);
        $dx = (int) \round(($node->x - ($rect['srcX'] + (($this->lastCols / $scale) / 2))) * $scale);
        $dy = (int) \round(($node->y - ($rect['srcY'] + ($this->lastRows / $scale))) * $scale);
        $this->view->panBy($dx, $dy, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
    }

    /**
     * Every node as a picker row, in the same (y, x) order `n`/`p` walks.
     *
     * The hint is the node's DEGREE, which is the one fact about a node that a
     * list can show and the picture cannot: in an architecture graph the hubs
     * are what you are looking for, and they are invisible in an alphabetical
     * list of two hundred namespaces.
     *
     * @return list<array{label: string, hint: string, value: string}>
     */
    public function nodeChoices(): array
    {
        if (null === $this->layout) {
            return [];
        }
        $in = [];
        $out = [];
        foreach ($this->layout->edges as $edge) {
            $out[$edge->tail] = ($out[$edge->tail] ?? 0) + 1;
            $in[$edge->head] = ($in[$edge->head] ?? 0) + 1;
        }
        $rows = [];
        foreach ($this->selectionOrder as $id) {
            // Direct, not guarded: the cycling order is BUILT from these nodes,
            // so a missing one is a broken invariant rather than a case — the
            // same access {@see cycleSelection()} has always made.
            $node = $this->layout->nodes[$id];
            $rows[] = [
                'label' => \strtr($node->label, ["\n" => ' ']),
                'hint' => $this->translator->trans('%in% in · %out% out', ['%in%' => $in[$id] ?? 0, '%out%' => $out[$id] ?? 0]),
                'value' => (string) $id,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function contentLines(int $cols, int $rows): array
    {
        $view = $this->view;
        if (GraphRenderMode::Bitmap === $this->mode && null !== $this->bitmap && null !== $view) {
            $lines = HalfBlockRasterizer::render(
                $this->scaled->forScale(
                    $this->bitmap,
                    $view->effectiveScale($cols, 2 * $rows),
                    // The raster's own density: dpi over whatever this tool calls 1:1.
                    ratio: $this->bitmapDpi / ($this->drawingEngine()?->baseDpi() ?? self::BITMAP_DPI),
                ),
                $view,
                $cols,
                $rows,
            );

            // Only a tab WITH geometry has a selection to frame.
            return null === $this->layout
                ? $lines
                : GraphSelectionOverlay::apply($lines, $this->layout, $view, $this->selected, $cols, $rows, $this->styles);
        }
        if (null !== $this->layout && null !== $this->view) {
            return GraphRenderer::render($this->layout, $this->view, $this->selected, $cols, $rows, $this->styles);
        }
        $message = match (true) {
            'missing-binary' === $this->error => $this->translator->trans('graphviz not found — install graphviz, or set the "dotPath" setting'),
            'missing-plantuml' === $this->error => $this->translator->trans('plantuml not found — install it, or set the "plantumlPath" setting'),
            null !== $this->error => Text::firstLine($this->error),
            $this->layingOut => '⏳ '.$this->translator->trans('layout…'),
            default => '',
        };
        $style = 'missing-binary' === $this->error || null !== $this->error && !$this->layingOut ? Role::DiagError : Role::Dim;

        return $this->messagePane($message, $style, $cols, $rows);
    }
}
