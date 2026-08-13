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
final class EditorFileActions implements EditorFileActionHandler
{
    public function __construct(
        private readonly BufferService $bufferService,
        private readonly BufferNavigator $navigator,
        private readonly Clipboard $clipboard,
        private readonly FindBarWidget $findBar,
        // Concrete on purpose: it reads the document *and* hands the widget to
        // EditorFindTarget as the decoration sink for match highlighting.
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
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
        $initial = $this->editor->getDocument()->getSelectedText();
        $this->findBar->open(
            new EditorFindTarget($this->editor->getDocument(), $this->editor),
            $mode,
            str_contains($initial, "\n") ? '' : $initial,
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
