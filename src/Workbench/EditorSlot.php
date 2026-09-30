<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Container\AsIdeService;
use Porta\Container\LayoutSlot;
use Porta\Editor\EditorPanes;
use Porta\Layout\PreviewSplit;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The editor body's content slot (breadcrumbs → find bar → content; the tab
 * strip sits around the body, wherever the tabBarPosition setting put it) and
 * the three things that can fill it: the code editor, a pane, or the editor
 * beside a live preview in {@see PreviewSplit}.
 *
 * {@see \Porta\Editor\BufferService} decides WHICH, per buffer; this does the
 * widget surgery, which has two rules worth a class of its own:
 *
 * - Everything that could be in the slot is taken out first — the editor,
 *   every pane, the split — because a widget has one parent, and adding it
 *   somewhere new does not take it from the old one.
 * - Detaching the focused widget makes the focus manager fall back to an
 *   arbitrary focusable, so the focus is handed back: to the same widget when
 *   it is still on screen, else to the first one that is.
 */
#[AsIdeService]
final readonly class EditorSlot
{
    public function __construct(
        private CodeEditorWidget $editor,
        private EditorPanes $panes,
        #[LayoutSlot('editor_body')]
        private ContainerWidget $editorBody,
        private PreviewSplit $split,
        private Tui $tui,
    ) {}

    /** The code editor alone. */
    public function showEditor(): void
    {
        $this->seat($this->editor, [$this->editor]);
    }

    /** A pane alone: a viewer tab's, or a preview shown without its text. */
    public function showPane(EditorPaneWidget $pane): void
    {
        $this->seat($pane, [$pane]);
    }

    /** The editor with $preview beside it, the divider between them. */
    public function showBeside(EditorPaneWidget $preview): void
    {
        $this->seat($this->split->area, [$this->editor, $preview]);
    }

    /**
     * @param AbstractWidget       $content what goes in the slot
     * @param list<AbstractWidget> $shown   the focusable widgets that puts on screen, in focus order
     */
    private function seat(AbstractWidget $content, array $shown): void
    {
        $beside = $content === $this->split->area ? $shown[1] : null;
        if (\in_array($content, $this->editorBody->all(), strict: true) && $beside === $this->split->preview()) {
            return;
        }
        $focused = $this->tui->getFocus();
        $hadFocus = $focused === $this->editor || \in_array($focused, $this->panes->all(), strict: true);
        $this->editorBody->remove($this->split->area);
        $this->split->release();
        $this->editorBody->remove($this->editor);
        foreach ($this->panes->all() as $pane) {
            $this->editorBody->remove($pane);
            if (!\in_array($pane, $shown, strict: true)) {
                // Losing the slot is not the same as closing: a pane frees what
                // only the VISIBLE pane needs (the image pane's decoded pixels)
                // and keeps the rest — which is what the per-viewer clear() calls
                // scattered through BufferService used to say one viewer at a time.
                $pane->hide();
            }
        }
        if (null !== $beside) {
            $this->split->mount($this->editor, $beside);
        }
        $this->editorBody->add($content);
        if ($hadFocus) {
            $this->tui->setFocus(\in_array($focused, $shown, strict: true) ? $focused : $shown[0]);
        }
    }
}
