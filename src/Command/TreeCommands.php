<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Config\PaletteVisibility;
use Porta\Tree\TreeOperations;
use Porta\Tree\TreePathClipboard;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Tui\Tui;

/**
 * The file tree's clipboard verbs, on the chords every file manager uses.
 *
 * They are the keyboard's only route to a cross-directory move. Before them the
 * tree's whole file-operation surface — new, rename, delete, and the drag —
 * was reachable by mouse alone, so moving a file needed a pointer.
 *
 * `ctrl+x` and `ctrl+v` are also the editor's cut and paste, and the collision
 * is deliberate: they are separated by FOCUS rather than by chord, so the same
 * key means the same verb on whichever thing the user is looking at. The
 * editor's pair lives in {@see \Porta\Widget\EditorKeymap} and never reaches
 * this registry; these are gated on the tree holding focus, and the dispatcher
 * checks commands before falling through to the focused widget, so a false
 * gate here leaves the editor's own binding untouched.
 */
#[AsTaggedItem(priority: 150)]
final readonly class TreeCommands implements CommandProvider
{
    public function __construct(
        private TreeOperations $operations,
        private FileTreeWidget $tree,
        private Tui $tui,
        private TreePathClipboard $clipboard,
    ) {}

    /**
     * Kept OUT of the command palette, unlike almost everything else here.
     *
     * All of them are gated on the tree holding focus, and opening the palette
     * takes it — so a palette entry for them could never run. An item that is
     * always listed and never works is worse than no item.
     */
    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('tree_cut', $this->operations->cutSelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->treeHasFocus(...))
            ->add('tree_copy', $this->operations->copySelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->treeHasFocus(...))
            // Also gated on there being something to paste, so ctrl+v with an
            // empty clipboard falls through rather than being swallowed.
            ->add('tree_paste', $this->operations->pasteIntoSelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->canPaste(...))
            ->add('tree_delete', $this->operations->deleteSelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->treeHasFocus(...))
            ->add('tree_new', $this->operations->newInSelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->treeHasFocus(...))
            ->add('tree_rename', $this->operations->renameSelection(...), keyBound: KeyDispatch::Bound, inPalette: PaletteVisibility::Hidden, when: $this->treeHasFocus(...));
    }

    private function treeHasFocus(): bool
    {
        return $this->tui->getFocus() === $this->tree;
    }

    private function canPaste(): bool
    {
        return $this->treeHasFocus() && !$this->clipboard->isEmpty();
    }
}
