<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Config\EditorConfig\DocumentWriter;
use Porta\Config\EditorConfig\EditorConfigResolver;
use Porta\Container\AsIdeService;
use Porta\Container\LayoutSlot;
use Porta\Event\BufferOpened;
use Porta\Event\BufferRepointRequested;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSaveRequested;
use Porta\Event\BufferSwitched;
use Porta\Event\DiagnosticsChanged;
use Porta\Event\TreeFileActivated;
use Porta\I18n\Translator;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\FormatOnSave;
use Porta\Lsp\LspSessionPool;
use Porta\Lsp\Uri;
use Porta\Support\Clocks;
use Porta\Support\DiagnosticLog;
use Porta\Support\Files;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\TextPreviewWidget;
use Porta\Workbench\Notifier;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The open-buffer lifecycle, extracted from the coordinator: opening files
 * into buffers (with .editorconfig and LSP didOpen), switching the editor
 * between buffers, closing tabs (with the unsaved-changes dialog), saving
 * (with .editorconfig save-time transforms and local-history snapshots),
 * reconciling buffers with external on-disk changes, and delivering LSP
 * diagnostics to their buffer.
 *
 * Cross-domain reactions (git marks, debug decorations, LSP save/inlay,
 * plugin hooks) ride the dispatched BufferOpened/BufferSwitched/BufferSaved
 * events; only intra-flow calls (didOpen ordering, external reloads) stay inline.
 */
#[AsIdeService]
final class BufferService implements BufferReopener, ChangeMarkSink, DiagnosticsSink, FileOpener
{
    /**
     * The file name that titles a graph tab "Project Architecture".
     *
     * Core's own constant, deliberately not the graph-viewer plugin's: titling
     * the tab is core's policy, and core must not know which plugin — if any —
     * wrote the file. The plugin owns the writer and pins that the two agree
     * (its `ArchitectureArtifact` is what produces this name), which is the
     * right direction for that dependency.
     */
    private const string ARCHITECTURE_FILENAME = 'architecture.dot';

    public function __construct(
        private readonly BufferManager $buffers,
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly Notifier $notifier,
        private readonly Tui $tui,
        private readonly LspSessionPool $lsp,
        private readonly EditorConfigResolver $editorConfigResolver,
        private readonly ProjectPaths $paths,
        private readonly Translator $translator,
        private readonly FilesystemReconciler $reconciler,
        private readonly HelpViewerWidget $helpViewer,
        #[LayoutSlot('editor_body')]
        private readonly ContainerWidget $editorBody,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly FormatOnSave $formatOnSave,
        private readonly ViewerProviderSource $viewers,
        private readonly EditorPanes $panes,
        private readonly Filesystem $fs = new Filesystem(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * When each path last refused a save because the file had changed
     * underneath it — the confirming second press, as Ctrl+Q does for unsaved
     * buffers.
     *
     * @var array<string, float>
     */
    private array $lastOverwritePress = [];

    /**
     * The project tree's Enter/click. Focus moves to the editor, which is what
     * "I picked this file" means from a list you were navigating.
     */
    #[AsEventListener]
    public function onTreeFileActivated(TreeFileActivated $event): void
    {
        $this->openFile($event->path, focus: OpenFocus::Editor);
    }

    /**
     * A refactoring renamed a file that was open. Close the stale buffer and
     * open the new path, keeping focus where it was — a rename buried inside a
     * refactoring must not yank the user to another file. A buffer left on the
     * old path would recreate the file on its next save, undoing half the
     * refactoring.
     *
     * A DIRECTORY moves every open buffer inside it, for that same reason and
     * not because a server has been seen doing it: `dumpFile()` creates the
     * directories it needs, so one stale tab writes the old folder back into
     * existence. The tree's own rename was fixed the same way, and the two
     * paths have to leave the same state behind — a `RenameFile` that Porta
     * honours differently from the tree is a refactoring that half applies.
     */
    #[AsEventListener]
    public function onBufferRepointRequested(BufferRepointRequested $event): void
    {
        // realpath() the DIRECTORY, not the file: by the time this runs the
        // rename has already happened, so the old path no longer resolves —
        // and buffers were keyed by the canonical path when they opened.
        $directory = \realpath(\dirname($event->from));
        $from = false === $directory ? $event->from : $directory.\DIRECTORY_SEPARATOR.\basename($event->from);
        // Collected before anything is closed: closing re-enters this class,
        // and a walk over a list being mutated underneath it is its own bug.
        $moved = [];
        foreach ($this->buffers->all() as $buffer) {
            $target = ProjectPaths::moved($buffer->path, $from, $event->to);
            if (null !== $target) {
                $moved[$buffer->path] = $target;
            }
        }
        foreach ($moved as $path => $target) {
            // Carry the UNSAVED text across. `BufferManager::close()` has no
            // dirty check, so a rename refactoring over an edited file used to
            // drop the edits and re-read the moved file from disk — silently,
            // and with no autosave anywhere to have caught them.
            //
            // The tree's rename solves the same problem by SAVING first
            // (TreeOperations::saveDirtyUnder), which it can because it runs
            // before the move. Here the rename has already happened by the time
            // this event is dispatched: the old path is gone, and `saveBuffer()`
            // writes to the buffer's own path, so saving now would recreate the
            // file the refactoring just moved. Carrying the text instead leaves
            // the user exactly where they were — same edits, unsaved, at the new
            // path — and `replaceAll()` is one undoable edit, so Ctrl+Z still
            // reaches what is on disk.
            $unsaved = $this->buffers->get($path)?->document;
            $carried = null !== $unsaved && $unsaved->isDirty() ? $unsaved->getText() : null;
            $this->buffers->close($path);
            $this->openFile($target);
            if (null !== $carried) {
                $reopened = $this->buffers->get($target) ?? $this->buffers->get(($real = \realpath($target)) ? $real : $target);
                $reopened?->document->replaceAll($carried);
            }
        }
    }

    #[\Override]
    public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep, OpenAs $as = OpenAs::Viewer): void
    {
        $path = ($resolved = \realpath($path)) ? $resolved : $path;

        // "Open as Text" on a tab that is already a viewer: drop it first, so
        // the path key is free for a text buffer. A viewer tab holds an empty
        // document, so there is nothing to lose and nothing to confirm.
        if (OpenAs::Text === $as && true === $this->buffers->get($path)?->isViewer()) {
            $this->buffers->close($path);
        }
        if ($this->buffers->has($path)) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive($path);
            $this->refreshStaleViewerPayload($active);
            $this->showBuffer($active);
            if (OpenFocus::Editor === $focus) {
                $this->focusEditorArea();
            }

            return;
        }

        if (!\is_file($path) || !\is_readable($path)) {
            $this->notifier->error($this->translator->trans('Cannot read %file%', ['%file%' => $this->paths->relative($path)]));

            return;
        }
        // The registered viewer routes decide which pane a file opens in (the
        // bundled image-viewer/graph-viewer plugins claim their extensions —
        // disabling one routes its files here to the text path instead; an id
        // no built-in pane answers to falls through the same way).
        $viewer = OpenAs::Text === $as ? null : $this->viewers->viewerFor($path);
        if (null !== $viewer && null !== $this->panes->paneFor($viewer)) {
            $this->openInPane($path, $viewer, $focus);

            return;
        }
        if ((int) \filesize($path) > Files::MAX_TEXT_BYTES) {
            // Not a refusal any more. This used to flash "File too large" and
            // stop, so a grown composer.lock, a log or a bundled asset was
            // simply unopenable and you left the IDE to look at it. It opens
            // read-only instead — the pane holds a bounded prefix and says so.
            // Ahead of the OpenAs::Text bypass on purpose: "open as text" on a
            // file this size has nowhere else to go.
            $this->openInPane($path, TextPreviewWidget::PANE_ID, $focus);

            return;
        }

        $content = (string) \file_get_contents($path);
        if ('' !== $content && !\mb_check_encoding($content, encoding: 'UTF-8')) {
            $this->notifier->warn($this->translator->trans('Not a UTF-8 text file'));

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
            $buffer->document->version,
        );

        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));

        if (OpenFocus::Editor === $focus) {
            $this->focusEditorArea();
        }
        $this->tui->requestRender();
    }

    /**
     * Re-opening an already-open viewer tab whose file changed on disk shows
     * the CURRENT content, not the stale payload — an explicit open must not
     * wait out the 1s reconciler (the regenerated architecture artifact is the
     * motivating case: the plugin writes it, then opens it). Silent on
     * purpose, unlike the reconciler's "Reloaded" flash — the user asked for
     * this file just now.
     */
    private function refreshStaleViewerPayload(Buffer $buffer): void
    {
        if (!$buffer->isViewer() || $buffer->diskStamp === $buffer->diskStampNow()) {
            return;
        }
        $load = $this->panes->loaderFor((string) $buffer->paneId);
        if (null === $load) {
            return;
        }

        try {
            /** @var mixed $payload */
            $payload = $load($buffer->path);
        } catch (\Throwable) {
            return; // unreadable or refused now: keep the last good payload
        }
        $buffer->panePayload = $payload;
        $buffer->stampFromDisk();
    }

    /**
     * Open $path into the pane that claimed it: its loader reads the payload,
     * the buffer carries it, and the pane shows it. No editorconfig, no LSP
     * didOpen, no git marks — a pane tab holds an empty document.
     *
     * A loader throws a RuntimeException to refuse the file (too large, not
     * decodable, not UTF-8) and its message is flashed as-is — that is the
     * pane's last word on a file its claim already accepted. Anything else a
     * loader throws is a BUG, not a refusal: a third-party TypeError must not
     * paint its internals into the status bar, so it flashes generic words
     * and leaves its trace in error.log instead.
     */
    private function openInPane(string $path, string $paneId, OpenFocus $focus): void
    {
        try {
            /** @var mixed $payload */
            $payload = $this->panes->load($paneId, $path);
        } catch (\RuntimeException $refusal) {
            $this->notifier->warn($refusal->getMessage());

            return;
        } catch (\Throwable $bug) {
            DiagnosticLog::forRoot($this->paths->root(), 'error')->append(\sprintf("%s: %s\n%s", $bug::class, $bug->getMessage(), $bug->getTraceAsString()));
            $this->notifier->error($this->translator->trans('Internal error — details in .porta/error.log'));

            return;
        }
        $buffer = $this->buffers->openPane($path, $paneId, $payload);
        // The artifact's directory is a plugin setting, so the FILE NAME is all
        // core can key the title on (and a hand-written architecture.dot is
        // titled the same, which is the honest reading of the name).
        if (self::ARCHITECTURE_FILENAME === \basename($path)) {
            $buffer->title = 'Project Architecture';
        }
        $buffer->stampFromDisk();
        $this->showBuffer($buffer);
        $this->dispatcher->dispatch(new BufferOpened($buffer));
        if (OpenFocus::Editor === $focus) {
            $this->focusEditorArea();
        }
        $this->tui->requestRender();
    }

    /**
     * Open a documentation page into a rendered help-viewer tab: no LSP, no
     * git marks — the raw markdown rides the buffer beside an empty document.
     * A path already open as a normal text buffer just re-shows that source
     * tab (developing Porta itself can have docs open both ways).
     */
    public function openHelp(string $path): void
    {
        $path = ($resolved = \realpath($path)) ? $resolved : $path;

        if ($this->buffers->has($path)) {
            /** @var Buffer $active */
            $active = $this->buffers->setActive($path);
            $this->showBuffer($active);
            $this->focusEditorArea();
            $this->tui->requestRender();

            return;
        }

        if (!\is_file($path) || !\is_readable($path)) {
            $this->notifier->error($this->translator->trans('Cannot read %file%', ['%file%' => $this->paths->relative($path)]));

            return;
        }

        // Through the pane's own loader, like every other pane: a docs file that
        // is not text is refused rather than rendered as mojibake.
        $this->openInPane($path, 'help', OpenFocus::Editor);
    }

    /**
     * Navigate the open help tab to another doc page in place: no new buffer,
     * no tab switch — the viewer widget owns the page and its back/forward
     * history, and the single help tab just keeps showing the widget.
     */
    public function showHelpPage(string $path): void
    {
        $path = ($resolved = \realpath($path)) ? $resolved : $path;
        if (!\is_file($path) || !\is_readable($path)) {
            $this->notifier->error($this->translator->trans('Cannot read %file%', ['%file%' => $this->paths->relative($path)]));

            return;
        }
        $this->helpViewer->refreshPage($path, (string) \file_get_contents($path));
        $this->tui->requestRender();
    }

    /** Focus the editor-pane content: the active tab's viewer, else the editor. */
    public function focusEditorArea(): void
    {
        $active = $this->buffers->active();
        $this->tui->setFocus($this->panes->paneFor((string) $active?->paneId) ?? $this->editor);
    }

    /**
     * Resolve the file's `.editorconfig` and apply it: indentation to the live
     * document, and the whole config stashed on the buffer for the save-time
     * transforms in {@see saveBuffer()}. Always on; the per-language indent
     * settings supersede it per field when not 'auto'. Flashes once when the
     * file requests a charset the editor cannot honour (UTF-8 only).
     */
    private function applyEditorConfig(Buffer $buffer): void
    {
        $config = $this->editorConfigResolver->resolve($buffer->path);
        $buffer->document->applyEditorConfig($config);
        $buffer->editorConfig = $config;
        if (null !== $config->charset && !\in_array($config->charset, ['utf-8', 'utf-8-bom'], strict: true)) {
            $this->notifier->warn($this->translator->trans('.editorconfig charset "%charset%" ignored (UTF-8 only)', ['%charset%' => $config->charset]), 5.0);
        }
    }

    public function showBuffer(Buffer $buffer): void
    {
        $pane = $this->panes->paneFor((string) $buffer->paneId);
        if (null !== $pane) {
            // Neutralize the editor so any jumpTo() fired after openFile()
            // mutates a throwaway document, not the previous buffer's cursor.
            $this->editor->showDocument('', new Document(''));
            $pane->show($buffer->path, $buffer->panePayload);
            $this->swapEditorPaneTo($pane);
            $this->dispatcher->dispatch(new BufferSwitched($buffer));
            $this->tui->requestRender();

            return;
        }
        $this->swapEditorPaneTo($this->editor);
        $this->editor->showDocument($buffer->path, $buffer->document, $buffer->diagnostics, $buffer->highlighter, $buffer->editorConfig);
        $this->editor->decorations->setVcsMarks($buffer->vcsMarks, $buffer->document->getLineCount());
        $this->editor->decorations->setCommentMarks($buffer->commentMarks());
        $this->dispatcher->dispatch(new BufferSwitched($buffer));
        $this->tui->requestRender();
    }

    /**
     * Make $widget the editor body's content slot (breadcrumbs → content; the
     * tab strip sits around the body, wherever the tabBarPosition setting put it).
     * Detaching the focused widget makes the focus manager fall back to an
     * arbitrary focusable, so focus is handed to the incoming widget instead.
     */
    private function swapEditorPaneTo(AbstractWidget $widget): void
    {
        if (\in_array($widget, $this->editorBody->all(), strict: true)) {
            return;
        }
        $focused = $this->tui->getFocus();
        $hadFocus = false;
        foreach ($this->panes->all() as $pane) {
            if ($pane === $widget) {
                continue;
            }
            $hadFocus = $hadFocus || $focused === $pane;
            $this->editorBody->remove($pane);
            // Losing the slot is not the same as closing: a pane frees what only
            // the VISIBLE pane needs (the image pane's decoded pixels) and keeps
            // the rest — which is what the per-viewer clear() calls scattered
            // through this class used to say one viewer at a time.
            $pane->hide();
        }
        if ($widget !== $this->editor) {
            $hadFocus = $hadFocus || $focused === $this->editor;
            $this->editorBody->remove($this->editor);
        }
        $this->editorBody->add($widget);
        if ($hadFocus) {
            $this->tui->setFocus($widget);
        }
    }

    /** The empty state after the last tab closes: a blank editor, tree focused. */
    private function showEmptyEditor(): void
    {
        $this->swapEditorPaneTo($this->editor);
        foreach ($this->panes->all() as $pane) {
            $pane->clear();
        }
        $this->editor->showDocument('', new Document(''));
        $this->tui->setFocus($this->tree);
    }

    /** Close a specific tab (activating it first, mirroring a close-button click). */
    public function closeTab(string $path): void
    {
        $active = $this->buffers->setActive($path);
        if (null !== $active) {
            $this->showBuffer($active); // the unsaved-changes dialog needs its pane attached
        }
        $this->focusEditorArea();
        $this->closeActiveBuffer();
        $this->tui->requestRender();
    }

    /**
     * Close every open buffer except $keep (null closes them all), leaving any
     * buffer with unsaved changes open and flashing how many were kept, so a bulk
     * close never silently discards edits.
     */
    /** Whether the tab for $path is pinned; false when nothing is open there. */
    public function isPinned(string $path): bool
    {
        return true === $this->buffers->get($path)?->pinned;
    }

    /**
     * Pin or unpin a tab. Pinned tabs survive every bulk close and carry the
     * pin glyph in the strip; the state is saved with the session, because a
     * tab you deliberately kept should still be there tomorrow.
     */
    public function togglePinned(string $path): void
    {
        $buffer = $this->buffers->get($path);
        if (null === $buffer) {
            return;
        }

        $buffer->pinned = !$buffer->pinned;
        // The tab STRIP shows the pin, not the editor, so a frame is all this
        // needs — the strip re-renders because its label changed.
        $this->tui->requestRender();
    }

    public function closeTabsExcept(?string $keep): void
    {
        $this->closeTabs(static fn (Buffer $buffer): bool => $buffer->path !== $keep, $keep);
    }

    /**
     * Close the tabs on one side of $path in TAB order, leaving $path itself
     * open — PhpStorm's Close to the Left / Close to the Right.
     *
     * The side is the strip's order, not the order you visited them in, which
     * is the whole point of the verb: it clears the run of tabs you can see
     * beside this one.
     */
    public function closeTabsToTheSide(string $path, TabSide $side): void
    {
        $paths = \array_map(static fn (Buffer $buffer): string => $buffer->path, $this->buffers->all());
        $at = \array_search($path, $paths, strict: true);
        if (false === $at) {
            return;
        }

        $doomed = \array_flip(
            TabSide::Right === $side
                ? \array_slice($paths, $at + 1)
                : \array_slice($paths, offset: 0, length: $at),
        );

        $this->closeTabs(static fn (Buffer $buffer): bool => ($doomed[$buffer->path] ?? null) !== null, $path);
    }

    /**
     * The shared bulk close: every buffer $shouldClose picks, except that a
     * buffer with unsaved changes is LEFT OPEN and counted, so no bulk verb
     * ever silently discards edits.
     *
     * @param \Closure(Buffer): bool $shouldClose
     */
    private function closeTabs(\Closure $shouldClose, ?string $keep): void
    {
        $dirtyKept = 0;
        foreach ($this->buffers->all() as $buffer) {
            // A pinned tab is not a candidate at all — that is what pinning
            // means, and unlike the unsaved-changes case it needs no report:
            // you asked for it, and the tab is still visibly there.
            if ($buffer->pinned || !$shouldClose($buffer)) {
                continue;
            }
            if ($buffer->isDirty()) {
                ++$dirtyKept;
                continue;
            }
            if (!$buffer->isViewer()) {
                $this->lsp->notifyDidClose($buffer->path);
            }
            $this->panes->paneFor((string) $buffer->paneId)?->forget($buffer->path);
            $this->buffers->close($buffer->path);
        }

        $this->showAfterBulkClose($keep);

        if ($dirtyKept > 0) {
            $this->notifier->flash(
                $this->translator->trans('%count% unsaved tab kept open|%count% unsaved tabs kept open', ['%count%' => $dirtyKept]),
            );
        }
        $this->tui->requestRender();
    }

    /** Show the buffer that should be visible after a bulk close: $keep, else the most recent, else empty. */
    private function showAfterBulkClose(?string $keep): void
    {
        if (null !== $keep && null !== ($kept = $this->buffers->setActive($keep))) {
            $this->showBuffer($kept);

            return;
        }
        $active = $this->buffers->active();
        if (null !== $active) {
            $this->showBuffer($active);

            return;
        }
        $this->showEmptyEditor();
    }

    public function closeActiveBuffer(CloseMode $mode = CloseMode::Guarded): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        if (CloseMode::Guarded === $mode && $active->isDirty()) {
            $name = \basename($active->path);
            $this->editor->openListOverlay(
                $this->translator->trans('Unsaved changes in %name%', ['%name%' => $name]),
                [
                    ['label' => $this->translator->trans('Save & Close'), 'hint' => '', 'value' => 'save'],
                    ['label' => $this->translator->trans('Discard & Close'), 'hint' => '', 'value' => 'discard'],
                    ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                ],
                function (string $choice) use ($active): void {
                    if ('save' === $choice && $this->saveBuffer($active)) {
                        $this->closeActiveBuffer();

                        return;
                    }
                    if ('discard' === $choice) {
                        $this->closeActiveBuffer(CloseMode::Force);
                    }
                },
            );
            $this->tui->requestRender();

            return;
        }

        if (!$active->isViewer()) {
            $this->lsp->notifyDidClose($active->path);
        }

        $next = $this->buffers->close($active->path);
        // The tab is gone for good, so per-path state goes with it: the graph
        // pane's remembered zoom, the help pane's navigation history. A pane
        // that keeps nothing per path inherits a no-op.
        $this->panes->paneFor((string) $active->paneId)?->forget($active->path);
        if (null !== $next) {
            $this->showBuffer($next);

            return;
        }
        $this->showEmptyEditor();
        $this->tui->requestRender();
    }

    public function saveCurrentFile(): void
    {
        $active = $this->buffers->active();
        if (null === $active) {
            return;
        }
        // Format-on-save only covers the single-file save: saveAllBuffers()
        // and Save & Close keep the synchronous bool contract of saveBuffer().
        if ($this->formatOnSave->isEnabled() && !$active->isViewer()) {
            $this->formatOnSave->formatThenSave($active, function () use ($active): void {
                if ($this->saveBuffer($active)) {
                    $this->notifier->success($this->translator->trans('Saved %file%', ['%file%' => $this->paths->relative($active->path)]));
                }
            });

            return;
        }
        if ($this->saveBuffer($active)) {
            $this->notifier->success($this->translator->trans('Saved %file%', ['%file%' => $this->paths->relative($active->path)]));
        }

        // No trailing requestRender: every outcome that changes the screen
        // already rendered through the notifier (success flash, IO-error
        // toast), and the silent viewer no-op changes nothing worth a frame.
    }

    public function saveAllBuffers(): void
    {
        $dirty = 0;
        $saved = 0;
        foreach ($this->buffers->all() as $buffer) {
            if (!$buffer->isDirty()) {
                continue;
            }
            ++$dirty;
            if ($this->saveBuffer($buffer)) {
                ++$saved;
            }
        }
        if (0 === $dirty) {
            $this->notifier->flash($this->translator->trans('Nothing to save'));

            return;
        }
        if ($saved > 0) {
            $this->notifier->flash($this->translator->trans('Saved %count% file|Saved %count% files', ['%count%' => $saved]));
        }

        // $saved === 0 here means every dirty buffer already flashed its own
        // "Save failed: %file%" error via saveBuffer() — nothing to add.
    }

    /**
     * Whether this save may overwrite a file someone else wrote to.
     *
     * The reconciler flashes when a file changes under an unsaved buffer, and
     * that flash is gone in seconds — so a save a minute later discarded the
     * other writer's work silently. Reproduced end to end: a second process
     * rewrote the open file, Porta warned, Ctrl+S overwrote it, and the
     * colleague's line was simply gone from disk.
     *
     * The first Ctrl+S now refuses and says so; a second within three seconds
     * goes through. That is the shape Ctrl+Q already uses for unsaved buffers,
     * rather than a modal nobody else in this app has.
     */
    private function overwriteConfirmed(Buffer $buffer): bool
    {
        if (!$buffer->diskChangedUnderEdit) {
            return true;
        }
        $now = Clocks::seconds($this->clock);
        if (($now - ($this->lastOverwritePress[$buffer->path] ?? 0.0)) <= 3.0) {
            return true;
        }
        $this->lastOverwritePress[$buffer->path] = $now;
        $this->notifier->flash(
            $this->translator->trans(
                '%file% changed on disk — Ctrl+S again to overwrite',
                ['%file%' => $this->paths->relative($buffer->path)],
            ),
            3.0,
        );

        return false;
    }

    /** Another part of the IDE asks for a save — {@see BufferSaver}. */
    #[AsEventListener]
    public function onBufferSaveRequested(BufferSaveRequested $event): void
    {
        $buffer = $this->buffers->get($event->path);
        if (null !== $buffer) {
            $this->saveBuffer($buffer);
        }
    }

    public function saveBuffer(Buffer $buffer): bool
    {
        if ($buffer->isViewer()) {
            return false; // never write the empty backing document over the real file
        }
        $bytes = null !== $buffer->editorConfig
            ? DocumentWriter::render($buffer->document, $buffer->editorConfig)
            : $buffer->document->getText();
        // The document strips a leading BOM on load so it is not a column the
        // width arithmetic counts and the terminal does not; putting it back
        // here is what keeps a file that arrived with one from quietly losing
        // it. Outside this line nothing else should ever see it.
        if ($buffer->document->hasByteOrderMark) {
            $bytes = Document::BOM.$bytes;
        }
        if (!$this->overwriteConfirmed($buffer)) {
            return false;
        }
        try {
            $this->fs->dumpFile($buffer->path, $bytes);
        } catch (IOExceptionInterface) {
            $this->notifier->error($this->translator->trans('Save failed: %file%', ['%file%' => $this->paths->relative($buffer->path)]));

            return false;
        }
        $buffer->document->markSaved();
        $buffer->stampFromDisk(); // our own save must not look external
        $buffer->diskChangedUnderEdit = false;
        unset($this->lastOverwritePress[$buffer->path]);
        $this->dispatcher->dispatch(new BufferSaved($buffer));

        return true;
    }

    /**
     * Reconcile the open buffers and the file tree with the on-disk state.
     * Runs on the coordinator's poll timer; also invoked directly by tests.
     * Delegated to {@see FilesystemReconciler}, which owns this lifecycle.
     */
    public function pollFilesystem(): void
    {
        $this->reconciler->pollFilesystem();
    }

    /**
     * The poll timer's amortized entry point: one bounded reconciliation slice
     * per call. Tests and explicit refreshes keep the synchronous full pass
     * above; the live timer takes this one so the loop never stalls on it.
     */
    public function pollFilesystemSlice(): void
    {
        $this->reconciler->pollSlice();
    }

    /**
     * Activate the most recently focused text buffer (for the editor-hosted
     * modals that cannot open over an image tab). Null when only images are open.
     */
    public function activateAdjacentTextBuffer(): ?Buffer
    {
        foreach ($this->buffers->recent() as $buffer) {
            if ($buffer->isViewer()) {
                continue;
            }

            $this->buffers->setActive($buffer->path);
            $this->showBuffer($buffer);

            return $buffer;
        }

        return null;
    }

    /**
     * The Search Everywhere / go-to-file / Find-in-Files pickers live inside the
     * editor widget, which is detached while an image or help tab is active. Hop
     * to the most recent text tab first; with none open, flash and refuse.
     */
    public function ensureTextEditorActive(): bool
    {
        $active = $this->buffers->active();
        if (null === $active || !$active->isViewer()) {
            return true;
        }
        if (null !== $this->activateAdjacentTextBuffer()) {
            return true;
        }
        $this->notifier->flash($this->translator->trans('Not available in this view'));

        return false;
    }

    /** Re-send didOpen for every open file, so a live LSP reconfigure relaunches its servers. */
    #[\Override]
    public function reopenAllInLsp(): void
    {
        foreach ($this->buffers->all() as $buffer) {
            $this->lsp->notifyDidOpen($buffer->path, $buffer->document->getText(), ProjectPaths::languageId($buffer->path), $buffer->document->version);
        }
    }

    /**
     * Deliver published LSP diagnostics to the buffer whose file they are
     * about (matched by URI), updating the editor when it is the active one.
     *
     * @param array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol: int, code?: int|string, source?: string, tags?: list<int>}>> $byLine
     */
    /**
     * Store a file's change marks and, if that file is the one on screen, paint
     * them. A path with no open buffer is a silent no-op — the producer works
     * from the working tree and may answer about a file the user never opened.
     *
     * @param array<int, ChangeMarkKind> $marks 0-based line => kind
     */
    #[\Override]
    public function applyChangeMarks(string $path, array $marks): void
    {
        foreach ($this->buffers->all() as $buffer) {
            if ($path !== $buffer->path) {
                continue;
            }
            $buffer->vcsMarks = $marks;
            if ($buffer === $this->buffers->active()) {
                $this->editor->decorations->setVcsMarks($marks, $buffer->document->getLineCount());
            }
            $this->tui->requestRender();

            return;
        }
    }

    /**
     * Store an owner's comment marks for a file and, if it is on screen, paint
     * the merge. A path with no open buffer is a silent no-op, as for change
     * marks.
     *
     * @param array<int, string> $marks 0-based line => one-line summary
     */
    #[\Override]
    public function applyCommentMarks(string $path, array $marks, string $owner): void
    {
        $buffer = $this->buffers->get($path);
        if (null === $buffer) {
            return;
        }
        $buffer->publishCommentMarks($owner, $marks);
        if ($buffer === $this->buffers->active()) {
            $this->editor->decorations->setCommentMarks($buffer->commentMarks());
        }
        $this->tui->requestRender();
    }

    #[\Override]
    public function applyDiagnostics(string $uri, array $byLine, string $owner): void
    {
        $target = null;
        foreach ($this->buffers->all() as $buffer) {
            if ($uri !== Uri::fromPath($buffer->path)) {
                continue;
            }

            $target = $buffer;
            break;
        }
        if (null === $target) {
            return;
        }

        // An empty publish is this owner saying it has nothing (any more), so
        // its slice goes rather than being kept as an empty one — otherwise
        // every server and analyser that ever spoke about the file would stay
        // in the map for the buffer's lifetime.
        unset($target->diagnosticsByOwner[$owner]);
        if ([] !== $byLine) {
            $target->diagnosticsByOwner[$owner] = $byLine;
        }
        $target->diagnostics = self::mergeDiagnostics($target->diagnosticsByOwner);
        if ($target === $this->buffers->active()) {
            $this->editor->decorations->setDiagnostics($target->diagnostics);
        }
        // Announce the change so an open Problems tab refreshes live.
        $this->dispatcher->dispatch(new DiagnosticsChanged());
        $this->tui->requestRender();
    }

    /**
     * Flatten every owner's findings into the one line-keyed view the stripe,
     * the gutter and the Problems panel read.
     *
     * With SEVERAL owners they are walked in name order and the lines sorted,
     * so the same set of publishes always produces the same map whatever order
     * they arrived in — a server answering a keystroke and an analyser
     * answering a save race routinely, and a panel that reshuffled itself on
     * that race would be unreadable.
     *
     * One owner is handed back exactly as it came, which is every file in a
     * single-server project: the ordering only has to be decided where there
     * is something to decide, and touching the common path would change what
     * a project with one server has always rendered.
     *
     * @param array<string, array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>>> $byOwner
     *
     * @return array<int, list<array{severity: DiagnosticSeverity, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>>
     */
    private static function mergeDiagnostics(array $byOwner): array
    {
        if (1 === \count($byOwner)) {
            return \array_first($byOwner); // the common case, untouched
        }
        \ksort($byOwner);
        $merged = [];
        foreach ($byOwner as $byLine) {
            foreach ($byLine as $line => $diagnostics) {
                foreach ($diagnostics as $diagnostic) {
                    $merged[$line][] = $diagnostic;
                }
            }
        }
        \ksort($merged);

        return $merged;
    }
}
