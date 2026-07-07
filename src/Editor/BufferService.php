<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\DocumentWriter;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\History\LocalHistory;
use Porta\I18n\Translator;
use Porta\Lsp\CompletionController;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Tui;

/**
 * The open-buffer lifecycle, extracted from the coordinator: opening files
 * into buffers (with .editorconfig and LSP didOpen), switching the editor
 * between buffers, closing tabs (with the unsaved-changes dialog), saving
 * (with .editorconfig save-time transforms and local-history snapshots),
 * reconciling buffers with external on-disk changes, and delivering LSP
 * diagnostics to their buffer.
 *
 * Git concerns stay with the coordinator and are reached through the two
 * injected closures.
 */
final class BufferService
{
    /**
     * @param \Closure(Buffer): void $refreshGitMarks
     * @param \Closure(): void       $refreshBranch
     */
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
        private readonly LspSessionPool $lsp,
        private readonly CompletionController $completion,
        private readonly LocalHistory $history,
        private readonly EditorConfigResolver $editorConfigResolver,
        private readonly string $rootDir,
        private readonly bool $editorConfigEnabled,
        private readonly Translator $translator,
        private readonly \Closure $refreshGitMarks,
        private readonly \Closure $refreshBranch,
    ) {}

    public function openFile(string $path, bool $focusEditor = false): void
    {
        $path = realpath($path) ?: $path;

        if ($this->buffers->has($path)) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive($path);
            $this->showBuffer($active);
            if ($focusEditor) {
                $this->tui->setFocus($this->editor);
            }

            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->statusBar->flash($this->translator->trans('Cannot read %file%', ['%file%' => $this->relativePath($path)]));

            return;
        }
        if ((int) filesize($path) > (2 * 1024 * 1024)) {
            $this->statusBar->flash($this->translator->trans('File too large (> 2 MB)'));

            return;
        }

        $content = (string) file_get_contents($path);
        if ('' !== $content && !mb_check_encoding($content, 'UTF-8')) {
            $this->statusBar->flash($this->translator->trans('Not a UTF-8 text file'));

            return;
        }

        $buffer = $this->buffers->open($path, $content);
        $buffer->stampFromDisk();
        $this->applyEditorConfig($buffer);

        // didOpen before showBuffer so the inlay-hint request it fires is
        // ordered after the server knows about the file; this is also what
        // lazily starts the file's language server.
        $this->lsp->notifyDidOpen(
            $path,
            $buffer->document->getText(),
            ProjectPaths::languageId($path),
            $buffer->document->getVersion(),
        );

        $this->showBuffer($buffer);
        ($this->refreshGitMarks)($buffer);

        if ($focusEditor) {
            $this->tui->setFocus($this->editor);
        }
        $this->tui->requestRender();
    }

    /**
     * Resolve the file's `.editorconfig` and apply it: indentation to the live
     * document, and the whole config stashed on the buffer for the save-time
     * transforms in {@see saveBuffer()}. A no-op when the setting is off. Flashes
     * once when the file requests a charset the editor cannot honour (UTF-8 only).
     */
    private function applyEditorConfig(Buffer $buffer): void
    {
        if (!$this->editorConfigEnabled) {
            return;
        }
        $config = $this->editorConfigResolver->resolve($buffer->path);
        $buffer->document->applyEditorConfig($config);
        $buffer->editorConfig = $config;
        if (null !== $config->charset && !\in_array($config->charset, ['utf-8', 'utf-8-bom'], true)) {
            $this->statusBar->flash($this->translator->trans('.editorconfig charset "%charset%" ignored (UTF-8 only)', ['%charset%' => $config->charset]), 5.0);
        }
    }

    public function showBuffer(Buffer $buffer): void
    {
        $this->editor->showDocument($buffer->path, $buffer->document, $buffer->diagnostics, $buffer->highlighter, $buffer->editorConfig);
        $this->editor->setGitMarks($buffer->gitMarks);
        $this->lsp->whenReady(fn () => $this->completion->requestInlayHints());
        $this->tui->requestRender();
    }

    /** Close a specific tab (activating it first, mirroring a close-button click). */
    public function closeTab(string $path): void
    {
        $this->buffers->setActive($path);
        $this->tui->setFocus($this->editor);
        $this->closeActiveBuffer();
        $this->tui->requestRender();
    }

    /**
     * Close every open buffer except $keep (null closes them all), leaving any
     * buffer with unsaved changes open and flashing how many were kept, so a bulk
     * close never silently discards edits.
     */
    public function closeTabsExcept(?string $keep): void
    {
        $dirtyKept = 0;
        foreach ($this->buffers->all() as $buffer) {
            if ($buffer->path === $keep) {
                continue;
            }
            if ($buffer->isDirty()) {
                ++$dirtyKept;
                continue;
            }
            $this->lsp->notifyDidClose($buffer->path);
            $this->buffers->close($buffer->path);
        }

        if (null !== $keep && null !== ($kept = $this->buffers->setActive($keep))) {
            $this->showBuffer($kept);
        } elseif (null !== ($active = $this->buffers->active())) {
            $this->showBuffer($active);
        } else {
            $this->editor->showDocument('', new Document(''));
            $this->tui->setFocus($this->tree);
        }

        if ($dirtyKept > 0) {
            $this->statusBar->flash(1 === $dirtyKept
                ? $this->translator->trans('%count% unsaved tab kept open', ['%count%' => $dirtyKept])
                : $this->translator->trans('%count% unsaved tabs kept open', ['%count%' => $dirtyKept]));
        }
        $this->tui->requestRender();
    }

    public function closeActiveBuffer(bool $force = false): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        if (!$force && $active->isDirty()) {
            $name = basename($active->path);
            $this->editor->openListOverlay(
                "Unsaved changes in {$name}",
                [
                    ['label' => 'Save & Close', 'hint' => '', 'value' => 'save'],
                    ['label' => 'Discard & Close', 'hint' => '', 'value' => 'discard'],
                    ['label' => 'Cancel', 'hint' => '', 'value' => 'cancel'],
                ],
                function (string $choice) use ($active): void {
                    if ('save' === $choice && $this->saveBuffer($active)) {
                        $this->closeActiveBuffer();
                    } elseif ('discard' === $choice) {
                        $this->closeActiveBuffer(force: true);
                    }
                },
            );
            $this->tui->requestRender();

            return;
        }

        $this->lsp->notifyDidClose($active->path);

        $next = $this->buffers->close($active->path);
        if (null !== $next) {
            $this->showBuffer($next);
        } else {
            $this->editor->showDocument('', new Document(''));
            $this->tui->setFocus($this->tree);
            $this->tui->requestRender();
        }
    }

    public function saveCurrentFile(): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        if ($this->saveBuffer($active)) {
            $this->statusBar->flash($this->translator->trans('Saved %file%', ['%file%' => $this->relativePath($active->path)]));
        }
        $this->tui->requestRender();
    }

    public function saveAllBuffers(): void
    {
        $saved = 0;
        foreach ($this->buffers->all() as $buffer) {
            if ($buffer->isDirty() && $this->saveBuffer($buffer)) {
                ++$saved;
            }
        }
        $this->statusBar->flash(match (true) {
            0 === $saved => $this->translator->trans('Nothing to save'),
            1 === $saved => $this->translator->trans('Saved %count% file', ['%count%' => $saved]),
            default => $this->translator->trans('Saved %count% files', ['%count%' => $saved]),
        });
        $this->tui->requestRender();
    }

    public function saveBuffer(Buffer $buffer): bool
    {
        $bytes = $this->editorConfigEnabled && null !== $buffer->editorConfig
            ? DocumentWriter::render($buffer->document, $buffer->editorConfig)
            : $buffer->document->getText();
        try {
            new Filesystem()->dumpFile($buffer->path, $bytes);
        } catch (IOExceptionInterface) {
            $this->statusBar->flash($this->translator->trans('Save failed: %file%', ['%file%' => $this->relativePath($buffer->path)]));
            $this->tui->requestRender();

            return false;
        }
        $buffer->document->markSaved();
        $buffer->stampFromDisk(); // our own save must not look external
        $this->history->snapshot($buffer->path, $bytes);
        ($this->refreshGitMarks)($buffer);
        ($this->refreshBranch)();

        $this->lsp->notifyDidSave($buffer->path);

        return true;
    }

    /**
     * Reconcile the open buffers and the file tree with the on-disk state.
     * Runs on the coordinator's poll timer; also invoked directly by tests.
     */
    public function pollFilesystem(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->pollBuffer($buffer);
        }
        if ($this->tree->refreshIfChanged()) {
            $this->tui->requestRender();
        }
    }

    /** Detect and act on an external change to a single buffer's file. */
    private function pollBuffer(Buffer $buffer): void
    {
        $stamp = $buffer->diskStampNow();
        if (null === $stamp) {
            if (null !== $buffer->diskStamp) {
                $buffer->diskStamp = null; // flash once; keep the buffer so it can be re-saved
                $this->statusBar->flash($this->translator->trans('%file% deleted on disk', ['%file%' => $this->relativePath($buffer->path)]));
                $this->tui->requestRender();
            }

            return;
        }
        if ($stamp === $buffer->diskStamp) {
            return;
        }

        // The file changed on disk. Record the new stamp either way so we react once.
        $content = $this->readTextFile($buffer->path);
        $buffer->diskStamp = $stamp;
        if (null === $content) {
            return; // unreadable / too large / binary now — leave the buffer untouched
        }
        if ($buffer->isDirty()) {
            $this->statusBar->flash($this->translator->trans('%file% changed on disk', ['%file%' => $this->relativePath($buffer->path)]));
            $this->tui->requestRender();

            return;
        }
        $this->reloadBuffer($buffer, $content);
    }

    /** Replace a clean buffer's content with the new on-disk text, keeping it clean. */
    private function reloadBuffer(Buffer $buffer, string $content): void
    {
        $buffer->document->replaceAll($content);
        $buffer->document->markSaved();
        $this->lsp->notifyFullChange($buffer->path, $buffer->document); // no-op when no server is running
        ($this->refreshGitMarks)($buffer);
        $this->statusBar->flash($this->translator->trans('Reloaded %file%', ['%file%' => $this->relativePath($buffer->path)]));
        $this->tui->requestRender();
    }

    /** Read a small UTF-8 text file, or null if missing, unreadable, too large or binary. */
    private function readTextFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path) || (int) filesize($path) > (2 * 1024 * 1024)) {
            return null;
        }
        $content = (string) file_get_contents($path);

        return '' === $content || mb_check_encoding($content, 'UTF-8') ? $content : null;
    }

    /**
     * Deliver published LSP diagnostics to the buffer whose file they are
     * about (matched by URI), updating the editor when it is the active one.
     *
     * @param array<int, list<array{severity: int, message: string, col: int, endCol: int}>> $byLine
     */
    public function applyDiagnostics(string $uri, array $byLine): void
    {
        $target = null;
        foreach ($this->buffers->all() as $buffer) {
            if ($uri === Uri::fromPath($buffer->path)) {
                $target = $buffer;
                break;
            }
        }
        if (null === $target) {
            return;
        }

        $target->diagnostics = $byLine;
        if ($target === $this->buffers->active()) {
            $this->editor->setDiagnostics($byLine);
        }
        $this->tui->requestRender();
    }

    private function relativePath(string $path): string
    {
        return ProjectPaths::relative($this->rootDir, $path);
    }
}
