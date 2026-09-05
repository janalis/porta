<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Ui\BidiTui;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\TabBarWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Auto-collapses the file-tree sidebar when the terminal is too narrow for it
 * to be useful, and restores it when the terminal widens again. Driven by the
 * render loop's width-change hook ({@see \Porta\Ui\BidiTui::onColumnsChanged()}),
 * so a resize re-arranges in the same frame it paints. The collapse is
 * ephemeral: it never touches the persisted layout percentages, so the user's
 * saved sidebar width survives the narrow spell. Re-arranging clears the main
 * container, transiently detaching the focused pane — which would drop focus
 * onto the menu bar — so focus is restored to the editor area afterwards (a
 * collapsing focused tree hands over the same way the view controllers do on
 * close).
 */
#[AsEager(priority: 600)]
final class ResponsiveLayout
{
    /** Below this many columns the sidebar collapses (tree minimum + a usable editor). */
    public const int COLLAPSE_BELOW = 70;

    /**
     * Below this many columns a SIDE tab strip goes back to the top.
     *
     * The strip is a fixed {@see TabBarWidget::VERTICAL_WIDTH} columns, so at
     * twice that it is already taking half the terminal and the file is reading
     * in the other half. Below it there is no reading left to do — which is how
     * a one-column editor pane was reachable at all.
     */
    public const int SIDE_TABS_BELOW = TabBarWidget::VERTICAL_WIDTH * 2;

    private bool $collapsed = false;

    /**
     * Registers itself on the render loop's width hook — this service is
     * resolved eagerly for that side effect (like the view controllers,
     * which register themselves with the view registry).
     */
    public function __construct(
        private readonly PaneLayout $paneLayout,
        private readonly FileTreeWidget $tree,
        private readonly EditorArea $editorArea,
        private readonly WorkspaceLayout $workspace,
        BidiTui $bidi,
    ) {
        $bidi->onColumnsChanged($this->apply(...));
    }

    /** Called by the render loop whenever the terminal width changes. */
    public function apply(int $columns): void
    {
        $this->applyTabStrip($columns);
        $collapse = $columns < self::COLLAPSE_BELOW;
        if ($collapse === $this->collapsed) {
            return;
        }
        $this->collapsed = $collapse;

        $restoreFocus = $this->editorAreaHasFocus();

        $collapse ? $this->paneLayout->collapseSidebar() : $this->paneLayout->expandSidebar();

        if ($restoreFocus) {
            $this->editorArea->focus();
        }
    }

    /**
     * Move a side tab strip to the top while the terminal is too narrow to seat
     * it, and put it back when the room returns.
     *
     * {@see WorkspaceLayout} owns which position that is: the settings applier
     * hands it the user's CHOICE at boot and on every change, so it can restore
     * one that changed while the strip was displaced. Reading a boot config
     * snapshot here would restore a preference the user had since abandoned.
     */
    private function applyTabStrip(int $columns): void
    {
        $displaced = $this->workspace->tabsAreDisplaced();
        $this->workspace->setNarrowTabs($columns < self::SIDE_TABS_BELOW);
        if ($displaced === $this->workspace->tabsAreDisplaced()) {
            return;
        }

        // The surgery clears and refills the editor pane, which detaches the
        // focused widget — the same hazard the sidebar collapse handles.
        if ($this->editorAreaHasFocus()) {
            $this->editorArea->focus();
        }
    }

    /**
     * Whether focus is somewhere a re-arrangement is about to detach.
     *
     * Shared by both responsive moves rather than spelled twice: they detach
     * the same widgets for the same reason, and a second copy of this condition
     * would be a second thing to get wrong — the `instanceof` guard is load
     * bearing (a non-focusable pane has no `isFocused()` to ask).
     */
    private function editorAreaHasFocus(): bool
    {
        $paneWidget = $this->editorArea->widget();

        return $this->tree->isFocused() || $paneWidget instanceof FocusableInterface && $paneWidget->isFocused();
    }
}
