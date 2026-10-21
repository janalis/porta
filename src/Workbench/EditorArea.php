<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Editor\PreviewMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The editor area's focusable content, resolved at call time from the active
 * buffer: the pane registered for its kind, or the code editor. One lookup, so
 * a new pane needs no case here — and the focus ring, the menu-close restore and
 * session restore all reach it through this one method.
 *
 * A previewed buffer in Split shows TWO focusable widgets, the editor and its
 * preview; the area then answers with whichever of them has the keys, and the
 * editor when neither does.
 */
#[AsIdeService]
final readonly class EditorArea
{
    public function __construct(
        private BufferManager $buffers,
        private CodeEditorWidget $editor,
        private EditorPanes $panes,
        private Tui $tui,
    ) {}

    /**
     * The editor-pane's focusable content: the active tab's viewer or lone
     * preview, else the editor — or, in Split, whichever side has the keys:
     * the editor, the preview, or the preview's flank.
     */
    public function widget(): AbstractWidget
    {
        $preview = $this->preview($this->buffers->active());
        $focus = $this->tui->getFocus();
        $beside = null !== $preview && null !== $focus && ($focus === $preview || $focus === $preview->flank());

        return $beside ? $focus : $this->entry();
    }

    /**
     * Where the keys land when the area is entered anew — a file picked, an
     * arrangement chosen: the viewer or lone preview, else the editor, even
     * where a preview beside it had them before.
     */
    public function entry(): AbstractWidget
    {
        $active = $this->buffers->active();
        $viewer = $this->panes->paneFor((string) $active?->paneId);
        if (null !== $viewer) {
            return $viewer;
        }
        $preview = $this->preview($active);

        return null !== $preview && PreviewMode::Preview === $active?->previewArrangement() ? $preview : $this->editor;
    }

    /**
     * The pane on screen in the editor area — a viewer tab's, or a preview
     * beside or instead of the editor — or null when the editor has it alone.
     * What the pointer routers hit-test: in Split the editor and its preview
     * are both there to be clicked, whichever holds the keys.
     */
    public function visiblePane(): ?EditorPaneWidget
    {
        $active = $this->buffers->active();

        return $this->panes->paneFor((string) $active?->paneId) ?? $this->preview($active);
    }

    /**
     * The focus ring's stops in the editor area: the editor then its preview
     * in Split — a preview's flank first, as it sits — else the one
     * {@see widget()}.
     *
     * @return list<AbstractWidget>
     */
    public function members(): array
    {
        $active = $this->buffers->active();
        $preview = $this->preview($active);
        if (null === $preview || PreviewMode::Split !== $active?->previewArrangement()) {
            return [$this->widget()];
        }
        $flank = $preview->flank();

        return null === $flank ? [$this->editor, $preview] : [$flank, $this->editor, $preview];
    }

    /** Focus the editor area's current content widget and repaint. */
    public function focus(): void
    {
        $this->tui->setFocus($this->widget());
        $this->tui->requestRender();
    }

    private function preview(?Buffer $buffer): ?EditorPaneWidget
    {
        return null === $buffer ? null : $this->panes->shownPreview($buffer);
    }
}
