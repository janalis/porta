<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Config\CommandRegistry;
use Porta\Container\AsIdeService;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Tui;

/**
 * Left-clicks on the status bar, the sibling of the right-click path in
 * {@see ContextMenuRouter}.
 *
 * The status bar had exactly one hit-test — `menuAt`, for right-click menus —
 * so the numbers people look at most (the problem counts, the caret position,
 * the LSP status) did nothing at all when clicked, while in PhpStorm every one
 * of them is a way in.
 *
 * Its own class rather than three more constructor arguments on
 * {@see MouseRouter}: the editor's action router is built INSIDE
 * {@see CodeEditorWidget} and is not a service, so reaching `goto_line` means
 * holding the widget — a dependency the mouse router has managed without.
 *
 * The file part is PhpStorm's way back to the tree: a double-click there
 * reveals the file in the sidebar, as a double-click on its editor tab does.
 */
#[AsIdeService]
final readonly class StatusBarClickRouter
{
    public function __construct(
        private HitTester $hit,
        private StatusBarWidget $statusBar,
        private CommandRegistry $commands,
        private CodeEditorWidget $editor,
        private FileTreeWidget $tree,
        private Tui $tui,
        private EditorArea $editorArea,
    ) {}

    /**
     * True when the click landed on an actionable cell and was consumed. A
     * cell with no action may still offer a menu — the branch's `▾` — and
     * $openMenu, the right-click router's, opens it: the menus are that
     * router's, and a select opens on a left click.
     *
     * A press on the file part is counted on $clicks, the mouse router's: a
     * double-click there reveals the file in the tree, selected, while focus
     * stays in the editor. Its first click is consumed and does nothing.
     *
     * @param \Closure(MouseEvent): bool $openMenu
     */
    public function route(MouseEvent $mouse, \Closure $openMenu, ClickCounter $clicks): bool
    {
        $rect = $this->hit->rect($this->statusBar);
        if (null === $rect || !$rect->contains($mouse->row, $mouse->col)) {
            return false;
        }

        $col = $rect->toRelative($mouse->row, $mouse->col)['col'];
        $file = $this->statusBar->fileAt($col);
        if (null !== $file) {
            if (2 === $clicks->countAt($mouse->row, $mouse->col)) {
                $this->tree->revealPath($file);
                $this->tui->requestRender();
            }

            return true;
        }

        $action = $this->statusBar->clickAt($col);
        if (null === $action) {
            return $openMenu($mouse);
        }

        // The pane speaking on the right answers for its own cells (a
        // preview's zoom), then registry commands (the tool windows); anything
        // else is an editor verb, which is where `goto_line` lives.
        $pane = $this->editorArea->widget();
        if ($pane instanceof EditorPaneWidget && $pane->runStatusAction($action)) {
            $this->tui->requestRender();

            return true;
        }
        if ($this->commands->has($action)) {
            $this->commands->run($action);

            return true;
        }

        $this->editor->runAction($action);

        return true;
    }
}
