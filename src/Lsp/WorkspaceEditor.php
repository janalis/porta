<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Applies LSP WorkspaceEdit / TextEdit payloads to open buffers (and to files
 * on disk when not open). Shared by the rename/code-action feature controller
 * and by the server-initiated {@see workspace/applyEdit} handler.
 */
final readonly class WorkspaceEditor
{
    public function __construct(
        private BufferManager $buffers,
        private CodeEditorWidget $editor,
        private LspSession $lsp,
    ) {}

    /**
     * Apply a WorkspaceEdit (either the `changes` map or `documentChanges`),
     * returning the number of files touched. Open buffers are edited in place
     * and re-synced to the server; closed, writable files are edited on disk.
     *
     * @param array<string, mixed> $edit
     */
    public function applyWorkspaceEdit(array $edit): int
    {
        $byUri = [];
        /** @var array<array-key, list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}>> $changes */
        $changes = $edit['changes'] ?? [];
        foreach ($changes as $uri => $edits) {
            $byUri[$uri] = $edits;
        }
        /** @var list<array{textDocument?: array{uri?: string}, edits?: list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}>}> $documentChanges */
        $documentChanges = $edit['documentChanges'] ?? [];
        foreach ($documentChanges as $change) {
            if (isset($change['textDocument']['uri'], $change['edits'])) {
                $byUri[$change['textDocument']['uri']] = array_merge($byUri[$change['textDocument']['uri']] ?? [], $change['edits']);
            }
        }

        $touched = 0;
        foreach ($byUri as $uri => $edits) {
            $target = Uri::toPath((string) $uri);
            if (null === $target || [] === $edits) {
                continue;
            }

            $buffer = $this->buffers->get($target) ?? $this->buffers->get(realpath($target) ?: $target);
            if (null !== $buffer) {
                $this->applyTextEdits($buffer->document, $edits);
                if ($buffer === $this->buffers->active()) {
                    $this->editor->invalidate();
                }
                $this->lsp->notifyFullChange($buffer->path, $buffer->document);
            } elseif (is_file($target) && is_writable($target)) {
                $document = new Document((string) file_get_contents($target));
                $this->applyTextEdits($document, $edits);
                new Filesystem()->dumpFile($target, $document->getText());
            } else {
                continue;
            }
            ++$touched;
        }

        return $touched;
    }

    /** @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}> $edits */
    public function applyTextEdits(Document $document, array $edits): void
    {
        // Bottom-up so earlier positions stay valid
        usort($edits, static function (array $a, array $b): int {
            return [$b['range']['start']['line'] ?? 0, $b['range']['start']['character'] ?? 0] <=> [$a['range']['start']['line'] ?? 0, $a['range']['start']['character'] ?? 0];
        });

        // One compound so a rename / reformat / code-action is a single undo step.
        $document->transact(static function () use ($document, $edits): void {
            foreach ($edits as $edit) {
                $start = $edit['range']['start'];
                $end = $edit['range']['end'];
                $document->setCursor($start['line'] ?? 0, $document->utf16ColToByteCol($start['line'] ?? 0, $start['character'] ?? 0));
                $document->beginSelect();
                $document->setCursor($end['line'] ?? 0, $document->utf16ColToByteCol($end['line'] ?? 0, $end['character'] ?? 0), keepSelection: true);
                if ($document->hasSelection()) {
                    $document->deleteSelection();
                }
                $document->insert($edit['newText'] ?? '');
            }
        });
    }
}
