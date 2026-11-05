<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Editor\EditorInvalidator;
use Porta\Editor\SelectionMode;
use Porta\Event\BufferRepointRequested;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\Event\FileRewritten;
use Porta\Event\WorkingTreeChanged;
use Porta\I18n\Translator;
use Porta\Support\Files;
use Porta\Workbench\Notifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Applies LSP WorkspaceEdit / TextEdit payloads to open buffers (and to files
 * on disk when not open). Shared by the rename/code-action feature controller
 * and by the server-initiated {@see workspace/applyEdit} handler.
 *
 * Two rules earn their keep here, and both are about not lying to the user.
 *
 * **Resource operations are all-or-nothing.** A server-side "extract to new
 * class" sends a `CreateFile` beside the edits that point at it. Dropping the
 * operation and applying the edits leaves references rewritten to a file that
 * was never created — a half-applied refactoring, reported as a success. So the
 * operations are validated FIRST against a simulated view of the tree, and one
 * that cannot be honoured refuses the whole edit. That is the `failureHandling:
 * "abort"` the client advertises.
 *
 * **Text edits stay tolerant, but are counted honestly.** Refusing a 20-file
 * rename because one file is read-only would be worse than doing the other 19;
 * those files are skipped and NAMED in the outcome instead of quietly vanishing
 * from the count.
 *
 * **Overlapping ranges skip their file rather than aborting the edit**, and
 * that is a deliberate deviation from the letter of the spec, which calls such
 * a WorkspaceEdit invalid outright. The reason is the rule above: a resource
 * operation that half-applies is unrecoverable, which is why those abort, while
 * a text file left alone is merely not yet renamed and is named in the outcome.
 * Porta used to apply them — see {@see TextEditOverlap} for what that did.
 */
#[AsIdeService]
final readonly class WorkspaceEditor
{
    public function __construct(
        private BufferManager $buffers,
        private EditorInvalidator $editor,
        private LspSessionPool $lsp,
        private EventDispatcherInterface $events,
        private RefactoringUndo $undo,
        private Filesystem $fs = new Filesystem(),
        /** Nullable so a bare test construction stays one `new`, the FindBarWidget idiom. */
        private ?Notifier $notifier = null,
        private Translator $translator = new Translator(),
    ) {}

    /**
     * Apply a WorkspaceEdit. `documentChanges` wins outright when present — the
     * spec's rule, and merging both would double-apply anything a server sends
     * in each for back-compat.
     *
     * @param array<string, mixed> $edit
     */
    public function applyWorkspaceEdit(array $edit): WorkspaceEditOutcome
    {
        $outcome = $this->applyEdit($edit);
        if (!$outcome->isRefused()) {
            // The tree may have moved — a file created, renamed, deleted or
            // rewritten on disk — and whatever computes version-control marks
            // has no other way to hear about it. Once for the whole edit, not
            // once per operation: a refactoring may rename twenty files, and
            // twenty `git status` runs answer the same question.
            //
            // Not on a refusal, which is the one case where silence is right:
            // refuseUnhonourable() returns before any operation runs, so there
            // is no half-applied state to ask git about. Every other outcome
            // may have changed disk, including a partial one.
            $this->events->dispatch(new WorkingTreeChanged());
        }

        return $outcome;
    }

    /** @param array<string, mixed> $edit */
    private function applyEdit(array $edit): WorkspaceEditOutcome
    {
        /** @var list<array<string, mixed>> $documentChanges */
        $documentChanges = \is_array($edit['documentChanges'] ?? null) ? $edit['documentChanges'] : [];
        if ([] !== $documentChanges) {
            return $this->applyDocumentChanges($documentChanges);
        }

        /** @var array<array-key, list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}>> $changes */
        $changes = \is_array($edit['changes'] ?? null) ? $edit['changes'] : [];
        $touched = 0;
        $skipped = [];
        $this->undo->begin();
        foreach ($changes as $uri => $edits) {
            $this->editOne((string) $uri, $edits, $touched, $skipped);
        }

        return new WorkspaceEditOutcome($touched, $skipped);
    }

    /**
     * The ordered form: resource operations and text edits interleave, and the
     * order is the contract (a file is created before it is edited).
     *
     * @param list<array<string, mixed>> $changes
     */
    private function applyDocumentChanges(array $changes): WorkspaceEditOutcome
    {
        $refusal = $this->refuseUnhonourable($changes);
        if (null !== $refusal) {
            return new WorkspaceEditOutcome(0, [], $refusal);
        }

        $touched = 0;
        $skipped = [];
        $this->undo->begin();
        foreach ($changes as $change) {
            $kind = \is_string($change['kind'] ?? null) ? $change['kind'] : '';
            if ('' !== $kind) {
                // A create, rename or delete puts this edit beyond the reach of
                // Ctrl+Alt+Z: only text is remembered, so undoing would restore
                // the words and leave the new file standing. Refused outright
                // rather than half-offered.
                $this->undo->decline();
                $this->applyResourceOperation($kind, $change);

                continue;
            }
            /** @var list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits */
            $edits = \is_array($change['edits'] ?? null) ? $change['edits'] : [];
            $uri = $this->stringOf($change['textDocument']['uri'] ?? null);
            if (null !== $uri) {
                $this->editOne($uri, $edits, $touched, $skipped);
            }
        }

        return new WorkspaceEditOutcome($touched, $skipped);
    }

    /**
     * Walk the operations against a simulated view of which paths exist, and
     * return why the edit cannot be honoured — or null when it can. Simulated,
     * because the answer for a later operation depends on the earlier ones: a
     * file created by step 1 exists by step 3.
     *
     * @param list<array<string, mixed>> $changes
     */
    private function refuseUnhonourable(array $changes): ?string
    {
        /** @var array<string, bool> $exists path => is there, seeded lazily from disk */
        $exists = [];
        foreach ($changes as $change) {
            $kind = \is_string($change['kind'] ?? null) ? $change['kind'] : '';
            if ('' === $kind) {
                continue;
            }
            /** @var array<string, mixed> $options */
            $options = \is_array($change['options'] ?? null) ? $change['options'] : [];
            $refusal = match ($kind) {
                'create' => $this->refuseCreate($change, $options, $exists),
                'rename' => $this->refuseRename($change, $options, $exists),
                'delete' => $this->refuseDelete($change, $options, $exists),
                default => \sprintf('unsupported operation "%s"', $kind),
            };
            if (null !== $refusal) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $options
     * @param array<string, bool>  $exists
     */
    private function refuseCreate(array $change, array $options, array &$exists): ?string
    {
        $path = $this->pathOf($change['uri'] ?? null);
        if (null === $path) {
            return 'a file to create has no usable path';
        }
        $refusal = match (true) {
            $this->outside($path) => \sprintf('%s is outside the project', $path),
            $this->present($path, $exists) && true !== ($options['overwrite'] ?? null) && true !== ($options['ignoreIfExists'] ?? null) => \sprintf('%s already exists', \basename($path)),
            default => null,
        };
        if (null === $refusal) {
            $exists[$path] = true;
        }

        return $refusal;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $options
     * @param array<string, bool>  $exists
     */
    private function refuseRename(array $change, array $options, array &$exists): ?string
    {
        $from = $this->pathOf($change['oldUri'] ?? null);
        $to = $this->pathOf($change['newUri'] ?? null);
        if (null === $from || null === $to) {
            return 'a file to rename has no usable path';
        }
        $outside = \array_find([$from, $to], $this->outside(...));
        $refusal = match (true) {
            null !== $outside => \sprintf('%s is outside the project', $outside),
            !$this->present($from, $exists) => \sprintf('%s is not there to rename', \basename($from)),
            $this->present($to, $exists) && true !== ($options['overwrite'] ?? null) && true !== ($options['ignoreIfExists'] ?? null) => \sprintf('%s already exists', \basename($to)),
            default => null,
        };
        if (null === $refusal) {
            $exists[$from] = false;
            $exists[$to] = true;
        }

        return $refusal;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $options
     * @param array<string, bool>  $exists
     */
    private function refuseDelete(array $change, array $options, array &$exists): ?string
    {
        $path = $this->pathOf($change['uri'] ?? null);
        if (null === $path) {
            return 'a file to delete has no usable path';
        }
        $refusal = match (true) {
            $this->outside($path) => \sprintf('%s is outside the project', $path),
            !$this->present($path, $exists) && true !== ($options['ignoreIfNotExists'] ?? null) => \sprintf('%s is not there to delete', \basename($path)),
            default => null,
        };
        if (null === $refusal) {
            $exists[$path] = false;
        }

        return $refusal;
    }

    /**
     * Whether a server's file operation would land outside the project. The
     * server is a process the project may have chosen, and a delete is a
     * recursive remove: `file:///Users/me` is a WorkspaceEdit away. Judged on
     * the deepest part that exists, resolved — a symlink out counts as out,
     * a dangling one too.
     */
    private function outside(string $path): bool
    {
        // A `..` is judged against the disk as it is BEFORE the edit: through a
        // folder an earlier operation creates, `new/..` walks up to the root
        // here and is the root itself once applied. Only a plain path is judged.
        if (Path::canonicalize($path) !== \rtrim($path, characters: '/')) {
            return true;
        }
        $root = \realpath($this->lsp->rootDir());
        $existing = $path;
        while (!\file_exists($existing) && !\is_link($existing) && \dirname($existing) !== $existing) {
            $existing = \dirname($existing);
        }
        $real = \realpath($existing);

        // The root itself is no server's to delete or rename either.
        return false === $root || false === $real || ($real === $root ? $existing === $path : !\str_starts_with($real, $root.'/'));
    }

    /** @param array<string, bool> $exists */
    private function present(string $path, array $exists): bool
    {
        return $exists[$path] ?? \file_exists($path);
    }

    private function pathOf(mixed $uri): ?string
    {
        $text = $this->stringOf($uri);

        return null === $text ? null : Uri::toPath($text);
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * Perform one operation. Validated already, so what remains is the doing —
     * and the open buffer, which has to follow the file it is showing.
     *
     * @param array<string, mixed> $change
     */
    private function applyResourceOperation(string $kind, array $change): void
    {
        // Every path here was resolved and judged by refuseUnhonourable, which
        // returned before any of this ran if one of them could not be — so the
        // asserts are the contract, not a branch to test.
        if ('create' === $kind) {
            $path = $this->pathOf($change['uri'] ?? null);
            \assert(null !== $path, description: 'a create with no usable path is refused before applying');
            if (!\file_exists($path)) {
                $this->fs->dumpFile($path, '');
            }

            return;
        }
        if ('delete' === $kind) {
            $path = $this->pathOf($change['uri'] ?? null);
            \assert(null !== $path, description: 'a delete with no usable path is refused before applying');
            // A server deleting a file the user has unsaved edits in takes those
            // edits with it, and there is no autosave to have caught them. It is
            // still applied: refusing here would leave the workspace edit HALF
            // APPLIED, which this codebase treats as the worse outcome. What it
            // may not do is happen in silence.
            $buffer = $this->bufferAt($path);
            if (true === $buffer?->isDirty()) {
                $this->notifier?->error($this->translator->trans('Deleted %file% — unsaved changes in it are gone', ['%file%' => \basename($path)]));
            }
            // What the user last had — the buffer's text, unsaved edits and
            // all — is what FileDeleted hands on for recovery.
            $previous = null !== $buffer && !$buffer->isViewer() ? $buffer->document->getText() : Files::textOf($path);
            $this->closeBufferAt($path);
            // Canonical, and resolved BEFORE the removal — afterwards there is
            // nothing left for realpath() to resolve. FileDeleted's contract.
            $key = Files::canonical($path);
            $this->fs->remove($path);
            $this->events->dispatch(new FileDeleted($key, $previous));

            return;
        }
        $from = $this->pathOf($change['oldUri'] ?? null);
        $to = $this->pathOf($change['newUri'] ?? null);
        \assert(null !== $from && null !== $to, description: 'a rename with no usable path is refused before applying');
        // The folder it goes into is made here, once the rename is judged —
        // made before, a refused move would leave it behind, wherever it was.
        $this->fs->mkdir(\dirname($to));
        $this->fs->rename($from, $to, overwrite: true);
        // Canonical both sides, per FileRenamed's contract; $from is already gone.
        $this->events->dispatch(new FileRenamed(Files::canonicalGone($from), Files::canonical($to)));
        // Dispatched rather than done here: re-opening needs BufferService,
        // which reaches this class back through FormatOnSave. A command event
        // is the sanctioned way out of a real container cycle, and this one is.
        $this->events->dispatch(new BufferRepointRequested($from, $to));
    }

    private function bufferAt(string $path): ?Buffer
    {
        return $this->buffers->find($path);
    }

    private function closeBufferAt(string $path): void
    {
        $buffer = $this->bufferAt($path);
        if (null !== $buffer) {
            $this->buffers->close($buffer->path);
        }
    }

    /**
     * Edit one file: the open buffer in place when there is one, else the file
     * on disk. A file that cannot be written is NAMED, not dropped.
     *
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits
     * @param list<string>                                                                                                                  $skipped
     */
    private function editOne(string $uri, array $edits, int &$touched, array &$skipped): void
    {
        $target = Uri::toPath($uri);
        if (null === $target || [] === $edits) {
            return;
        }

        $buffer = $this->bufferAt($target);
        if (null !== $buffer) {
            $this->editBuffer($buffer, $target, $edits, $touched, $skipped);

            return;
        }
        $this->editFile($target, $edits, $touched, $skipped);
    }

    /**
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits
     * @param list<string>                                                                                                                  $skipped
     */
    private function editBuffer(Buffer $buffer, string $target, array $edits, int &$touched, array &$skipped): void
    {
        if (!self::applyTextEdits($buffer->document, $edits)) {
            $skipped[] = $target;

            return;
        }
        $this->undo->recordBuffer($buffer->path, $buffer->document->version);
        if ($buffer === $this->buffers->active()) {
            $this->editor->invalidate();
        }
        $this->lsp->notifyFullChange($buffer->path, $buffer->document);
        ++$touched;
    }

    /**
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits
     * @param list<string>                                                                                                                  $skipped
     */
    private function editFile(string $target, array $edits, int &$touched, array &$skipped): void
    {
        // An open buffer is the user's, wherever it lives; a file written
        // unseen on disk is the server's doing, and stays inside the project
        // like its creates and deletes.
        $contents = $this->outside($target) ? null : Files::readWritable($target);
        if (null === $contents) {
            $skipped[] = $target;

            return;
        }
        $document = new Document($contents);
        if (!self::applyTextEdits($document, $edits)) {
            $skipped[] = $target;

            return;
        }
        try {
            $this->fs->dumpFile($target, $document->getText());
            ++$touched;
            // Announced only AFTER the write succeeded, and carrying what the
            // write replaced. A file with no buffer has no undo stack and no
            // BufferSaved, so this is the only moment its previous content
            // still exists anywhere — see {@see FileRewritten}.
            $this->events->dispatch(new FileRewritten($target, $contents));
            $this->undo->recordFile($target, $contents, $document->getText());
        } catch (IOException) {
            // The writability check passed and the write still failed (a full
            // disk, a read-only mount, a race). Uncaught this escapes the LSP
            // response callback into the event loop, with earlier files already
            // written — so it is reported like any other unwritable file.
            $skipped[] = $target;
        }
    }

    /**
     * Apply one file's text edits, or refuse them all.
     *
     * Returns whether they were applied. Overlapping ranges are the one
     * refusal: the sort below is what makes the rest work and is also what
     * hides them, since two edits over the same characters both land and the
     * second clamps into whatever the first produced. See
     * {@see TextEditOverlap}.
     *
     * @param list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText?: string}> $edits
     */
    public static function applyTextEdits(Document $document, array $edits): bool
    {
        if (null !== TextEditOverlap::describe($edits)) {
            return false;
        }

        // Bottom-up so earlier positions stay valid
        \usort(
            $edits,
            static fn (array $a, array $b): int => (
                [$b['range']['start']['line'] ?? 0, $b['range']['start']['character'] ?? 0] <=> [$a['range']['start']['line'] ?? 0, $a['range']['start']['character'] ?? 0]
            ),
        );

        // One compound so a rename / reformat / code-action is a single undo step.
        $document->transact(static function () use ($document, $edits): void {
            foreach ($edits as $edit) {
                $start = $edit['range']['start'];
                $end = $edit['range']['end'];
                $document->setCursor($start['line'] ?? 0, $document->utf16ColToByteCol($start['line'] ?? 0, $start['character'] ?? 0));
                $document->beginSelect();
                $document->setCursor($end['line'] ?? 0, $document->utf16ColToByteCol($end['line'] ?? 0, $end['character'] ?? 0), selection: SelectionMode::Keep);
                if ($document->hasSelection()) {
                    $document->deleteSelection();
                }
                $document->insert($edit['newText'] ?? '');
            }
        });

        return true;
    }
}
