<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Image\HalfBlockRasterizer;
use Porta\Image\ImageData;
use Porta\Image\ZoomPanModel;
use Porta\Ui\Glyphs;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
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
 * The image tab's pane: renders the active image as truecolor half-blocks
 * (via {@see HalfBlockRasterizer}) and owns its zoom/pan. It replaces the
 * code editor as the editor-pane's content while an image buffer is active,
 * so the tab-cycling / close / go-to-file keys are re-bound here and routed
 * back to the IDE through {@see ImageViewerHandler}.
 */
#[AsIdeService]
#[AsWidget('image-viewer')]
final class ImageViewerWidget extends AbstractWidget implements FocusableInterface, LtrPane, MenuOverlayHost, VerticallyExpandableInterface
{
    use FillsPaneTrait;
    use FocusableTrait;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use StylesDefault;

    private ?ImageData $image = null;
    private ?ZoomPanModel $view = null;
    /** last rendered pane size, so key handling scales/pans what is on screen */
    private int $lastCols = 80;
    private int $lastRows = 24;
    private ?ImageViewerHandler $handler = null;

    public function __construct(?Styles $styles = null)
    {
        $this->initStyles($styles);
    }

    public function setHandler(ImageViewerHandler $handler): void
    {
        $this->handler = $handler;
    }

    /** Show an image, resetting the view to fit-to-pane. */
    public function show(ImageData $image): void
    {
        $this->image = $image;
        $this->view = new ZoomPanModel($image->width, $image->height);
        $this->invalidate();
    }

    /** Drop the image reference (frees the GdImage once the buffer is gone too). */
    public function clear(): void
    {
        $this->image = null;
        $this->view = null;
        $this->invalidate();
    }

    /** The status bar's zoom text ('Fit (37%)' / '200%'), '' when nothing is shown. */
    public function zoomLabel(): string
    {
        return null === $this->view ? '' : $this->view->zoomLabel($this->lastCols, 2 * $this->lastRows);
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

    /** Pan by wheel scrolling (in half-rows vertically, columns horizontally). */
    public function scrollBy(int $dxCells, int $dyHalfRows): void
    {
        if (null === $this->view) {
            return;
        }
        $this->view->panBy($dxCells, $dyHalfRows, $this->lastCols, 2 * $this->lastRows);
        $this->afterViewChange();
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
        $lines =
            null === $this->image || null === $this->view
                ? array_fill(0, $rows, str_repeat(' ', $cols))
                : HalfBlockRasterizer::render($this->image, $this->view, $cols, $rows);

        // A caret-less, row-less pane still needs a focus cue like its siblings:
        // a box-drawing frame drawn over the image's edge ring while focused. The
        // glyph carries it (accent-tinted with colour, a bare box under --no-ansi).
        if ($this->isFocused() && $cols >= 3 && $rows >= 3) {
            $lines = $this->focusFrame($lines, $cols, $rows);
        }

        return $this->withMenuOverlay($lines, $cols);
    }

    /**
     * Overlay the focus frame on the outer ring, keeping every line $cols wide
     * (the box glyphs are one column). The middle rows keep the image between
     * their side rails via a column slice, so the pan/zoom geometry is untouched.
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

    public function handleInput(string $data): void
    {
        if ('+' === $data) { // KeyParser cannot express a bare '+' spec; alias it here.
            $this->zoom(1);

            return;
        }
        $kb = $this->getKeybindings();
        $pxRows = 2 * $this->lastRows;
        match (true) {
            $kb->matches($data, 'image_zoom_in') => $this->zoom(1),
            $kb->matches($data, 'image_zoom_out') => $this->zoom(-1),
            $kb->matches($data, 'image_zoom_fit') => $this->withView(static fn (ZoomPanModel $v) => $v->fit()),
            $kb->matches($data, 'image_zoom_actual') => $this->withView(fn (ZoomPanModel $v) => $v->actualSize($this->lastCols, $pxRows)),
            $kb->matches($data, 'image_pan_left') => $this->pan(-2, 0, $data),
            $kb->matches($data, 'image_pan_right') => $this->pan(2, 0, $data),
            $kb->matches($data, 'image_pan_up') => $this->pan(0, -4, $data),
            $kb->matches($data, 'image_pan_down') => $this->pan(0, 4, $data),
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
            'image_zoom_in' => ['='],
            'image_zoom_out' => ['-'],
            'image_zoom_fit' => ['0', 'f'],
            'image_zoom_actual' => ['1'],
            'image_pan_left' => [Key::LEFT, 'shift+left'],
            'image_pan_right' => [Key::RIGHT, 'shift+right'],
            'image_pan_up' => [Key::UP, 'shift+up'],
            'image_pan_down' => [Key::DOWN, 'shift+down'],
            // Re-bound to the editor's defaults so tab management keeps working
            // while the code editor is detached (see EditorKeymap).
            'next_buffer' => ['ctrl+right'],
            'prev_buffer' => ['ctrl+left'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],
            'save_all' => ['ctrl+alt+s'],
        ];
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
}
