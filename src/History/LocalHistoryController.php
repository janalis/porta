<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Ui\Dock\BottomDock;
use Porta\Ui\Dock\ResultsPanelMode;
use Porta\Ui\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * The Local History feature: lists a file's save-time snapshots in the results
 * panel and restores a picked one into the active buffer as a single undoable
 * edit. Owns the snapshot-path list backing the currently shown panel rows.
 */
#[AsIdeService]
final class LocalHistoryController
{
    /** @var list<string> snapshot file paths for the currently listed history */
    private array $snapshots = [];

    public function __construct(
        private readonly LocalHistory $history,
        private readonly BottomDock $panels,
        // Two roles of the same widget: the active file names the history,
        // the invalidator repaints after a restore lands in the buffer.
        private readonly ActiveFileProvider $activeFile,
        private readonly EditorInvalidator $editorInvalidator,
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly CompletionController $completion,
    ) {}

    public function show(): void
    {
        $path = $this->activeFile->getFilePath();
        if (null === $path) {
            return;
        }

        $snapshots = $this->history->list($path);
        if ([] === $snapshots) {
            $this->panels->flash($this->translator->trans('No local history for this file yet'));

            return;
        }

        $this->snapshots = [];
        $results = [];
        foreach ($snapshots as $index => $snapshot) {
            $this->snapshots[] = $snapshot['file'];
            $when = date('Y-m-d H:i:s', intdiv($snapshot['stamp'], num2: 1000));
            $results[] = ['path' => basename($path), 'line' => $index, 'text' => $when.(0 === $index ? '  (latest)' : '')];
        }

        $this->panels->showList(ResultsPanelMode::History, $this->translator->trans('Local History'), $results);
    }

    public function restore(int $index): void
    {
        $file = $this->snapshots[$index] ?? null;
        $active = $this->buffers->active();
        if (null === $file || null === $active) {
            return;
        }
        $content = $this->history->read($file);
        if (null === $content) {
            $this->notifier->error($this->translator->trans('Could not read that snapshot'));
            $this->tui->requestRender();

            return;
        }

        // Replace the whole document as one undoable edit.
        $doc = $active->document;
        $doc->selectAll();
        $doc->insert($content);
        $this->editorInvalidator->invalidate();
        $this->completion->scheduleDidChange($doc);
        $this->notifier->flash($this->translator->trans('Restored from local history'));
        $this->tui->requestRender();
    }
}
