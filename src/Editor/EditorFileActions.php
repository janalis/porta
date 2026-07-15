<?php

declare(strict_types=1);

namespace Porta\Editor;

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
        private readonly CodeEditorWidget $editor,
        private readonly Tui $tui,
    ) {}

    public function save(): void
    {
        $this->bufferService->saveCurrentFile();
    }

    public function saveAll(): void
    {
        $this->bufferService->saveAllBuffers();
    }

    /** Mirror editor copies to the system clipboard via OSC 52. */
    public function copy(string $text): void
    {
        $this->clipboard->copy($text);
    }

    public function openFind(): void
    {
        $this->openFindBar(FindMode::Find);
    }

    public function openFindReplace(): void
    {
        $this->openFindBar(FindMode::Replace);
    }

    private function openFindBar(FindMode $mode): void
    {
        $initial = $this->editor->getDocument()->getSelectedText();
        $this->findBar->open(
            $this->editor->getDocument(),
            $mode,
            str_contains($initial, "\n") ? '' : $initial,
        );
        $this->tui->setFocus($this->findBar);
        $this->tui->requestRender();
    }

    public function openGotoFile(): void
    {
        $this->navigator->openGoToFile();
    }
}
