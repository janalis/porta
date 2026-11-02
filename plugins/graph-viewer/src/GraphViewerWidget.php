<?php

declare(strict_types=1);

namespace Porta\GraphViewer;

use Janalis\Viz\GraphViz\DotDiagnostics;
use Janalis\Viz\GraphViz\DotSource;
use Janalis\Viz\Layout\GraphLayout;
use Janalis\Viz\Layout\GraphNode;
use Janalis\Viz\Mermaid\MermaidScene;
use Porta\GraphViewer\Graph\DiagramEngine;
use Porta\GraphViewer\Graph\DiagramKind;
use Porta\GraphViewer\Graph\GraphRenderMode;
use Porta\GraphViewer\Graph\LayoutEngine;
use Porta\GraphViewer\Mermaid\MermaidCliPicture;
use Porta\GraphViewer\Mermaid\MermaidCliRenderer;
use Porta\GraphViewer\Mermaid\MermaidHitTest;
use Porta\GraphViewer\Mermaid\MermaidTask;
use Porta\GraphViewer\Mermaid\NativeMermaidRenderer;
use Porta\GraphViewer\Render\GraphRenderer;
use Porta\GraphViewer\Render\GraphSelectionOverlay;
use Porta\I18n\Translator;
use Porta\ImageViewer\HalfBlockRasterizer;
use Porta\ImageViewer\ImageData;
use Porta\ImageViewer\ImageDecoder;
use Porta\ImageViewer\PixelOutline;
use Porta\ImageViewer\PixelPlacement;
use Porta\ImageViewer\ScaledSource;
use Porta\ImageViewer\ZoomPanModel;
use Porta\ImageViewer\ZoomPanPane;
use Porta\Support\DebouncedRequest;
use Porta\Support\RevoltScheduler;
use Porta\Support\Scheduler;
use Porta\Support\Text;
use Porta\Ui\Glyphs;
use Porta\Ui\Graphics\TerminalGraphics;
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

    /** Whether this tab has a layout to select in and draw as cells: a DOT tab, with `dot` there to lay it out. */
    private bool $geometry = true;
    /** The picture dot rendered, or null while none has arrived for this graph. */
    private ?ImageData $bitmap = null;
    /** A native or mapped CLI picture shares the current scene geometry. */
    private bool $nativeBitmap = false;
    /** The dpi the shown bitmap was rendered at — its pixels per layout point. */
    private int $bitmapDpi = self::BITMAP_DPI;
    /** Coalesces and debounces the re-render the current zoom wants. */
    private readonly DebouncedRequest $pending;
    private readonly DiagramBitmapRenderer $bitmaps;
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

    /** @var array<string, bool> whether each path's source was last told to have errors */
    private array $erring = [];

    /** The shown source's first error, for a pane shown without its text; null while it has none. */
    private ?string $sourceError = null;

    /** Whether the source is on screen beside this pane, its errors with it ({@see textBeside()}). */
    private bool $besideText = false;
    private bool $layingOut = false;
    private bool $resumePicture = false;
    /** The selected node's _gvid. */
    private ?int $selected = null;
    /** @var list<int> node ids in (y, x) order — the n/p cycling order */
    private array $selectionOrder = [];
    /** @var array<string, GraphLayout> dot-hash => layout, LRU-capped; survives tab switches */
    private array $layoutCache = [];
    /** @var array<string, array{ZoomPanModel, int, int}> path => [view, extent w, extent h], preserved across regenerations */
    private array $viewCache = [];
    private readonly NativeMermaidRenderer $native;
    private ?MermaidScene $nativeScene = null;
    /** @var array<string, MermaidScene> */
    private array $nativeCache = [];
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
        // Trailing and optional for the same reason: without it the picture
        // paints as half-blocks, as it does until the terminal has answered.
        ?TerminalGraphics $graphics = null,
        // Trailing and optional again: publishing diagnostics is the plugin
        // api's, which only the plugin file holds. Told the source's errors
        // for a path, 0-based, as the api takes them; an empty list clears.
        private readonly ?\Closure $onSourceErrors = null,
        private readonly ?DiagramEngine $mermaid = null,
        ?NativeMermaidRenderer $native = null,
        ?MermaidCliRenderer $cli = null,
    ) {
        $this->initStyles($styles);
        $this->bitmaps = new DiagramBitmapRenderer($cli);
        // Hand-built (a plugin class cannot be a container service), so the id
        // the #[AsWidget] attribute used to apply is set here — tests and the
        // hit-tester both look widgets up by it.
        $this->setId('graph-viewer');
        $this->mode = GraphRenderMode::fromId($renderMode);
        $this->decoder = $decoder ?? new ImageDecoder();
        $this->native = $native ?? new NativeMermaidRenderer(new MermaidTask($scheduler ?? new RevoltScheduler()));
        $this->pending = new DebouncedRequest($scheduler ?? new RevoltScheduler(), self::RENDER_DEBOUNCE);
        $this->scaled = new ScaledSource();
        $this->pixels = null !== $graphics ? new PixelPlacement($graphics, $this->afterViewChange(...)) : null;
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
        $kind = DiagramKind::forPath($path) ?? DiagramKind::Dot;
        $hash = \hash('xxh128', $kind->name."\0".$dot);
        if ($path === $this->path && $hash === $this->dotHash) {
            if ($this->resumePicture && !$this->geometry) {
                $this->resumePicture = false;
                $this->showPicture();
            }

            return; // same tab, same content — nothing to do
        }
        if ($path !== $this->path) {
            $this->sourceError = null; // another file's, until its own layout says
        }
        $changedPath = $path !== $this->path;
        $this->bitmaps->cancel($this->drawingEngine());
        $this->native->cancel();
        $this->pending->reset();
        $this->path = $path;
        $this->dotHash = $hash;
        $this->dot = $dot;
        $this->kind = $kind;
        $this->geometry = $kind->hasLayout() && null !== $this->engine->binary();
        // The previous tab's picture must not paint under this tab's geometry;
        // the cell rendering covers the gap until the new bitmap lands.
        if ($changedPath || DiagramKind::Mermaid !== $kind) {
            $this->nativeScene = null;
            $this->bitmap = null;
            $this->nativeBitmap = false;
        }
        $this->selected = null;
        match (true) {
            !$this->geometry => $this->showPicture(),
            null !== ($cached = $this->layoutCache[$hash] ?? null) => $this->adopt($cached),
            default => $this->startLayout($path, $dot, $hash),
        };
    }

    /**
     * Nothing to lay out: the picture is the whole tab, and its first
     * render at 1:1 is what establishes the extent.
     */
    private function showPicture(): void
    {
        $this->layout = null;
        if (null === $this->bitmap && null === $this->nativeScene) {
            $this->view = null;
        }
        $this->selectionOrder = [];
        $this->error = DiagramKind::Mermaid === $this->kind && null === $this->drawingEngine()?->binary() ? 'missing-mermaid' : null;
        if (DiagramKind::Mermaid === $this->kind && (GraphRenderMode::Cells === $this->mode || null !== $this->error)) {
            $this->requestNative(
                GraphRenderMode::Cells === $this->mode ? null : $this->translator->trans('mmdc not found — install mermaid-cli, or set the "mermaidPath" setting'),
            );
        }
        $this->pending->reset();
        $this->invalidate();
        $this->scheduleRefine();
    }

    private function startLayout(string $path, string $dot, string $hash): void
    {
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
            function (string $message) use ($path, $dot): void {
                $this->layingOut = false;
                // The previous good layout and view stay on screen either way.
                // What dot says is wrong with the source goes beside the text,
                // like any other error; only a failure of the tool itself —
                // missing, or answering nonsense — is the picture's to say.
                $errors = DotDiagnostics::parse($message, $dot);
                $this->error = [] === $errors ? $message : null;
                $this->sourceError = [] === $errors ? null : $this->translator->trans('Line %line%: %message%', ['%line%' => $errors[0]['line'] + 1, '%message%' => $errors[0]['message']]);
                $this->sourceErrors($path, $errors);
                $this->invalidate();
                $this->handler?->viewChanged();
            },
        );
    }

    /** Split shows the source's errors in the editor; alone, this pane banners them. */
    #[\Override]
    // @mago-expect lint:no-boolean-flag-parameter -- a state setter: it stores whether the source shows beside this pane, which decides where its errors go.
    public function textBeside(bool $beside): void
    {
        if ($beside !== $this->besideText) {
            $this->besideText = $beside;
            $this->invalidate();
        }
    }

    /** Detach the current tab (another buffer kind takes the pane); layout caches survive. */
    public function clear(): void
    {
        $this->bitmaps->cancel($this->drawingEngine());
        $this->native->cancel();
        $this->nativeScene = null;
        $this->path = null;
        $this->dotHash = null;
        $this->dot = null;
        $this->bitmap = null;
        $this->nativeBitmap = false;
        $this->pixels?->forget();
        $this->pending->reset();
        $this->layout = null;
        $this->view = null;
        $this->error = null;
        $this->sourceError = null;
        $this->layingOut = false;
        $this->selected = null;
        $this->selectionOrder = [];
        $this->invalidate();
    }

    /** Another pane took the slot: the terminal's copy is freed, and re-sent on return. */
    public function hide(): void
    {
        $this->resumePicture = true;
        $this->bitmaps->cancel($this->drawingEngine());
        $this->native->cancel();
        $this->pending->reset();
        $this->pixels?->forget();
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

        return null !== $selected ? $info.' · '.self::oneLine($selected->label) : $info;
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
        $layout = $this->layout;
        $view = $this->view;
        if (null === $layout || null === $view) {
            return;
        }
        // Over Graphviz's own picture, the node that picture shows in the cell;
        // over the cell drawing, the box drawn there.
        $this->selected = match (true) {
            $this->nativeBitmap && null !== $this->nativeScene => MermaidHitTest::nodeAt($this->nativeScene, $view, $this->lastCols, $this->lastRows, $relRow, $relCol),
            $this->showsBitmap() => GraphRenderer::nodeShownAt($layout, $view, $this->lastCols, $this->lastRows, $relRow, $relCol),
            default => GraphRenderer::nodeAt($this->nativeScene->selectionGraph ?? $layout, $view, $this->lastCols, $this->lastRows, $relRow, $relCol),
        };
        $this->afterViewChange();
        $this->revealSelected();
    }

    /**
     * The caret reached another line of the DOT: select and centre the node
     * written nearest it there, as a click on that node would have.
     */
    #[\Override]
    public function followSource(int $line, int $byteCol): void
    {
        if (DiagramKind::Mermaid === $this->kind) {
            $id = $this->nativeScene?->onLine($line);
            if (null !== $id && $id !== $this->selected) {
                $this->focusNode($id);
            }

            return;
        }
        $nodes = $this->layout->nodes ?? [];
        $name = DotSource::nodeAt((string) $this->dot, \array_values(\array_map(static fn (GraphNode $node): string => $node->name, $nodes)), $line, $byteCol);
        foreach ($nodes as $id => $node) {
            if ($node->name !== $name || $id === $this->selected) {
                continue;
            }
            $this->focusNode($id);

            return;
        }
    }

    /**
     * Click-to-source: the node just clicked, where the DOT text writes it.
     * A preview's text is the DOT this pane drew; a viewer tab's handler has
     * no text to go to and lets it pass.
     */
    private function revealSelected(): void
    {
        if (DiagramKind::Mermaid === $this->kind) {
            $line = $this->nativeScene?->origin($this->selected);
            if (null !== $line) {
                $this->handler?->revealSource($line, byteCol: 0);
            }

            return;
        }
        $node = null === $this->selected ? null : $this->layout?->nodes[$this->selected] ?? null;
        $offset = null === $node ? null : DotSource::declarationOf((string) $this->dot, $node->name);
        if (null !== $offset) {
            $this->handler?->revealSource(...Text::lineAndColumn((string) $this->dot, $offset));
        }
    }

    /** A theme switch re-bakes the SGR codes cached in rendered lines. */
    public function refreshTheme(): void
    {
        $this->invalidate();
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $lines = $this->framedLines($context);
        $cols = $context->getColumns();
        // The banner/progress row outranks the focus frame's top border — an
        // error (or in-flight hint) must never hide behind focus chrome.
        if ([] !== $lines && (null !== $this->layout || null !== $this->bitmap || null !== $this->nativeScene)) {
            // A source error is the editor's to show while the text is beside
            // this pane; shown alone, the picture has to say it.
            $banner = $this->error ?? ($this->besideText ? null : $this->sourceError);
            if (null !== $banner) {
                $lines[0] = $this->errorBanner($banner, $cols);
            }
            if ($this->layingOut) {
                $lines[0] = AnsiUtils::truncateToWidth($this->styles->of(Role::Dim)->apply(' '.Glyphs::WORKING.' '.$this->translator->trans('layout…').' '), $cols, pad: true);
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
            default => $this->tabKey($data),
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
            ...self::TAB_KEYS,
        ];
    }

    /** Adopt a layout: reuse the path's remembered view unless the extent changed (then fit fresh). */
    /**
     * Tell the plugin what is wrong with $path's source now, and only when
     * that changes: a path without errors that stays so is not re-told.
     *
     * @param list<array{line: int, message: string, severity: int, column: int}> $errors
     */
    private function sourceErrors(string $path, array $errors): void
    {
        if ([] === $errors && !($this->erring[$path] ?? false)) {
            return;
        }
        $this->erring[$path] = [] !== $errors;
        $this->onSourceErrors?->__invoke($path, $errors);
    }

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
        $this->sourceError = null;
        $this->sourceErrors($path, []); // laid out: the source has none
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
        return match ($this->kind) {
            DiagramKind::Dot => $this->engine,
            DiagramKind::PlantUml => $this->plantUml,
            DiagramKind::Mermaid => $this->mermaid,
        };
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
        // Drawn by the terminal, one column is a whole cell of screen pixels,
        // which the bitmap has to cover to look sharp.
        $perColumn = $this->pixelsPerColumn();
        $wanted = $base * (int) \ceil(\max(1.0, $scale * $perColumn));
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
        $engine = $this->drawingEngine();
        if (
            null === $this->dot
            || null === $engine
            || (DiagramKind::Mermaid === $this->kind && (GraphRenderMode::Cells === $this->mode || null === $engine->binary()))
            || (GraphRenderMode::Cells === $this->mode && $this->geometry)
        ) {
            return; // Native cells need no external render.
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
        $this->native->cancel();
        $this->bitmaps->render(
            $this->kind,
            $engine,
            $dot,
            $dpi,
            fn (MermaidCliPicture $picture) => $this->acceptBitmap($picture, $hash, $dpi, $engine),
            fn (string $message) => $this->bitmapError($message, $hash),
        );
    }

    private function acceptBitmap(MermaidCliPicture $picture, ?string $hash, int $dpi, DiagramEngine $engine): void
    {
        if ($hash !== $this->dotHash) {
            return;
        }
        $image = $this->decoder->decodeBytes($picture->png);
        $mapped = $picture->scene;
        if (null === $image) {
            $this->error = $this->translator->trans('the graph bitmap could not be decoded');
            $this->fallbackNative();
            $this->invalidate();

            return;
        }
        $this->native->cancel();
        $this->error = null;
        if (DiagramKind::Mermaid === $this->kind) {
            $this->nativeScene = $mapped;
            $this->layout = $mapped?->graph;
            // @mago-expect analysis:invalid-type-cast -- An absent map deliberately becomes an empty selection; null is initialized, not an error.
            $this->selectionOrder = \array_keys((array) $mapped?->elements);
            $this->selected = $mapped?->selectable($this->selected);
        }
        $this->nativeBitmap = null !== $mapped;
        $this->bitmap = $image;
        $this->bitmapDpi = $dpi;
        if (!$this->geometry) {
            $this->adoptPictureExtent($image, $dpi, $engine->baseDpi());
        }
        $this->invalidate();
        $this->handler?->viewChanged();
    }

    private function bitmapError(string $message, ?string $hash): void
    {
        if ($hash === $this->dotHash) {
            $this->error = $this->translator->trans($message);
            $this->fallbackNative();
            $this->invalidate();
        }
    }

    private function fallbackNative(): void
    {
        if (DiagramKind::Mermaid === $this->kind) {
            $this->requestNative($this->error);
        }
    }

    private function requestNative(?string $warning = null): void
    {
        $this->bitmaps->cancel($this->drawingEngine());
        $this->native->cancel();
        $source = $this->dot;
        $hash = $this->dotHash;
        if (null === $source || null === $hash) {
            return;
        }
        if (null !== ($cached = $this->nativeCache[$hash] ?? null)) {
            $this->adoptNative($cached, $warning);

            return;
        }
        $this->native->render(
            $source,
            function (MermaidScene $scene) use ($hash, $warning): void {
                $this->nativeCache[$hash] = $scene;
                if (\count($this->nativeCache) > self::LAYOUT_CACHE_CAP) {
                    \array_shift($this->nativeCache);
                }
                $this->adoptNative($scene, $warning);
            },
            function (string $message) use ($warning): void {
                $this->error = (null === $warning ? '' : $warning.' · ').$message;
                $this->invalidate();
            },
        );
    }

    private function adoptNative(MermaidScene $scene, ?string $warning): void
    {
        $this->bitmap = null;
        $this->nativeBitmap = false;
        $this->nativeScene = $scene;
        $this->layout = $scene->graph;
        $this->selectionOrder = \array_keys($scene->elements);
        $this->error = $warning;
        $width = \max(1, (int) \ceil($scene->graph->width));
        $height = \max(1, (int) \ceil($scene->graph->height));
        if (null === $this->view || $width !== $this->extentW || $height !== $this->extentH) {
            $this->extentW = $width;
            $this->extentH = $height;
            $this->view = new ZoomPanModel($width, $height, self::ZOOM_STEPS);
        }
        $this->invalidate();
        $this->handler?->viewChanged();
        if (GraphRenderMode::Bitmap === $this->mode) {
            $this->rasterNative($scene);
        }
    }

    private function rasterNative(MermaidScene $scene): void
    {
        $this->native->raster(
            $scene,
            function (string $png): void {
                $image = $this->decoder->decodeBytes($png);
                if (null !== $image) {
                    $this->bitmap = $image;
                    $this->nativeBitmap = true;
                    $this->invalidate();
                }
            },
            function (string $message): void {
                $this->error = (null === $this->error ? '' : $this->error.' · ').$message;
                $this->invalidate();
            },
        );
    }

    /** Retain zoom for the same natural dimensions, fit again when the diagram grows or shrinks. */
    private function adoptPictureExtent(ImageData $image, int $dpi, int $base): void
    {
        $width = \max(1, (int) \round(($image->width * $base) / $dpi));
        $height = \max(1, (int) \round(($image->height * $base) / $dpi));
        if (null !== $this->view && $width === $this->extentW && $height === $this->extentH) {
            return;
        }
        $this->extentW = $width;
        $this->extentH = $height;
        $this->view = new ZoomPanModel($width, $height, self::ZOOM_STEPS);
    }

    /**
     * Swap the picture for the readable cells and back. Switching TO the picture
     * asks for one if this pane never had reason to render it (the persisted
     * default was the cells), which is why it is not just an invalidate.
     */
    private function toggleRenderMode(): void
    {
        if (DiagramKind::PlantUml === $this->kind) {
            return; // PlantUML has no native cell renderer
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
        if (DiagramKind::Mermaid === $this->kind && (GraphRenderMode::Cells === $mode || null === $this->drawingEngine()?->binary())) {
            $this->bitmaps->cancel($this->drawingEngine());
            $this->native->cancel();
            $this->pending->reset();
            $this->requestNative();
        }
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
        if (DiagramKind::Mermaid === $this->kind && null === $this->nativeScene?->selectable($id)) {
            return;
        }
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
        foreach ($this->layout->relationships as $edge) {
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
                'label' => self::oneLine($node->label),
                'hint' => $this->translator->trans('%in% in · %out% out', ['%in%' => $in[$id] ?? 0, '%out%' => $out[$id] ?? 0]),
                'value' => (string) $id,
            ];
        }

        return $rows;
    }

    /** Whether the pane shows Graphviz's own picture rather than the cell drawing. */
    private function showsBitmap(): bool
    {
        return (
            !(DiagramKind::Mermaid === $this->kind && null !== $this->nativeScene && (GraphRenderMode::Cells === $this->mode || (null !== $this->error && !$this->nativeBitmap)))
            && (!$this->geometry || GraphRenderMode::Bitmap === $this->mode)
            && null !== $this->bitmap
        );
    }

    /** The selected node's outline, for a picture the terminal draws; null when nothing is selected. */
    private function selectionOutline(): ?PixelOutline
    {
        $node = null === $this->selected ? null : $this->layout?->nodes[$this->selected] ?? null;
        if (null === $node) {
            return null;
        }
        $rgb = $this->styles->of(Role::TabAccent)->getColor()?->toRgb() ?? ['r' => 255, 'g' => 255, 'b' => 255];

        return new PixelOutline($node->x - ($node->w / 2), $node->y - ($node->h / 2), $node->x + ($node->w / 2), $node->y + ($node->h / 2), [$rgb['r'], $rgb['g'], $rgb['b']]);
    }

    /** @return list<string> */
    private function contentLines(int $cols, int $rows): array
    {
        $view = $this->view;
        $bitmap = $this->bitmap;

        return match (true) {
            $this->showsBitmap() && null !== $bitmap && null !== $view => $this->bitmapLines($bitmap, $view, $cols, $rows),
            null !== $this->nativeScene && null !== $view => GraphRenderer::render($this->nativeScene->graph, $view, $this->selected, $cols, $rows, $this->styles),
            null !== $this->layout && null !== $this->view => GraphRenderer::render($this->layout, $this->view, $this->selected, $cols, $rows, $this->styles),
            default => $this->statusLines($cols, $rows),
        };
    }

    /** @return list<string> */
    private function bitmapLines(ImageData $bitmap, ZoomPanModel $view, int $cols, int $rows): array
    {
        // The raster's own density: dpi over whatever this tool calls 1:1.
        $ratio = $this->nativeBitmap && null !== $this->nativeScene
            ? $bitmap->width / \max(1.0, $this->nativeScene->graph->width)
            : $this->bitmapDpi / ($this->drawingEngine()?->baseDpi() ?? self::BITMAP_DPI);
        // Drawn by the terminal, the selection is stroked into the picture
        // itself, to the pixel; a frame of glyphs would blank the cells it
        // sits on.
        $drawn = $this->pixels?->render($bitmap, $ratio, $view, $cols, $rows, $this->selectionOutline());
        if (null !== $drawn) {
            return $drawn;
        }
        $lines = HalfBlockRasterizer::render(
            $this->scaled->forScale($bitmap, $view->effectiveScale($cols, 2 * $rows), $ratio),
            $view,
            $cols,
            $rows,
        );

        // Only a tab WITH geometry has a selection to frame.
        return null === $this->layout
            ? $lines
            : GraphSelectionOverlay::apply($lines, $this->layout, $view, $this->selected, $cols, $rows, $this->styles);
    }

    /**
     * Neither a picture nor geometry yet: what is missing, what failed, or that a layout is under way.
     *
     * @return list<string>
     */
    private function statusLines(int $cols, int $rows): array
    {
        $message = match (true) {
            'missing-mermaid' === $this->error => $this->translator->trans('mmdc not found — install mermaid-cli, or set the "mermaidPath" setting'),
            null !== $this->error => Text::firstLine($this->error),
            $this->layingOut => Glyphs::WORKING.' '.$this->translator->trans('layout…'),
            default => '',
        };
        $style = null !== $this->error && !$this->layingOut ? Role::DiagError : Role::Dim;

        return $this->messagePane($message, $style, $cols, $rows);
    }

    /** A label on one line; an empty card compartment leaves trailing blank lines. */
    private static function oneLine(string $label): string
    {
        return \trim(\strtr($label, ["\n" => ' ']));
    }
}
