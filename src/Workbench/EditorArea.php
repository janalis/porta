<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\GraphViewerWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor area's focusable content, resolved at call time from the active
 * buffer — an image/help tab focuses its viewer, everything else the editor.
 */
#[AsIdeService]
final class EditorArea
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly ImageViewerWidget $imageViewer,
        private readonly HelpViewerWidget $helpViewer,
        private readonly GraphViewerWidget $graphViewer,
        private readonly Tui $tui,
    ) {}

    /** The editor-pane's focusable content: the active tab's viewer, else the editor. */
    public function widget(): AbstractWidget
    {
        $active = $this->buffers->active();

        return match (true) {
            'image' === $active?->paneId => $this->imageViewer,
            'help' === $active?->paneId => $this->helpViewer,
            'graph' === $active?->paneId => $this->graphViewer,
            default => $this->editor,
        };
    }

    /** Focus the editor area's current content widget and repaint. */
    public function focus(): void
    {
        $this->tui->setFocus($this->widget());
        $this->tui->requestRender();
    }
}
