<?php

declare(strict_types=1);

namespace Porta\Layout;

use Porta\Container\AsEager;
use Porta\Ui\BidiTui;
use Porta\Ui\EditorArea;
use Porta\Widget\FileTreeWidget;
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

    private bool $collapsed = false;

    /**
     * Registers itself on the render loop's width hook — this service is
     * resolved eagerly for that side effect (like the view controllers,
     * which attach themselves to WorkspaceViews).
     */
    public function __construct(
        private readonly PaneLayout $paneLayout,
        private readonly FileTreeWidget $tree,
        private readonly EditorArea $editorArea,
        BidiTui $bidi,
    ) {
        $bidi->onColumnsChanged($this->apply(...));
    }

    /** Called by the render loop whenever the terminal width changes. */
    public function apply(int $columns): void
    {
        $collapse = $columns < self::COLLAPSE_BELOW;
        if ($collapse === $this->collapsed) {
            return;
        }
        $this->collapsed = $collapse;

        $paneWidget = $this->editorArea->widget();
        $restoreFocus = $this->tree->isFocused() || $paneWidget instanceof FocusableInterface && $paneWidget->isFocused();

        $collapse ? $this->paneLayout->collapseSidebar() : $this->paneLayout->expandSidebar();

        if ($restoreFocus) {
            $this->editorArea->focus();
        }
    }
}
