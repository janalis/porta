<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Ui\FrameHooks;
use Porta\Ui\Theme\Styles;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\SeparatorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The editor beside its live preview: one {@see PaneSplit} holding the code
 * editor, a drag divider and the preview pane, which {@see
 * \Porta\Editor\BufferService} mounts into the editor body's content slot for
 * a previewed buffer in Split.
 *
 * What is the preview's own: there is one, for every previewed file, so it is
 * a service the layout controller persists; and a split too narrow for both
 * sides steps the preview aside, where a view's columns would stack.
 *
 * A preview with a {@see EditorPaneWidget::flank()} — the merge view's ours,
 * the editor's result, its theirs — is seated in two nested splits instead:
 * `flanked` holds the flank-and-editor pair against the pane, `flank` holds
 * the flank against the editor. Each has its own divider and its own kept
 * proportion, and a narrowing area steps the flank aside first, then the pane,
 * so the editor is the last to go.
 */
#[AsEager]
#[AsIdeService]
final readonly class PreviewSplit
{
    /** The narrowest either side may be dragged to, and below twice which the preview steps aside. */
    public const int MIN_SIDE = PaneSplit::MIN_SIDE;

    /** The editor | preview split. */
    public const string PLAIN = 'preview';

    /** The (flank | editor) | preview split. */
    public const string FLANKED = 'flanked';

    /** The flank | editor split inside {@see FLANKED}. */
    public const string FLANK = 'flank';

    /** What the slot seats: the plain split's area, or the flanked one's. */
    public ContainerWidget $area;

    /** The plain split's divider. */
    public SeparatorWidget $separator;

    private PaneSplit $split;

    private PaneSplit $flanked;

    private PaneSplit $flank;

    public function __construct(
        Styles $styles,
        ?FrameHooks $frames = null,
        ?Renderer $renderer = null,
        ?Tui $tui = null,
    ) {
        $this->split = new PaneSplit('preview-split', $styles, NarrowSplit::LeadAlone, $renderer, $tui);
        // Three equal columns until dragged: the pair takes two of them and
        // its own divider — a shade over two thirds, so the floors the layout
        // takes land the three even — and the flank half of the pair.
        // Its lead is the flank's split: when the pane steps aside with the
        // keys, the editor in it takes them, never the container.
        $this->flanked = new PaneSplit('preview-flanked', $styles, NarrowSplit::LeadAlone, $renderer, $tui, share: 672, keysTo: fn (): ?AbstractWidget => $this->flank->rest());
        $this->flank = new PaneSplit('preview-flank', $styles, NarrowSplit::RestAlone, $renderer, $tui);
        $this->area = LayoutWidgets::container('preview-host', new Style(flex: 1));
        $this->separator = $this->split->separator;
        // Outer before inner: the inner split reads a rect its outer one has just sized.
        $frames?->onBeforeFrame($this->split->fit(...));
        $frames?->onBeforeFrame($this->flanked->fit(...));
        $frames?->onBeforeFrame($this->flank->fit(...));
    }

    /**
     * Lend the editor and its preview to the split (see {@see PaneSplit::mount()}),
     * and the preview's flank, when it has one, to the editor's other side.
     */
    public function mount(AbstractWidget $editor, AbstractWidget $preview): void
    {
        $flank = $preview instanceof EditorPaneWidget ? $preview->flank() : null;
        $seat = null === $flank ? $this->plain($editor, $preview) : $this->flanked($flank, $editor, $preview);
        $this->area->clear();
        $this->area->add($seat);
    }

    /**
     * Editor | preview, leaving the flanked arrangement. Only the one being
     * left is released: each split skips a mount of what it already holds, so
     * the same preview again detaches nothing.
     */
    private function plain(AbstractWidget $editor, AbstractWidget $preview): ContainerWidget
    {
        $this->flanked->release();
        $this->flank->release();
        $this->split->mount($editor, $preview);

        return $this->split->area;
    }

    /** Flank | editor | preview, leaving the plain arrangement. */
    private function flanked(EditorPaneWidget $flank, AbstractWidget $editor, AbstractWidget $preview): ContainerWidget
    {
        $this->split->release();
        $this->flank->mount($flank, $editor);
        $this->flanked->mount($this->flank->area, $preview);

        return $this->flanked->area;
    }

    /** Hand the lent widgets back — detached, so the caller can seat them elsewhere. */
    public function release(): void
    {
        $this->area->clear();
        $this->split->release();
        $this->flanked->release();
        $this->flank->release();
    }

    /** The pane lent to the split right now, or null when nothing is mounted. */
    public function preview(): ?AbstractWidget
    {
        return $this->split->rest() ?? $this->flanked->rest();
    }

    /** The preview's flank seated on the editor's other side, or null. */
    public function flankPane(): ?AbstractWidget
    {
        return $this->flank->lead();
    }

    /** The editor's share of the plain split, as the percent the layout file keeps. */
    public function percent(): int
    {
        return $this->split->percent();
    }

    /** Restore a persisted proportion; out-of-range values are clamped rather than refused. */
    public function setPercent(int $percent): void
    {
        $this->split->setPercent($percent);
    }

    /**
     * Every split, by the key its proportion is kept under — the layout
     * controller restores, drags, nudges and persists each the same way.
     *
     * @return array<self::PLAIN|self::FLANKED|self::FLANK, PaneSplit>
     */
    public function splits(): array
    {
        return [self::PLAIN => $this->split, self::FLANKED => $this->flanked, self::FLANK => $this->flank];
    }

    /**
     * Every divider: the plain split's and the two a flanked preview adds.
     * Each is detached — and so skipped by whatever hit-tests it — while its
     * split is not on screen.
     *
     * @return list<SeparatorWidget>
     */
    public function separators(): array
    {
        return \array_values(\array_map(static fn (PaneSplit $split): SeparatorWidget => $split->separator, $this->splits()));
    }

    /**
     * The key of the split a keyboard nudge from $focus moves: the flank's
     * own divider from the flank, the pane's from the editor or the pane — or
     * null when $focus is in none of them.
     *
     * @return self::PLAIN|self::FLANKED|self::FLANK|null
     */
    public function splitFor(?object $focus): ?string
    {
        return match (true) {
            null === $focus || null === $this->area->getContext() => null,
            $focus === $this->flankPane() => self::FLANK,
            $focus === $this->split->lead() || $focus === $this->split->rest() => self::PLAIN,
            $focus === $this->flank->rest() || $focus === $this->flanked->rest() => self::FLANKED,
            default => null,
        };
    }

    /** The area's last rect while it is on screen, else null. */
    public function rect(): ?WidgetRect
    {
        return $this->split->rect();
    }

    /** Seat the editor on the reading direction's leading side. */
    public function setMirrored(bool $mirrored): void
    {
        foreach ($this->splits() as $split) {
            $split->setMirrored($mirrored);
        }
    }
}
