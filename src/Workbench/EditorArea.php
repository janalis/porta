<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor area's focusable content, resolved at call time from the active
 * buffer: the pane registered for its kind, or the code editor. One lookup, so
 * a new pane needs no case here — and the focus ring, the menu-close restore and
 * session restore all reach it through this one method.
 */
#[AsIdeService]
final class EditorArea
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly EditorPanes $panes,
        private readonly Tui $tui,
    ) {}

    /** The editor-pane's focusable content: the active tab's viewer, else the editor. */
    public function widget(): AbstractWidget
    {
        return $this->panes->paneFor((string) $this->buffers->active()?->paneId) ?? $this->editor;
    }

    /** Focus the editor area's current content widget and repaint. */
    public function focus(): void
    {
        $this->tui->setFocus($this->widget());
        $this->tui->requestRender();
    }
}
