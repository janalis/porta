<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Container\AsEager;
use Porta\Container\LayoutSlot;
use Porta\Editor\InlayHintMode;
use Porta\Editor\ProblemCards;
use Porta\I18n\Translator;
use Porta\Layout\PreviewSplit;
use Porta\Layout\ViewRegions;
use Porta\Overlay\State\ToastCenter;
use Porta\Overlay\State\TooltipCenter;
use Porta\Ui\FrameHooks;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\ContextMenu;
use Porta\Widget\DockTabsWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\SeparatorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\Support\GutterPointer;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Asks whatever owns the cell under the pointer what it means, on every
 * move, and hands the answer to {@see TooltipCenter} — which keeps the card,
 * starts its dwell, or drops it. The owners are asked in {@see HoverRouter}'s
 * order: a toast, then the open modal, then the workspace. An open menu owns
 * the pointer outright, so no card competes with its items.
 *
 * A resize moves every element, and a resting pointer sends no move to say
 * so: the frame after one asks again at the pointer's last cell, against the
 * rects that frame laid out, so the card follows its element or goes. That
 * frame hook is why it is eager: the pointer path would build it anyway, but
 * a hook must not depend on who asked first.
 */
#[AsEager]
final class TooltipRouter
{
    /** The last move, asked again after a resize; null before the first. */
    private ?MouseEvent $last = null;

    private ?string $lastView = null;

    /** @var array{int, int} the terminal's columns and rows at the last frame */
    private array $size = [0, 0];

    /** Whether the terminal was resized since the last frame's rects were laid out. */
    private bool $resized = false;

    public function __construct(
        private readonly HitTester $hit,
        private readonly Tui $tui,
        private readonly TooltipCenter $tooltips,
        private readonly EditorLayoutPanes $panes,
        private readonly MenuBarWidget $menuBar,
        private readonly ContextMenu $contextMenu,
        private readonly ToastCenter $toasts,
        private readonly ModalWindowRouter $windows,
        private readonly StatusBarWidget $statusBar,
        private readonly BottomDock $dock,
        private readonly DockTabsWidget $dockTabs,
        #[LayoutSlot('separator')]
        private readonly SeparatorWidget $separator,
        private readonly ViewRegistry $views,
        private readonly ViewRegions $regions,
        private readonly CommandRegistry $commands,
        private readonly Translator $translator,
        private readonly PreviewSplit $previewSplit,
        private readonly ProblemCards $problems,
        ?FrameHooks $frameHooks = null,
    ) {
        $frameHooks?->onBeforeFrame($this->followResize(...));
    }

    /** A bare move: the element under the pointer, or none. */
    public function route(MouseEvent $mouse, ?string $view): void
    {
        $this->last = $mouse;
        $this->lastView = $view;
        $this->tooltips->point($this->lookup($mouse, $view));
    }

    /**
     * Before each frame: a size change is noted, and a frame is asked for so
     * the NEXT hook — whose rects are the resized layout's — can ask again.
     */
    private function followResize(): void
    {
        if ($this->resized) {
            $this->resized = false;
            if (null !== $this->last && null !== $this->tooltips->shown()) {
                $this->route($this->last, $this->lastView);
            }
        }
        $terminal = $this->tui->getTerminal();
        $size = [$terminal->getColumns(), $terminal->getRows()];
        if ($size === $this->size) {
            return;
        }
        $this->resized = [0, 0] !== $this->size;
        $this->size = $size;
        if ($this->resized) {
            $this->tui->requestRender();
        }
    }

    private function lookup(MouseEvent $mouse, ?string $view): ?Tooltip
    {
        return match (true) {
            $this->contextMenu->isOpen() || $this->menuBar->isDropdownShown() => null,
            $this->toasts->covers($mouse->row, $mouse->col) => $this->toasts->tooltipAt($mouse->row, $mouse->col, $this->translator),
            $this->panes->editor->isFindModalOpen() => $this->panes->editor->findModal->tooltipAt($mouse->row, $mouse->col),
            default => $this->panes->editor->isAnyPickerOpen() || $this->windows->isModalOpen() ? null : $this->workspaceAt($mouse, $view),
        };
    }

    /**
     * The status bar rides along under every view; an open view owns the
     * rest outright, as it does the hover.
     */
    private function workspaceAt(MouseEvent $mouse, ?string $view): ?Tooltip
    {
        $status = $this->hit->tooltip($this->statusBar, $mouse->row, $mouse->col);
        $region = null !== $view && $this->views->isAnyOpen() ? $this->regions->find($view) : null;

        return match (true) {
            null !== $status => $status,
            null !== $region => $this->firstAnswer($mouse, $this->providersIn([$region->header(), ...$region->panes()])),
            default => $this->mainAt($mouse),
        };
    }

    /** The editor layout: its strips and panes, then the gutter's marks. */
    private function mainAt(MouseEvent $mouse): ?Tooltip
    {
        $tip = $this->firstAnswer($mouse, $this->mainProviders());
        $rect = null === $tip ? $this->hit->rect($this->panes->editor) : null;
        $editor = $this->panes->editor;

        return match (true) {
            null !== $tip => $tip,
            null === $rect || !$rect->contains($mouse->row, $mouse->col) => null,
            $editor->isCompletionOpen() => $editor->completion->tooltipAt($mouse->row - $rect->row, $mouse->col - $rect->col, $this->translator)?->moved($rect->row, $rect->col),
            default => $this->codeAt($mouse, $rect),
        };
    }

    /** Inside the editor, no completion open: the gutter's marks, then the code's problems and hints. */
    private function codeAt(MouseEvent $mouse, WidgetRect $rect): ?Tooltip
    {
        $editor = $this->panes->editor;
        $pointer = new GutterPointer($editor->viewport(), $editor->decorations, $editor->display->vcsGutter, $editor->getDocument()->getLineCount(), $editor->documentLineAt(...));
        $clickShowsChange = $this->commands->has(GutterClickRouter::SHOW_CHANGE);
        $relRow = $mouse->row - $rect->row;
        $relCol = $mouse->col - $rect->col;
        $tip = $pointer->tooltip($relRow, $relCol, $clickShowsChange, $this->translator);
        // Past the gutter, the code: the problems a squiggle there is about,
        // in every diagnostic-message mode — the card is where the message
        // is read when the line does not say it. Its fix line arrives later.
        // Not while cards are off: building one asks the servers for its fix.
        if (null === $tip && $this->tooltips->enabled()) {
            $tip = $this->problems->tooltipAt($relRow, $relCol, $editor->viewport(), fn (Tooltip $card) => $this->tooltips->refresh($card->moved($rect->row, $rect->col)));
        }
        // Then its inlay hints, while they show as cards.
        if (null === $tip && InlayHintMode::Tooltip === $editor->display->inlayHints) {
            $tip = $editor->mouse->inlayTooltipAt($relRow, $relCol, $editor->viewport());
        }

        return $tip?->moved($rect->row, $rect->col);
    }

    /**
     * The first of $widgets under the pointer that has something to say.
     *
     * @param iterable<AbstractWidget&TooltipProvider> $widgets
     */
    private function firstAnswer(MouseEvent $mouse, iterable $widgets): ?Tooltip
    {
        foreach ($widgets as $widget) {
            if (null !== ($tip = $this->hit->tooltip($widget, $mouse->row, $mouse->col))) {
                return $tip;
            }
        }

        return null;
    }

    /**
     * Every widget under $widgets that answers for itself — a view's panes
     * nest, and whichever of them implements {@see TooltipProvider} (a
     * plugin's own among them) is asked, with no list of names to keep.
     *
     * @param array<AbstractWidget> $widgets
     *
     * @return \Generator<AbstractWidget&TooltipProvider>
     */
    private function providersIn(array $widgets): \Generator
    {
        foreach ($widgets as $widget) {
            if ($widget instanceof TooltipProvider) {
                yield $widget;
            }
            if ($widget instanceof ContainerWidget) {
                yield from $this->providersIn($widget->all());
            }
        }
    }

    /**
     * The main layout's surfaces that answer for themselves. A widget that
     * hides by rendering nothing keeps its last rect, so its own liveness
     * gates it here (the find bar), as it does on the hover path.
     *
     * @return list<AbstractWidget&TooltipProvider>
     */
    private function mainProviders(): array
    {
        return [
            ...($this->panes->findBar->isOpen() ? [$this->panes->findBar] : []),
            $this->panes->tabBar,
            $this->panes->breadcrumbs,
            $this->panes->tree,
            ...($this->dock->stripVisible() ? [$this->dockTabs] : []),
            // A hidden panel keeps its last rect, so it answers only while shown.
            ...(($panel = $this->dock->visiblePanel()) instanceof TooltipProvider ? [$panel] : []),
            $this->separator,
            ...$this->previewSplit->separators(),
        ];
    }
}
