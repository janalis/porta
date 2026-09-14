<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Search\EditorFindTarget;
use Porta\Support\Clipboard;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorFileActionHandler;
use Porta\Widget\FindBarWidget;
use Symfony\Component\Tui\Tui;

/** The IDE-side implementation of the editor's file-level hooks. */
final readonly class EditorFileActions implements EditorFileActionHandler
{
    public function __construct(
        private BufferService $bufferService,
        private BufferNavigator $navigator,
        private Clipboard $clipboard,
        private FindBarWidget $findBar,
        // Concrete on purpose: it reads the document *and* hands the widget to
        // EditorFindTarget as the decoration sink for match highlighting.
        private CodeEditorWidget $editor,
        private Tui $tui,
    ) {}

    #[\Override]
    public function save(): void
    {
        $this->bufferService->saveCurrentFile();
    }

    #[\Override]
    public function saveAll(): void
    {
        $this->bufferService->saveAllBuffers();
    }

    /** Mirror editor copies to the system clipboard via OSC 52. */
    #[\Override]
    public function copy(string $text): void
    {
        $this->clipboard->copy($text);
    }

    #[\Override]
    public function openFind(): void
    {
        $this->openFindBar(FindMode::Find);
    }

    #[\Override]
    public function openFindReplace(): void
    {
        $this->openFindBar(FindMode::Replace);
    }

    private function openFindBar(FindMode $mode): void
    {
        $document = $this->editor->getDocument();
        $selected = $document->getSelectedText();
        // The selection when there is one, the identifier the caret stands in
        // otherwise — PhpStorm's rule, and the one that makes Ctrl+F on a
        // symbol a single keystroke instead of a keystroke and retyping it.
        // A multi-line selection prefills nothing: the field is one line, and
        // its first line alone would search for something nobody asked for.
        $initial = '' === $selected ? CaretWord::at($document) : $selected;
        $this->findBar->open(
            new EditorFindTarget($document, $this->editor->decorations()),
            $mode,
            \str_contains($initial, "\n") ? '' : $initial,
        );
        $this->tui->setFocus($this->findBar);
        $this->tui->requestRender();
    }

    #[\Override]
    public function openGotoFile(): void
    {
        $this->navigator->openGotoFile();
    }
}
