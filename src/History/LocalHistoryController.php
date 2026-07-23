<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Ui\BottomDock;
use Porta\Ui\Notifier;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * The Local History feature: lists a file's save-time snapshots in the results
 * panel and restores a picked one into the active buffer as a single undoable
 * edit. Owns the snapshot-path list backing the currently shown panel rows.
 */
final class LocalHistoryController
{
    /** @var list<string> snapshot file paths for the currently listed history */
    private array $snapshots = [];

    public function __construct(
        private readonly LocalHistory $history,
        private readonly BottomDock $panels,
        private readonly CodeEditorWidget $editor,
        private readonly BufferManager $buffers,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly Translator $translator,
        private readonly CompletionController $completion,
    ) {}

    public function show(): void
    {
        $path = $this->editor->getFilePath();
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
        $this->editor->invalidate();
        $this->completion->scheduleDidChange($doc);
        $this->notifier->flash($this->translator->trans('Restored from local history'));
        $this->tui->requestRender();
    }
}
